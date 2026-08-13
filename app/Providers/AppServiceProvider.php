<?php

namespace App\Providers;

use App\Livewire\CustomSessionGuard;
use App\Models\User;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use BladeUI\Icons\Factory as IconFactory;
use Exception;
use App\Support\FynixPalette;
use Filament\Support\Facades\FilamentColor;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Log;
use Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Override the package's SessionGuard component with our custom one
        Livewire::component('filament-inactivity-guard::session-guard', CustomSessionGuard::class);

        // Only skip the install check if running the installer command or actual PHPUnit tests
        $isInstaller = false;
        if ($this->app->runningInConsole()) {
            $argv = $_SERVER['argv'] ?? [];
            if (isset($argv[1]) && (
                $argv[1] === 'fynix:install'
            || $argv[1] === 'fynix:deploy'
            || $argv[1] === 'package:discover'
            || $argv[1] === 'filament:upgrade'
            || $argv[1] === 'vendor:publish'
            || $argv[1] === 'test'
            || $argv[1] === 'migrate'
            || str_starts_with($argv[1], 'migrate:')
            || $argv[1] === 'config:cache'
            || $argv[1] === 'config:clear'
            || $argv[1] === 'route:cache'
            || $argv[1] === 'route:clear'
            || $argv[1] === 'view:cache'
            || $argv[1] === 'view:clear'
            || $argv[1] === 'cache:clear'
            || $argv[1] === 'key:generate'
            || $argv[1] === 'storage:link'
            || $argv[1] === 'db:seed'
            || $argv[1] === 'fynix:create-user'
            || $argv[1] === 'settings:set'
            )) {
                $isInstaller = true;
            }
        }

        // Skip settings config only when running actual PHPUnit tests (not just APP_ENV=testing)
        if ($this->app->runningUnitTests()) {
            $isInstaller = true;
        }

        if (! $isInstaller) {
            if (Schema::hasTable('settings')) {

                Config::set('app.name', setting('general.name', 'Fynix Cyber Audit'));
                Config::set('app.url', setting('general.url', 'https://fynixcyberaudit.test'));

                // Only override the .env mail config once SMTP settings have been
                // saved in-app; a fresh install must not boot the mailer with a
                // null host. Avoid the top-level "driver" key — it switches
                // MailManager to the legacy whole-array config path.
                $mailHost = setting('mail.host');
                if (! empty($mailHost)) {
                    // Decrypt mail password if it's encrypted
                    $mailPassword = setting('mail.password');
                    if (! empty($mailPassword)) {
                        try {
                            $mailPassword = Crypt::decryptString($mailPassword);
                        } catch (Exception $e) {
                            // If decryption fails, assume it's plaintext (legacy data)
                        }
                    }

                    config([
                        'mail.default' => 'smtp',
                        'mail.mailers.smtp' => array_merge(config('mail.mailers.smtp', []), [
                            'transport' => 'smtp',
                            'host' => $mailHost,
                            'port' => setting('mail.port', config('mail.mailers.smtp.port')),
                            'encryption' => setting('mail.encryption'),
                            'username' => setting('mail.username'),
                            'password' => $mailPassword,
                        ]),
                        'mail.from' => [
                            'address' => setting('mail.from', config('mail.from.address')),
                            'name' => setting('general.name'),
                        ],
                    ]);
                }

                // Configure filesystem based on settings
                $storageDriver = setting('storage.driver', 'private');

                // Ensure local disk is always configured
                config()->set('filesystems.disks.local', array_merge(config('filesystems.disks.local', []), [
                    'driver' => 'local',
                    'root' => storage_path('app'),
                    'throw' => false,
                ]));

                // Configure S3-compatible storage (AWS S3 or DigitalOcean Spaces)
                if (in_array($storageDriver, ['s3', 'digitalocean'])) {
                    $settingKey = "storage.{$storageDriver}";
                    $accessKey = setting("{$settingKey}.key");
                    $secretKey = setting("{$settingKey}.secret");
                    $region = setting("{$settingKey}.region", $storageDriver === 's3' ? 'us-east-1' : 'nyc3');
                    $bucket = setting("{$settingKey}.bucket");

                    try {
                        // Decrypt credentials if they exist and are encrypted
                        if (! empty($accessKey)) {
                            $accessKey = Crypt::decryptString($accessKey);
                        }
                        if (! empty($secretKey)) {
                            $secretKey = Crypt::decryptString($secretKey);
                        }

                        $diskConfig = [
                            'driver' => 's3',
                            'key' => $accessKey,
                            'secret' => $secretKey,
                            'bucket' => $bucket,
                        ];

                        if ($storageDriver === 'digitalocean') {
                            // DigitalOcean Spaces uses path-style endpoint
                            $diskConfig['region'] = 'us-east-1'; // Always us-east-1 for AWS SDK compatibility
                            $diskConfig['endpoint'] = 'https://'.strtolower($region).'.digitaloceanspaces.com';
                            $diskConfig['use_path_style_endpoint'] = true;
                        } else {
                            // AWS S3
                            $diskConfig['region'] = $region;
                            $diskConfig['use_path_style_endpoint'] = false;
                        }

                        config()->set("filesystems.disks.{$storageDriver}", array_merge(
                            config("filesystems.disks.{$storageDriver}", []),
                            $diskConfig
                        ));
                    } catch (Exception $e) {
                        Log::error("Failed to decrypt {$storageDriver} credentials: ".$e->getMessage());
                        $storageDriver = 'private';
                    }
                }

                // Set the default filesystem driver
                config()->set('filesystems.default', $storageDriver);

                // Set session lifetime from settings
                Config::set('session.lifetime', setting('security.session_timeout', 15));
            } else {
                // if table "settings" does not exist
                // Error that app was not installed properly
                abort(500, 'Fynix Cyber Audit was not installed properly. Please review the
                installation guide to install the app.');
            }
        }

        Gate::before(function ($user, string $ability) {
            // Only apply super admin bypass for regular User model, not VendorUser
            if ($user instanceof User && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch) {
            $switch
                ->locales(['en', 'es', 'fr', 'hr']);
        });

        Table::configureUsing(function (Table $table): Table {
            return $table->paginationPageOptions([10, 25, 50, 100]);
        });

        FilamentColor::register(FynixPalette::filamentColors());

    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Identity\OidcConfig::class, fn () => \App\Identity\OidcConfig::resolve());

        $this->app->bind(\App\Identity\OidcClient::class, function ($app) {
            if ($app->bound(\App\Identity\StubOidcClient::class)) {
                return $app->make(\App\Identity\StubOidcClient::class);
            }

            $config = $app->make(\App\Identity\OidcConfig::class);
            if (! $config->isReady()) {
                return new \App\Identity\StubOidcClient;
            }

            return \App\Identity\RealOidcClient::fromConfig($config);
        });

        $this->app->bind(\App\Identity\IdentityService::class, function ($app) {
            return new \App\Identity\IdentityService(
                $app->make(\App\Identity\OidcClient::class),
                $app->make(\App\Identity\OidcConfig::class),
            );
        });

        $this->app->bind(\App\Suite\PpmClient::class, function ($app) {
            if ($app->bound(\App\Suite\FakePpmClient::class)) {
                return $app->make(\App\Suite\FakePpmClient::class);
            }

            if ($app->environment('testing')) {
                return new \App\Suite\FakePpmClient;
            }

            return new \App\Suite\LivePpmClient;
        });

        // Force HTTPS in production environments (must be in register, not boot)
        if (! $this->app->environment('local')) {
            URL::forceScheme('https');

            // Ensure HTTPS is detected from proxy headers
            $this->app['request']->server->set('HTTPS', 'on');
            $_SERVER['HTTPS'] = 'on';
        }

        // Register custom icons
        $this->callAfterResolving(IconFactory::class, function (IconFactory $factory) {
            $factory->add('grc', [
                'path' => resource_path('svg'),
                'prefix' => 'grc',
            ]);
        });

        // Register setting service early so it's available for Filament panel providers
        // The mangoldsecurity/settings package registers this in boot() which is too late
        if (! $this->app->bound('setting')) {
            $this->app->singleton('setting', function () {
                return new \MangoldSecurity\Settings\Services\Setting;
            });
        }
    }
}
