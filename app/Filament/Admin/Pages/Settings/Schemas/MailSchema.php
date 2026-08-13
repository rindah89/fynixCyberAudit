<?php

namespace App\Filament\Admin\Pages\Settings\Schemas;

use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class MailSchema
{
    public static function schema(): array
    {
        return [
            TextInput::make('mail.host'),
            TextInput::make('mail.port')
                ->type('number'),
            Select::make('mail.encryption')
                ->options([
                    'TLS' => 'TLS',
                    'STARTTLS' => 'STARTTLS',
                    'none' => 'None',
                ]),
            TextInput::make('mail.username'),
            TextInput::make('mail.password')
                ->password()
                ->placeholder(fn () => filled(setting('mail.password')) ? '••••••••' : null)
                ->helperText(fn () => filled(setting('mail.password'))
                    ? 'Password is stored securely. Leave blank to keep current password.'
                    : null)
                ->dehydrateStateUsing(function ($state) {
                    // If blank, keep the existing encrypted password
                    if (! filled($state)) {
                        return setting('mail.password');
                    }

                    // Encrypt the new password
                    return Crypt::encryptString($state);
                })
                ->afterStateHydrated(function (TextInput $component, $state) {
                    // Never populate the field with the actual password
                    // This prevents the password from appearing in the Livewire payload
                    $component->state(null);
                }),
            TextInput::make('mail.from')
                ->label('From Address')
                ->email()
                ->helperText('The email address to send emails from'),
            Actions::make([
                Action::make('sendTestEmail')
                    ->label('Send Test Email')
                    ->color('primary')
                    ->action(function ($livewire) {
                        // Save current form data first
                        $livewire->save();

                        // Then test email with saved settings
                        static::sendTestEmail();
                    })
                    ->visible(fn (Get $get) => filled(setting('mail.host')) &&
                        filled(setting('mail.port')) &&
                        filled(setting('mail.encryption')) &&
                        filled(setting('mail.username')) &&
                        filled(setting('mail.password')) &&
                        filled(setting('mail.from'))
                    ),
            ]),
        ];
    }

    /**
     * Get the decrypted mail password from settings.
     */
    protected static function getDecryptedMailPassword(): ?string
    {
        $password = setting('mail.password');

        if (! filled($password)) {
            return null;
        }

        try {
            return Crypt::decryptString($password);
        } catch (Exception $e) {
            // If decryption fails, assume it's plaintext (legacy data)
            return $password;
        }
    }

    protected static function sendTestEmail(): void
    {
        $mailConfig = [
            'host' => setting('mail.host'),
            'port' => setting('mail.port'),
            'encryption' => setting('mail.encryption'),
            'username' => setting('mail.username'),
            'password' => static::getDecryptedMailPassword(),
            'from' => setting('mail.from'),
        ];

        // Store original config to restore later
        $originalConfig = [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'mail.from' => config('mail.from'),
        ];

        try {
            static::validateMailConfiguration($mailConfig);
            static::configureMailSettings($mailConfig);
            static::testSmtpConnection($mailConfig);
            static::sendEmail();

            Notification::make()
                ->title('Test email sent successfully!')
                ->body('Email sent to: '.auth()->user()->email)
                ->success()
                ->send();

        } catch (Exception $e) {
            static::handleMailError($e, $mailConfig);
        } finally {
            // Restore original configuration
            config($originalConfig);
            app()->forgetInstance('mail.manager');
        }
    }

    protected static function validateMailConfiguration(array $mailConfig): void
    {
        if (empty($mailConfig['host']) || empty($mailConfig['port']) ||
            empty($mailConfig['username']) || empty($mailConfig['password']) ||
            empty($mailConfig['from'])) {
            throw new Exception('Mail configuration is incomplete. Please ensure all fields are filled.');
        }

        // AWS SES specific validations
        if (str_contains($mailConfig['host'], 'amazonaws.com')) {
            if (! str_starts_with($mailConfig['username'], 'AKIA')) {
                throw new Exception('AWS SES username should start with "AKIA". Please use your AWS SES SMTP credentials, not your AWS console credentials.');
            }
            if ($mailConfig['port'] != 587 && $mailConfig['port'] != 465 && $mailConfig['port'] != 25) {
                throw new Exception('AWS SES typically uses ports 587 (STARTTLS), 465 (TLS), or 25. Current port: '.$mailConfig['port']);
            }
        }
    }

    protected static function configureMailSettings(array $mailConfig): void
    {
        // Set the mail configuration dynamically
        $encryption = strtolower($mailConfig['encryption']);

        // For AWS SES, ensure proper encryption handling
        if (str_contains($mailConfig['host'], 'amazonaws.com')) {
            if ($encryption === 'starttls') {
                $encryption = 'tls';  // Laravel expects 'tls' for STARTTLS
            }
        }

        $smtpConfig = [
            'transport' => 'smtp',
            'host' => $mailConfig['host'],
            'port' => (int) $mailConfig['port'],
            'encryption' => $encryption,
            'username' => $mailConfig['username'],
            'password' => $mailConfig['password'],
            'timeout' => 60,  // Increase timeout for AWS SES
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ];

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => $smtpConfig,
            'mail.from' => [
                'address' => $mailConfig['from'],
                'name' => config('app.name'),
            ],
        ]);

        // Clear any cached mail manager instance
        app()->forgetInstance('mail.manager');

        // Force refresh the mail configuration
        $mailManager = app('mail.manager');
        $mailManager->purge('smtp');

        // Add debug info for troubleshooting
        $debugInfo = "Host: {$mailConfig['host']}, Port: {$mailConfig['port']}, Raw Encryption: {$mailConfig['encryption']}, Processed Encryption: {$encryption}, Username: ".substr($mailConfig['username'], 0, 12).'...';
        Log::info('Mail test configuration: '.$debugInfo);
        Log::info('Full SMTP config: '.json_encode($smtpConfig));
    }

    protected static function testSmtpConnection(array $mailConfig): void
    {
        $encryption = strtolower($mailConfig['encryption']);

        // For AWS SES, ensure proper encryption handling
        if (str_contains($mailConfig['host'], 'amazonaws.com') && $encryption === 'starttls') {
            $encryption = 'tls';
        }

        // For AWS SES, determine correct encryption for connection test
        $useEncryption = false;
        if ($encryption === 'tls' && $mailConfig['port'] == 465) {
            $useEncryption = true; // SSL/TLS on port 465
        }
        // Port 587 uses STARTTLS (starts plain then upgrades), so useEncryption = false

        $transport = new EsmtpTransport(
            $mailConfig['host'],
            (int) $mailConfig['port'],
            $useEncryption
        );
        $transport->setUsername($mailConfig['username']);
        $transport->setPassword($mailConfig['password']);

        // This will test the connection
        $transport->start();
        Log::info('SMTP connection test successful');
        $transport->stop();
    }

    protected static function sendEmail(): void
    {
        Mail::mailer('smtp')->raw('This is a test email from Fynix Cyber Audit. If you receive this, your mail configuration is working correctly.', function (Message $message) {
            $message->to(auth()->user()->email)
                ->subject('Fynix Cyber Audit Mail Configuration Test');
        });
    }

    protected static function handleMailError(Exception $e, array $mailConfig): void
    {
        $errorMessage = $e->getMessage();

        // Add helpful hints for common AWS SES errors
        if (str_contains($errorMessage, '535 Authentication Credentials Invalid')) {
            $errorMessage .= "\n\nAWS SES troubleshooting:\n• SMTP credentials must be generated in AWS SES Console > SMTP Settings\n• Use the SMTP username/password, NOT your AWS access keys\n• Verify both sender ({$mailConfig['from']}) and recipient emails in AWS SES\n• Check if you're in AWS SES sandbox mode (limits sending to verified emails only)\n• Ensure the SMTP hostname region matches your SES region\n• AWS says this key has never been used - try generating new SMTP credentials";
        }

        Notification::make()
            ->title('Failed to send test email')
            ->body($errorMessage)
            ->danger()
            ->send();
    }
}
