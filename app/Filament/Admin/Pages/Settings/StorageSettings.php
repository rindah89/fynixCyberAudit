<?php

namespace App\Filament\Admin\Pages\Settings;

use Artisan;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Log;
use Throwable;

class StorageSettings extends BaseSettings
{
    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    public static function canAccess(): bool
    {
        if (auth()->check() && auth()->user()->can('Manage Preferences') && setting('storage.locked') != 'true') {
            return true;
        }

        return false;
    }

    public function mount(): void
    {
        // Set default storage driver if not set
        if (empty(setting('storage.driver'))) {
            setting(['storage.driver' => 'private']);
        }

        parent::mount();
    }

    public static function getNavigationGroup(): string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.settings.storage_settings');
    }

    public function form(Schema $schema): Schema
    {
        $isLocked = setting('storage.locked', 'false') === 'true';

        return $schema
            ->components([
                Section::make('Storage Configuration')
                    ->columnSpanFull()
                    ->description($isLocked ? '⚠️ Storage settings are locked and read-only. Contact your administrator to modify these settings.' : null)
                    ->schema([
                        Select::make('storage.driver')
                            ->label('Storage Driver')
                            ->options([
                                'private' => 'Local Private Storage',
                                's3' => 'Amazon S3',
                                'digitalocean' => 'DigitalOcean Spaces',
                            ])
                            ->default('private')
                            ->required()
                            ->live()
                            ->disabled($isLocked),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('storage.s3.key')
                                    ->label('AWS Access Key ID')
                                    ->password()
                                    ->visible(fn ($get) => $get('storage.driver') === 's3')
                                    ->required(fn ($get) => $get('storage.driver') === 's3')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 's3')
                                    ->disabled($isLocked)
                                    ->placeholder(fn () => filled(setting('storage.s3.key')) ? '••••••••' : null)
                                    ->helperText(fn () => filled(setting('storage.s3.key'))
                                        ? 'Key is stored securely. Leave blank to keep current key.'
                                        : null)
                                    ->dehydrateStateUsing(function ($state) {
                                        if (! filled($state)) {
                                            return setting('storage.s3.key');
                                        }

                                        return Crypt::encryptString($state);
                                    })
                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                        $component->state(null);
                                    }),

                                TextInput::make('storage.s3.secret')
                                    ->label('AWS Secret Access Key')
                                    ->password()
                                    ->visible(fn ($get) => $get('storage.driver') === 's3')
                                    ->required(fn ($get) => $get('storage.driver') === 's3')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 's3')
                                    ->disabled($isLocked)
                                    ->placeholder(fn () => filled(setting('storage.s3.secret')) ? '••••••••' : null)
                                    ->helperText(fn () => filled(setting('storage.s3.secret'))
                                        ? 'Secret is stored securely. Leave blank to keep current secret.'
                                        : null)
                                    ->dehydrateStateUsing(function ($state) {
                                        if (! filled($state)) {
                                            return setting('storage.s3.secret');
                                        }

                                        return Crypt::encryptString($state);
                                    })
                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                        $component->state(null);
                                    }),

                                TextInput::make('storage.s3.region')
                                    ->label('AWS Region')
                                    ->placeholder('us-east-1')
                                    ->visible(fn ($get) => $get('storage.driver') === 's3')
                                    ->required(fn ($get) => $get('storage.driver') === 's3')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 's3')
                                    ->disabled($isLocked),

                                TextInput::make('storage.s3.bucket')
                                    ->label('S3 Bucket Name')
                                    ->visible(fn ($get) => $get('storage.driver') === 's3')
                                    ->required(fn ($get) => $get('storage.driver') === 's3')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 's3')
                                    ->disabled($isLocked),

                                TextInput::make('storage.digitalocean.key')
                                    ->label('DigitalOcean Spaces Access Key ID')
                                    ->password()
                                    ->visible(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->required(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->disabled($isLocked)
                                    ->placeholder(fn () => filled(setting('storage.digitalocean.key')) ? '••••••••' : null)
                                    ->helperText(fn () => filled(setting('storage.digitalocean.key'))
                                        ? 'Key is stored securely. Leave blank to keep current key.'
                                        : 'Your DigitalOcean Spaces access key ID')
                                    ->dehydrateStateUsing(function ($state) {
                                        if (! filled($state)) {
                                            return setting('storage.digitalocean.key');
                                        }

                                        return Crypt::encryptString($state);
                                    })
                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                        $component->state(null);
                                    }),

                                TextInput::make('storage.digitalocean.secret')
                                    ->label('DigitalOcean Spaces Secret Access Key')
                                    ->password()
                                    ->visible(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->required(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->disabled($isLocked)
                                    ->placeholder(fn () => filled(setting('storage.digitalocean.secret')) ? '••••••••' : null)
                                    ->helperText(fn () => filled(setting('storage.digitalocean.secret'))
                                        ? 'Secret is stored securely. Leave blank to keep current secret.'
                                        : 'Your DigitalOcean Spaces secret access key')
                                    ->dehydrateStateUsing(function ($state) {
                                        if (! filled($state)) {
                                            return setting('storage.digitalocean.secret');
                                        }

                                        return Crypt::encryptString($state);
                                    })
                                    ->afterStateHydrated(function (TextInput $component, $state) {
                                        $component->state(null);
                                    }),

                                TextInput::make('storage.digitalocean.region')
                                    ->label('DigitalOcean Region')
                                    ->placeholder('nyc3')
                                    ->helperText('DigitalOcean region code (e.g., nyc3, sfo3, fra1) - used for endpoint URL')
                                    ->visible(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->required(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->disabled($isLocked),

                                TextInput::make('storage.digitalocean.bucket')
                                    ->label('DigitalOcean Space Name')
                                    ->helperText('The name of your Space - endpoint will be auto-constructed as spacename.region.digitaloceanspaces.com')
                                    ->visible(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->required(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->dehydrated(fn ($get) => $get('storage.driver') === 'digitalocean')
                                    ->disabled($isLocked),

                            ]),
                    ]),
            ]);
    }

    protected function getActions(): array
    {
        $isLocked = setting('storage.locked', 'false') === 'true';

        if ($isLocked) {
            return [];
        }

        return [
            Action::make('testS3Connection')
                ->label('Test S3 Connection')
                ->color('primary')
                ->action(function () {
                    try {
                        // Get form data directly without saving
                        $formState = $this->form->getState();
                        $key = $formState['storage']['s3']['key'] ?? '';
                        $secret = $formState['storage']['s3']['secret'] ?? '';
                        $region = $formState['storage']['s3']['region'] ?? '';
                        $bucket = $formState['storage']['s3']['bucket'] ?? '';

                        // Decrypt credentials if they're encrypted (they will be from getState after dehydration)
                        try {
                            if (filled($key) && str_starts_with($key, 'eyJpdiI6')) {
                                $key = Crypt::decryptString($key);
                            }
                            if (filled($secret) && str_starts_with($secret, 'eyJpdiI6')) {
                                $secret = Crypt::decryptString($secret);
                            }
                        } catch (Exception $e) {
                            // If decryption fails, use the values as-is (they might not be encrypted yet)
                            Log::warning('Failed to decrypt S3 credentials for testing: '.$e->getMessage());
                        }

                        // Validate that all required fields are filled
                        if (empty($key) || empty($secret) || empty($region) || empty($bucket)) {
                            Notification::make()
                                ->title('S3 connection test failed')
                                ->body('All S3 fields must be filled to test the connection.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // Test connection with current form values
                        static::testS3ConnectionWithCredentials($key, $secret, $region, $bucket);
                    } catch (Exception $e) {
                        // Handle any errors during the test
                        Log::error('S3 connection test failed: '.$e->getMessage());

                        Notification::make()
                            ->title('S3 connection test failed')
                            ->body('Connection test failed: '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(function () {
                    $driver = setting('storage.driver');

                    return $driver === 's3' &&
                           ! empty(setting('storage.s3.key')) &&
                           ! empty(setting('storage.s3.secret')) &&
                           ! empty(setting('storage.s3.region')) &&
                           ! empty(setting('storage.s3.bucket'));
                }),
            Action::make('testDigitalOceanConnection')
                ->label('Test DigitalOcean Connection')
                ->color('primary')
                ->action(function () {
                    try {
                        // Get form data directly without saving
                        $formState = $this->form->getState();
                        $key = $formState['storage']['digitalocean']['key'] ?? '';
                        $secret = $formState['storage']['digitalocean']['secret'] ?? '';
                        $region = $formState['storage']['digitalocean']['region'] ?? '';
                        $bucket = $formState['storage']['digitalocean']['bucket'] ?? '';

                        // Decrypt credentials if they're encrypted (they will be from getState after dehydration)
                        try {
                            if (filled($key) && str_starts_with($key, 'eyJpdiI6')) {
                                $key = Crypt::decryptString($key);
                            }
                            if (filled($secret) && str_starts_with($secret, 'eyJpdiI6')) {
                                $secret = Crypt::decryptString($secret);
                            }
                        } catch (Exception $e) {
                            // If decryption fails, use the values as-is (they might not be encrypted yet)
                            Log::warning('Failed to decrypt credentials for testing: '.$e->getMessage());
                        }

                        // Validate that all required fields are filled
                        if (empty($key) || empty($secret) || empty($region) || empty($bucket)) {
                            Notification::make()
                                ->title('DigitalOcean connection test failed')
                                ->body('All DigitalOcean fields must be filled to test the connection.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // Test connection with current form values
                        static::testDigitalOceanConnectionWithCredentials($key, $secret, $region, $bucket);
                    } catch (Exception $e) {
                        // Handle any errors during the test
                        Log::error('DigitalOcean connection test failed: '.$e->getMessage());

                        Notification::make()
                            ->title('DigitalOcean connection test failed')
                            ->body('Connection test failed: '.$e->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(function () {
                    $driver = setting('storage.driver');

                    return $driver === 'digitalocean' &&
                           ! empty(setting('storage.digitalocean.key')) &&
                           ! empty(setting('storage.digitalocean.secret')) &&
                           ! empty(setting('storage.digitalocean.region')) &&
                           ! empty(setting('storage.digitalocean.bucket'));
                }),
        ];
    }

    protected function afterSave(): void
    {
        $driver = setting('storage.driver');

        try {
            // Update environment variables based on the selected storage driver
            if ($driver === 'digitalocean') {
                Log::info('About to update DigitalOcean environment variables after save');
                static::updateDigitalOceanEnvVars();
                Log::info('Successfully updated DigitalOcean environment variables after save');
            } elseif ($driver === 's3') {
                Log::info('About to update S3 environment variables after save');
                static::updateS3EnvVars();
                Log::info('Successfully updated S3 environment variables after save');
            } else {
                // For private storage, clear all cloud storage env vars
                Log::info('Clearing cloud storage environment variables for driver: '.$driver);
                static::clearDigitalOceanEnvVars();
                static::clearS3EnvVars();

                // Update the FILESYSTEM_DISK environment variable to match the selected driver
                static::updateFilesystemDisk($driver);
                Log::info('Successfully updated environment variables for driver: '.$driver);
            }
        } catch (Throwable $e) {
            Log::error('Failed to update environment variables after save: '.$e->getMessage());
            Log::error('Exception trace: '.$e->getTraceAsString());

            // Don't break the save process, just log the error
            // The user's settings will still be saved
        }
    }

    protected static function testS3Connection(): void
    {
        try {
            // First check if we can even get configuration
            $s3Config = static::getS3Configuration();

            // Early validation to provide clear error messages
            static::validateS3Configuration($s3Config);

            // Configure test disk
            static::configureS3Settings($s3Config);

            // Run actual connection test
            static::testS3Access($s3Config);

            Notification::make()
                ->title('S3 connection test successful!')
                ->body("Successfully connected to bucket: {$s3Config['bucket']}")
                ->success()
                ->send();

        } catch (Throwable $e) {
            // Catch all throwable errors to prevent 500 responses
            Log::error('S3 connection test error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            static::handleS3Error($e, $s3Config ?? []);
        }
    }

    protected static function testDigitalOceanConnection(): void
    {
        try {
            // First check if we can even get configuration
            $doConfig = static::getDigitalOceanConfiguration();

            // Early validation to provide clear error messages
            static::validateDigitalOceanConfiguration($doConfig);

            // Configure test disk
            static::configureDigitalOceanSettings($doConfig);

            // Run actual connection test
            static::testDigitalOceanAccess($doConfig);

            Notification::make()
                ->title('DigitalOcean Spaces connection test successful!')
                ->body("Successfully connected to space: {$doConfig['bucket']}")
                ->success()
                ->send();

        } catch (Throwable $e) {
            // Catch all throwable errors to prevent 500 responses
            Log::error('DigitalOcean connection test error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            static::handleDigitalOceanError($e, $doConfig ?? []);
        }
    }

    protected static function getS3Configuration(): array
    {
        $key = setting('storage.s3.key');
        $secret = setting('storage.s3.secret');

        // Decrypt if encrypted
        try {
            if (filled($key)) {
                $key = Crypt::decryptString($key);
            }
            if (filled($secret)) {
                $secret = Crypt::decryptString($secret);
            }
        } catch (Exception $e) {
            // If decryption fails, assume they're plain text
        }

        return [
            'key' => $key,
            'secret' => $secret,
            'region' => setting('storage.s3.region'),
            'bucket' => setting('storage.s3.bucket'),
        ];
    }

    protected static function getDigitalOceanConfiguration(): array
    {
        try {
            $key = setting('storage.digitalocean.key', '');
            $secret = setting('storage.digitalocean.secret', '');
            $region = setting('storage.digitalocean.region', '');
            $bucket = setting('storage.digitalocean.bucket', '');

            // Ensure we have strings, not null values
            $key = $key ?? '';
            $secret = $secret ?? '';
            $region = $region ?? '';
            $bucket = $bucket ?? '';

            // Log what we got from settings for debugging
            Log::info('DigitalOcean Settings Retrieved:', [
                'key_present' => filled($key),
                'secret_present' => filled($secret),
                'region' => $region,
                'bucket' => $bucket,
            ]);

            // Decrypt if encrypted
            try {
                if (filled($key) && is_string($key)) {
                    $decryptedKey = Crypt::decryptString($key);
                    $key = $decryptedKey ?: '';
                }
                if (filled($secret) && is_string($secret)) {
                    $decryptedSecret = Crypt::decryptString($secret);
                    $secret = $decryptedSecret ?: '';
                }
            } catch (Exception $e) {
                // If decryption fails, assume they are plain text or return empty
                Log::warning('Failed to decrypt DigitalOcean credentials, treating as plain text: '.$e->getMessage());
                $key = is_string($key) ? $key : '';
                $secret = is_string($secret) ? $secret : '';
            }

            // Validate region format
            if (filled($region) && ! preg_match('/^[a-z0-9]+$/', strtolower($region))) {
                throw new Exception('Invalid DigitalOcean region format. Please use a valid region code like "nyc3", "sfo3", or "fra1".');
            }

            // Use path-style endpoint instead of virtual hosted-style
            // Format: https://region.digitaloceanspaces.com (bucket will be in path)
            $endpoint = filled($region) ? 'https://'.strtolower($region).'.digitaloceanspaces.com' : '';

            return [
                'key' => $key ?: '',
                'secret' => $secret ?: '',
                'region' => $region ?: '',
                'bucket' => $bucket ?: '',
                'endpoint' => $endpoint,
            ];
        } catch (Exception $e) {
            // Re-throw with better context
            throw new Exception('Failed to retrieve DigitalOcean configuration: '.$e->getMessage());
        }
    }

    protected static function validateS3Configuration(array $s3Config): void
    {
        if (empty($s3Config['key']) || empty($s3Config['secret']) ||
            empty($s3Config['region']) || empty($s3Config['bucket'])) {
            throw new Exception('S3 configuration is incomplete. Please ensure all fields are filled.');
        }

        if (! str_starts_with($s3Config['key'], 'AKIA')) {
            throw new Exception('AWS Access Key ID should start with "AKIA". Please verify your credentials.');
        }
    }

    protected static function validateDigitalOceanConfiguration(array $doConfig): void
    {
        $missing = [];

        if (empty($doConfig['key'])) {
            $missing[] = 'Access Key ID';
        }
        if (empty($doConfig['secret'])) {
            $missing[] = 'Secret Access Key';
        }
        if (empty($doConfig['region'])) {
            $missing[] = 'Region';
        }
        if (empty($doConfig['bucket'])) {
            $missing[] = 'Space Name';
        }

        if (! empty($missing)) {
            $missingFields = implode(', ', $missing);
            throw new Exception("DigitalOcean Spaces configuration is incomplete. Missing fields: {$missingFields}. Please ensure all fields are filled and try again.");
        }

        // Additional validation for region format
        if (! preg_match('/^[a-z0-9]+$/', strtolower($doConfig['region']))) {
            throw new Exception('Invalid DigitalOcean region format. Please use a valid region code like "nyc3", "sfo3", or "fra1".');
        }

        // Validate space name format (should not have special chars)
        if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', strtolower($doConfig['bucket']))) {
            throw new Exception('Invalid DigitalOcean Space name format. Space names must contain only lowercase letters, numbers, and hyphens, and cannot start or end with a hyphen.');
        }
    }

    protected static function configureS3Settings(array $s3Config): void
    {
        // Temporarily configure S3 for testing
        config([
            'filesystems.disks.s3' => [
                'driver' => 's3',
                'key' => $s3Config['key'],
                'secret' => $s3Config['secret'],
                'region' => $s3Config['region'],
                'bucket' => $s3Config['bucket'],
                'url' => env('AWS_URL'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'throw' => false,
            ],
        ]);

        Log::info("S3 test configuration: Bucket: {$s3Config['bucket']}, Region: {$s3Config['region']}, Key: ".substr($s3Config['key'], 0, 8).'...');
    }

    protected static function configureDigitalOceanSettings(array $doConfig): void
    {
        // Configure DigitalOcean Spaces for testing (it's S3-compatible)
        // Per DigitalOcean docs: region is always 'us-east-1' for AWS SDK compatibility
        config([
            'filesystems.disks.digitalocean.key' => $doConfig['key'],
            'filesystems.disks.digitalocean.secret' => $doConfig['secret'],
            'filesystems.disks.digitalocean.region' => 'us-east-1', // Always us-east-1 per DigitalOcean documentation
            'filesystems.disks.digitalocean.bucket' => $doConfig['bucket'],
            'filesystems.disks.digitalocean.endpoint' => $doConfig['endpoint'],
            'filesystems.disks.digitalocean.use_path_style_endpoint' => true, // Use path-style instead of virtual hosted-style
            'filesystems.disks.digitalocean.throw' => false,
        ]);

        Log::info('DigitalOcean Spaces test configuration', [
            'space' => $doConfig['bucket'],
            'endpoint' => $doConfig['endpoint'],
            'do_region' => $doConfig['region'],
            'aws_region' => 'us-east-1',
            'key_prefix' => substr($doConfig['key'], 0, 8).'...',
            'use_path_style_endpoint' => true,
        ]);
    }

    protected static function testS3Access(array $s3Config): void
    {
        $disk = Storage::disk('s3');

        // Skip the existence check and go directly to read/write test
        // This is more reliable than checking bucket existence
        $testFileName = 'fynixcyberaudit-connection-test-'.uniqid().'.txt';
        $testContent = 'Fynix Cyber Audit S3 connection test - '.date('Y-m-d H:i:s');

        try {
            Log::info("Starting S3 read/write test with file: {$testFileName}");

            // Test: Write, read, and delete a test file
            $disk->put($testFileName, $testContent);
            Log::info("S3 write test successful: {$testFileName}");

            // Verify file was written by reading it back
            if (! $disk->exists($testFileName)) {
                throw new Exception('Test file was not found after writing');
            }

            $readContent = $disk->get($testFileName);
            if ($readContent !== $testContent) {
                throw new Exception('Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"');
            }
            Log::info('S3 read test successful');

            // Clean up test file
            $disk->delete($testFileName);

        } catch (Exception $e) {
            // Try to clean up test file if it was created
            try {
                if ($disk->exists($testFileName)) {
                    $disk->delete($testFileName);
                    Log::info('Cleaned up test file after error');
                }
            } catch (Exception $cleanupError) {
                Log::warning('Failed to cleanup test file: '.$cleanupError->getMessage());
            }

            // Provide more specific error message
            $errorMsg = $e->getMessage();
            if (str_contains($errorMsg, 'InvalidAccessKeyId')) {
                throw new Exception('Invalid AWS Access Key ID. Please verify your credentials.');
            } elseif (str_contains($errorMsg, 'SignatureDoesNotMatch')) {
                throw new Exception('Invalid AWS Secret Access Key. Please verify your credentials.');
            } elseif (str_contains($errorMsg, 'NoSuchBucket')) {
                throw new Exception("S3 bucket '{$s3Config['bucket']}' does not exist or is not accessible in region '{$s3Config['region']}'.");
            } elseif (str_contains($errorMsg, 'AccessDenied')) {
                throw new Exception("Access denied to S3 bucket '{$s3Config['bucket']}'. Please check IAM permissions.");
            } else {
                throw new Exception('S3 connection test failed: '.$errorMsg);
            }
        }
    }

    protected static function testDigitalOceanAccess(array $doConfig): void
    {
        $disk = Storage::disk('digitalocean');

        // Test: Write, read, and delete a test file
        $testFileName = 'fynixcyberaudit-connection-test-'.uniqid().'.txt';
        $testContent = 'Fynix Cyber Audit DigitalOcean Spaces connection test - '.date('Y-m-d H:i:s');

        try {
            Log::info('Starting DigitalOcean Spaces test', [
                'file' => $testFileName,
                'endpoint' => $doConfig['endpoint'],
                'bucket' => $doConfig['bucket'],
                'region' => $doConfig['region'],
            ]);

            // Simplified test: just try to write and immediately read back
            $disk->put($testFileName, $testContent);
            Log::info("DigitalOcean Spaces write successful: {$testFileName}");

            // Wait a moment for eventual consistency
            sleep(2);
            Log::info('Waiting for DigitalOcean Spaces consistency...');

            // Read back after delay
            $readContent = $disk->get($testFileName);
            if ($readContent !== $testContent) {
                throw new Exception('Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"');
            }
            Log::info('DigitalOcean Spaces read test successful');

            // Clean up test file
            $disk->delete($testFileName);
            Log::info('DigitalOcean Spaces cleanup successful');

        } catch (Exception $e) {
            Log::error('DigitalOcean Spaces test failed', [
                'error' => $e->getMessage(),
                'endpoint' => $doConfig['endpoint'],
                'file' => $testFileName,
            ]);

            // Try to clean up test file if it was created
            try {
                $disk->delete($testFileName);
                Log::info('Cleaned up test file after error');
            } catch (Exception $cleanupError) {
                Log::warning('Failed to cleanup test file: '.$cleanupError->getMessage());
            }

            // Provide more specific error message
            $errorMsg = $e->getMessage();
            if (str_contains($errorMsg, 'InvalidAccessKeyId')) {
                throw new Exception('Invalid DigitalOcean Spaces Access Key. Please verify your Spaces credentials.');
            } elseif (str_contains($errorMsg, 'SignatureDoesNotMatch')) {
                throw new Exception('Invalid DigitalOcean Spaces Secret Key. Please verify your Spaces credentials.');
            } elseif (str_contains($errorMsg, 'NoSuchBucket')) {
                throw new Exception("DigitalOcean Space '{$doConfig['bucket']}' does not exist or is not accessible at endpoint '{$doConfig['endpoint']}'.");
            } elseif (str_contains($errorMsg, 'AccessDenied')) {
                throw new Exception("Access denied to DigitalOcean Space '{$doConfig['bucket']}'. Please check Spaces permissions.");
            } elseif (str_contains($errorMsg, 'Unable to check existence')) {
                throw new Exception("Connection to DigitalOcean Space failed. Please verify your endpoint '{$doConfig['endpoint']}' and credentials.");
            } else {
                throw new Exception('DigitalOcean Spaces connection test failed: '.$errorMsg);
            }
        }
    }

    protected static function handleS3Error(Exception $e, array $s3Config): void
    {
        $errorMessage = $e->getMessage();

        // Add helpful hints for common S3 errors
        if (str_contains($errorMessage, 'InvalidAccessKeyId') || str_contains($errorMessage, 'SignatureDoesNotMatch')) {
            $errorMessage .= "\n\nS3 troubleshooting:\n• Verify your AWS Access Key ID and Secret Access Key are correct\n• Ensure the IAM user has proper S3 permissions\n• Check that the credentials haven't expired\n• Make sure you're using the correct AWS region";
        } elseif (str_contains($errorMessage, 'NoSuchBucket')) {
            $errorMessage .= "\n\nS3 troubleshooting:\n• Verify the bucket name is correct and exists\n• Ensure the bucket is in the specified region\n• Check that your IAM user has access to this bucket";
        } elseif (str_contains($errorMessage, 'AccessDenied')) {
            $errorMessage .= "\n\nS3 troubleshooting:\n• Your IAM user needs s3:GetObject, s3:PutObject, s3:DeleteObject permissions\n• Check bucket policies that might be restricting access\n• Verify the IAM user has access to the specific bucket path";
        }

        Notification::make()
            ->title('S3 connection test failed')
            ->body($errorMessage)
            ->danger()
            ->send();
    }

    protected static function handleDigitalOceanError(Exception $e, array $doConfig): void
    {
        $errorMessage = $e->getMessage();

        // Add helpful hints for common DigitalOcean Spaces errors
        if (str_contains($errorMessage, 'InvalidAccessKeyId') || str_contains($errorMessage, 'SignatureDoesNotMatch')) {
            $errorMessage .= "\n\nDigitalOcean Spaces troubleshooting:\n• Verify your Spaces Access Key ID and Secret Access Key are correct\n• Get these credentials from the API section in your DigitalOcean control panel\n• Ensure the Spaces keys have proper permissions\n• Check that the credentials haven't expired";
        } elseif (str_contains($errorMessage, 'NoSuchBucket')) {
            $errorMessage .= "\n\nDigitalOcean Spaces troubleshooting:\n• Verify the Space name is correct and exists\n• Ensure the Space is in the specified region\n• Check the endpoint URL matches your region\n• Verify your account has access to this Space";
        } elseif (str_contains($errorMessage, 'AccessDenied')) {
            $errorMessage .= "\n\nDigitalOcean Spaces troubleshooting:\n• Your Spaces keys need read/write permissions\n• Check Space CORS settings if applicable\n• Verify your account has access to the specific Space\n• Ensure the Space keys are not restricted";
        }

        Notification::make()
            ->title('DigitalOcean Spaces connection test failed')
            ->body($errorMessage)
            ->danger()
            ->send();
    }

    public static function updateS3EnvVars(): void
    {
        try {
            $s3Config = static::getS3Configuration();

            // Log what we received for debugging
            Log::info('updateS3EnvVars called with config:', [
                'key_present' => ! empty($s3Config['key']),
                'secret_present' => ! empty($s3Config['secret']),
                'region' => $s3Config['region'] ?? 'null',
                'bucket' => $s3Config['bucket'] ?? 'null',
            ]);

            if (empty($s3Config['key']) || empty($s3Config['secret']) ||
                empty($s3Config['region']) || empty($s3Config['bucket'])) {
                Log::info('S3 configuration incomplete, skipping env update');

                return;
            }

            $envVars = [
                'AWS_ACCESS_KEY_ID' => $s3Config['key'],
                'AWS_SECRET_ACCESS_KEY' => $s3Config['secret'],
                'AWS_DEFAULT_REGION' => $s3Config['region'],
                'AWS_BUCKET' => $s3Config['bucket'],
            ];

            Log::info('About to write S3 env vars:', array_keys($envVars));
            static::writeEnvVars($envVars);

            // Set the default filesystem to s3
            static::writeEnvVars(['FILESYSTEM_DISK' => 's3']);

            Log::info('Updated S3 environment variables', [
                'bucket' => $s3Config['bucket'],
                'region' => $s3Config['region'],
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to update S3 environment variables: '.$e->getMessage());
            Log::error('Exception trace: '.$e->getTraceAsString());
            throw $e; // Re-throw to allow upper error handling
        }
    }

    public static function clearS3EnvVars(): void
    {
        try {
            $envVars = [
                'AWS_ACCESS_KEY_ID' => '',
                'AWS_SECRET_ACCESS_KEY' => '',
                'AWS_DEFAULT_REGION' => '',
                'AWS_BUCKET' => '',
            ];

            static::writeEnvVars($envVars);

            Log::info('Cleared S3 environment variables');

        } catch (Exception $e) {
            Log::error('Failed to clear S3 environment variables: '.$e->getMessage());
        }
    }

    public static function updateDigitalOceanEnvVars(): void
    {
        try {
            $doConfig = static::getDigitalOceanConfiguration();

            // Log what we received for debugging
            Log::info('updateDigitalOceanEnvVars called with config:', [
                'key_present' => ! empty($doConfig['key']),
                'secret_present' => ! empty($doConfig['secret']),
                'region' => $doConfig['region'] ?? 'null',
                'bucket' => $doConfig['bucket'] ?? 'null',
                'config_type' => gettype($doConfig),
            ]);

            if (empty($doConfig['key']) || empty($doConfig['secret']) ||
                empty($doConfig['region']) || empty($doConfig['bucket'])) {
                Log::info('DigitalOcean configuration incomplete, skipping env update');

                return;
            }

            // Construct endpoint
            $endpoint = 'https://'.strtolower($doConfig['region']).'.digitaloceanspaces.com';

            $envVars = [
                'DO_SPACES_KEY' => $doConfig['key'],
                'DO_SPACES_SECRET' => $doConfig['secret'],
                'DO_SPACES_REGION' => 'us-east-1', // Always us-east-1 for AWS SDK compatibility
                'DO_SPACES_BUCKET' => $doConfig['bucket'],
                'DO_SPACES_ENDPOINT' => $endpoint,
                'DO_SPACES_USE_PATH_STYLE' => 'true',
            ];

            Log::info('About to write env vars:', array_keys($envVars));
            static::writeEnvVars($envVars);

            // Set the default filesystem to digitalocean
            static::writeEnvVars(['FILESYSTEM_DISK' => 'digitalocean']);

            Log::info('Updated DigitalOcean environment variables', [
                'endpoint' => $endpoint,
                'bucket' => $doConfig['bucket'],
                'region' => $doConfig['region'],
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to update DigitalOcean environment variables: '.$e->getMessage());
            Log::error('Exception trace: '.$e->getTraceAsString());
            throw $e; // Re-throw to allow upper error handling
        }
    }

    public static function clearDigitalOceanEnvVars(): void
    {
        try {
            $envVars = [
                'DO_SPACES_KEY' => '',
                'DO_SPACES_SECRET' => '',
                'DO_SPACES_REGION' => '',
                'DO_SPACES_BUCKET' => '',
                'DO_SPACES_ENDPOINT' => '',
                'DO_SPACES_USE_PATH_STYLE' => '',
            ];

            static::writeEnvVars($envVars);

            Log::info('Cleared DigitalOcean environment variables');

        } catch (Exception $e) {
            Log::error('Failed to clear DigitalOcean environment variables: '.$e->getMessage());
        }
    }

    public static function updateFilesystemDisk(string $driver): void
    {
        try {
            $envVars = [
                'FILESYSTEM_DISK' => $driver,
            ];

            static::writeEnvVars($envVars);

            Log::info('Updated FILESYSTEM_DISK to: '.$driver);

        } catch (Exception $e) {
            Log::error('Failed to update FILESYSTEM_DISK: '.$e->getMessage());
            throw $e;
        }
    }

    protected static function writeEnvVars(array $vars): void
    {
        // Validate that $vars is actually an array
        if (! is_array($vars) || empty($vars)) {
            Log::warning('writeEnvVars called with invalid or empty vars', ['vars' => $vars]);

            return;
        }

        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            throw new Exception('.env file not found');
        }

        $envContent = File::get($envPath);

        foreach ($vars as $key => $value) {
            // Ensure key is a string
            if (! is_string($key) || empty($key)) {
                Log::warning('Skipping invalid env var key', ['key' => $key, 'value' => $value]);

                continue;
            }

            $pattern = "/^{$key}=.*$/m";
            $replacement = $key.'='.(empty($value) ? '' : $value);

            if (preg_match($pattern, $envContent)) {
                // Update existing variable
                $envContent = preg_replace($pattern, $replacement, $envContent);
            } else {
                // Add new variable at the end
                $envContent .= "\n{$replacement}";
            }
        }

        File::put($envPath, $envContent);

        // Don't cache config during active Livewire requests to avoid disrupting form state
        // Just clear the cache so new env values are picked up
        try {
            Artisan::call('config:clear');
            Log::info('Config cache cleared after env update');
        } catch (Exception $e) {
            Log::warning('Failed to clear config cache: '.$e->getMessage());
        }
    }

    /**
     * Test S3 connection with provided credentials without saving to settings
     */
    protected static function testS3ConnectionWithCredentials(string $key, string $secret, string $region, string $bucket): void
    {
        try {
            // Validate inputs
            if (empty($key) || empty($secret) || empty($region) || empty($bucket)) {
                throw new Exception('All S3 credentials are required for testing.');
            }

            // Validate key format
            if (! str_starts_with($key, 'AKIA')) {
                throw new Exception('AWS Access Key ID should start with "AKIA". Please verify your credentials.');
            }

            Log::info('Testing S3 connection with provided credentials:', [
                'bucket' => $bucket,
                'region' => $region,
                'key_prefix' => substr($key, 0, 8).'...',
            ]);

            // Create test configuration
            $testConfig = [
                'driver' => 's3',
                'key' => $key,
                'secret' => $secret,
                'region' => $region,
                'bucket' => $bucket,
                'url' => env('AWS_URL'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'throw' => false,
            ];

            // Temporarily configure the s3 disk for testing
            config(['filesystems.disks.s3' => $testConfig]);

            // Generate a unique test file name
            $testFileName = 'fynixcyberaudit-connection-test-'.uniqid().'.txt';
            $testContent = 'Fynix Cyber Audit S3 connection test - '.now();

            Log::info('Starting S3 test', [
                'file' => $testFileName,
                'bucket' => $bucket,
                'region' => $region,
            ]);

            // Get the disk instance
            $disk = \Storage::disk('s3');

            // Test 1: Write a test file
            $writeResult = $disk->put($testFileName, $testContent);
            if (! $writeResult) {
                throw new Exception('Failed to write test file to S3. Please check your credentials and permissions.');
            }

            Log::info('S3 write successful: '.$testFileName);

            // Test 2: Wait for consistency and then read it back
            sleep(2); // Give S3 a moment for consistency
            Log::info('Waiting for S3 consistency...');

            if (! $disk->exists($testFileName)) {
                // Cleanup and throw error
                try {
                    $disk->delete($testFileName);
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }
                throw new Exception('Test file was written but cannot be found. This might indicate a permissions issue.');
            }

            $readContent = $disk->get($testFileName);
            if ($readContent !== $testContent) {
                // Cleanup and throw error
                try {
                    $disk->delete($testFileName);
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }

                Log::error('S3 test failed', [
                    'error' => 'Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"',
                    'bucket' => $bucket,
                    'file' => $testFileName,
                ]);

                throw new Exception('Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"');
            }

            Log::info('S3 read test successful');

            // Test 3: Clean up the test file
            $deleteResult = $disk->delete($testFileName);
            if (! $deleteResult) {
                Log::warning('Could not delete test file: '.$testFileName);
                // This is not critical, so we won't fail the test
            } else {
                Log::info('S3 cleanup successful');
            }

            // Success notification
            Notification::make()
                ->title('S3 Connection Successful!')
                ->body("Successfully connected to '{$bucket}' in region '{$region}'. All operations (read, write, delete) completed successfully.")
                ->success()
                ->send();

        } catch (Exception $e) {
            Log::error('S3 connection test error: '.$e->getMessage());

            throw new Exception('S3 connection test failed: '.$e->getMessage());
        }
    }

    /**
     * Test DigitalOcean connection with provided credentials without saving to settings
     */
    protected static function testDigitalOceanConnectionWithCredentials(string $key, string $secret, string $region, string $bucket): void
    {
        try {
            // Validate inputs
            if (empty($key) || empty($secret) || empty($region) || empty($bucket)) {
                throw new Exception('All DigitalOcean credentials are required for testing.');
            }

            // Validate region format
            if (! preg_match('/^[a-z0-9]+$/', strtolower($region))) {
                throw new Exception('Invalid DigitalOcean region format. Please use a valid region code like "nyc3", "sfo3", or "fra1".');
            }

            // Validate bucket format
            if (! preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', strtolower($bucket))) {
                throw new Exception('Invalid DigitalOcean Space name format. Space names must contain only lowercase letters, numbers, and hyphens.');
            }

            // Build endpoint from region
            $endpoint = "https://{$region}.digitaloceanspaces.com";

            Log::info('Testing DigitalOcean Spaces connection with provided credentials:', [
                'space' => $bucket,
                'endpoint' => $endpoint,
                'region' => $region,
                'key_prefix' => substr($key, 0, 8).'...',
            ]);

            // Create test configuration
            $testConfig = [
                'driver' => 's3',
                'key' => $key,
                'secret' => $secret,
                'region' => 'us-east-1', // AWS SDK region for DigitalOcean
                'bucket' => $bucket,
                'endpoint' => $endpoint,
                'use_path_style_endpoint' => true,
                'throw' => false,
            ];

            // Temporarily configure the digitalocean disk for testing
            config(['filesystems.disks.digitalocean' => $testConfig]);

            // Generate a unique test file name
            $testFileName = 'fynixcyberaudit-connection-test-'.uniqid().'.txt';
            $testContent = 'Fynix Cyber Audit DigitalOcean Spaces connection test - '.now();

            Log::info('Starting DigitalOcean Spaces test', [
                'file' => $testFileName,
                'endpoint' => $endpoint,
                'bucket' => $bucket,
                'region' => $region,
            ]);

            // Get the disk instance
            $disk = \Storage::disk('digitalocean');

            // Test 1: Write a test file
            $writeResult = $disk->put($testFileName, $testContent);
            if (! $writeResult) {
                throw new Exception('Failed to write test file to DigitalOcean Spaces. Please check your credentials and permissions.');
            }

            Log::info('DigitalOcean Spaces write successful: '.$testFileName);

            // Test 2: Wait for consistency and then read it back
            sleep(2); // Give DigitalOcean a moment for consistency
            Log::info('Waiting for DigitalOcean Spaces consistency...');

            if (! $disk->exists($testFileName)) {
                // Cleanup and throw error
                try {
                    $disk->delete($testFileName);
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }
                throw new Exception('Test file was written but cannot be found. This might indicate a permissions issue.');
            }

            $readContent = $disk->get($testFileName);
            if ($readContent !== $testContent) {
                // Cleanup and throw error
                try {
                    $disk->delete($testFileName);
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }

                Log::error('DigitalOcean Spaces test failed', [
                    'error' => 'Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"',
                    'endpoint' => $endpoint,
                    'file' => $testFileName,
                ]);

                throw new Exception('Content mismatch: expected "'.$testContent.'", got "'.$readContent.'"');
            }

            Log::info('DigitalOcean Spaces read test successful');

            // Test 3: Clean up the test file
            $deleteResult = $disk->delete($testFileName);
            if (! $deleteResult) {
                Log::warning('Could not delete test file: '.$testFileName);
                // This is not critical, so we won't fail the test
            } else {
                Log::info('DigitalOcean Spaces cleanup successful');
            }

            // Success notification
            Notification::make()
                ->title('DigitalOcean Spaces Connection Successful!')
                ->body("Successfully connected to '{$bucket}' in region '{$region}'. All operations (read, write, delete) completed successfully.")
                ->success()
                ->send();

        } catch (Exception $e) {
            Log::error('DigitalOcean connection test error: '.$e->getMessage());

            throw new Exception('DigitalOcean Spaces connection test failed: '.$e->getMessage());
        }
    }
}
