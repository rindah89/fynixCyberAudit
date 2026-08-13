<?php

namespace App\Console\Commands;

use DB;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class Deploy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fynix:deploy
                            {--db-driver=mysql : Database driver (mysql, pgsql, or sqlite)}
                            {--db-host=127.0.0.1 : Database host}
                            {--db-port= : Database port (3306 for MySQL, 5432 for PostgreSQL)}
                            {--db-name=fynixcyberaudit : Database name}
                            {--db-user= : Database username}
                            {--db-password= : Database password}
                            {--admin-email=admin@example.com : Admin user email address}
                            {--admin-password= : Admin user password}
                            {--site-name=Fynix Cyber Audit : Site name}
                            {--site-url=https://fynixcyberaudit.test : Site URL}
                            {--app-key= : Application key (will generate if not provided)}
                            {--s3 : Enable S3 storage configuration}
                            {--s3-bucket= : S3 bucket name}
                            {--s3-region= : S3 region}
                            {--s3-key= : S3 access key ID}
                            {--s3-secret= : S3 secret access key}
                            {--digitalocean : Enable DigitalOcean Spaces storage configuration}
                            {--do-bucket= : DigitalOcean Space name}
                            {--do-region= : DigitalOcean region (e.g., nyc3, sfo3, fra1)}
                            {--do-key= : DigitalOcean Spaces access key ID}
                            {--do-secret= : DigitalOcean Spaces secret access key}
                            {--smtp : Enable SMTP configuration}
                            {--smtp-host= : SMTP server host}
                            {--smtp-port=587 : SMTP server port}
                            {--smtp-username= : SMTP username}
                            {--smtp-password= : SMTP password}
                            {--smtp-encryption=tls : SMTP encryption (tls, ssl, or none)}
                            {--smtp-from= : From email address}
                            {--lock : Lock storage settings to read-only after deployment}
                            {--skip-migration : Skip database migrations and seeding}
                            {--accept : Auto-accept deployment without confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy Fynix Cyber Audit with command line configuration for production environments';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->displayHeader();

        // Validate required parameters
        if (! $this->validateRequiredParameters()) {
            return;
        }

        // Get configuration values
        $config = $this->getConfiguration();

        // Display configuration summary
        $this->displayConfigurationSummary($config);

        // Confirm deployment
        if (! $this->option('accept')) {
            if (! $this->confirm('Proceed with Fynix Cyber Audit deployment?', true)) {
                $this->error('Deployment cancelled.');

                return;
            }
        } else {
            $this->info('[INFO] Auto-accepting deployment (--accept flag provided)');
        }

        try {
            $this->performDeployment($config);
            $this->displaySuccess();
        } catch (Exception $e) {
            $this->error('Deployment failed: '.$e->getMessage());
            exit(1); // Exit with error code
        }
    }

    /**
     * Display the deployment header
     */
    protected function displayHeader(): void
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════════╗');
        $this->info('║                 Fynix Cyber Audit Deployment Tool                ║');
        $this->info('║                                                                  ║');
        $this->info('║  Automated deployment for production environments               ║');
        $this->info('╚══════════════════════════════════════════════════════════════════╝');
        $this->info('');
    }

    /**
     * Validate required parameters
     */
    protected function validateRequiredParameters(): bool
    {
        $errors = [];

        // Validate database driver
        $dbDriver = $this->option('db-driver');
        if (! in_array($dbDriver, ['mysql', 'pgsql', 'sqlite'])) {
            $errors[] = 'Database driver must be either "mysql", "pgsql", or "sqlite"';
        }

        // Validate required database parameters (skip for sqlite)
        if ($dbDriver !== 'sqlite') {
            if (! $this->option('db-user')) {
                $errors[] = 'Database username is required (--db-user)';
            }

            if (! $this->option('db-password')) {
                $errors[] = 'Database password is required (--db-password)';
            }
        }

        if (! $this->option('admin-password')) {
            $errors[] = 'Admin password is required (--admin-password)';
        }

        // Validate S3 parameters if S3 is enabled
        if ($this->option('s3')) {
            $s3Required = ['s3-bucket', 's3-region', 's3-key', 's3-secret'];
            foreach ($s3Required as $param) {
                if (! $this->option($param)) {
                    $errors[] = "S3 {$param} is required when --s3 is enabled";
                }
            }
        }

        // Validate DigitalOcean parameters if DigitalOcean is enabled
        if ($this->option('digitalocean')) {
            $doRequired = ['do-bucket', 'do-region', 'do-key', 'do-secret'];
            foreach ($doRequired as $param) {
                if (! $this->option($param)) {
                    $errors[] = "DigitalOcean {$param} is required when --digitalocean is enabled";
                }
            }
        }

        // Validate SMTP parameters if SMTP is enabled
        if ($this->option('smtp')) {
            $smtpRequired = ['smtp-host', 'smtp-username', 'smtp-password', 'smtp-from'];
            foreach ($smtpRequired as $param) {
                if (! $this->option($param)) {
                    $errors[] = "SMTP {$param} is required when --smtp is enabled";
                }
            }

            // Validate SMTP encryption type
            $smtpEncryption = $this->option('smtp-encryption');
            if (! in_array($smtpEncryption, ['tls', 'ssl', 'none'])) {
                $errors[] = 'SMTP encryption must be "tls", "ssl", or "none"';
            }

            // Validate SMTP port
            $smtpPort = $this->option('smtp-port');
            if (! is_numeric($smtpPort) || $smtpPort < 1 || $smtpPort > 65535) {
                $errors[] = 'SMTP port must be a valid port number (1-65535)';
            }
        }

        // Ensure only one storage type is enabled
        if ($this->option('s3') && $this->option('digitalocean')) {
            $errors[] = 'Cannot enable both S3 and DigitalOcean storage. Please choose one.';
        }

        // Validate admin password strength
        $adminPassword = $this->option('admin-password');
        if ($adminPassword && strlen($adminPassword) < 8) {
            $errors[] = 'Admin password must be at least 8 characters long';
        }

        if (! empty($errors)) {
            $this->error('Validation failed:');
            foreach ($errors as $error) {
                $this->error('  • '.$error);
            }
            $this->info('');
            $this->info('Use --help to see all available options.');

            return false;
        }

        return true;
    }

    /**
     * Get deployment configuration
     */
    protected function getConfiguration(): array
    {
        $dbDriver = $this->option('db-driver');

        // Set default port based on database driver (not needed for sqlite)
        $defaultPort = $dbDriver === 'mysql' ? '3306' : '5432';
        $dbPort = $this->option('db-port') ?: $defaultPort;

        $config = [
            'db_driver' => $dbDriver,
            'db_host' => $dbDriver === 'sqlite' ? null : $this->option('db-host'),
            'db_port' => $dbDriver === 'sqlite' ? null : $dbPort,
            'db_database' => $dbDriver === 'sqlite' ? database_path('database.sqlite') : $this->option('db-name'),
            'db_username' => $dbDriver === 'sqlite' ? null : $this->option('db-user'),
            'db_password' => $dbDriver === 'sqlite' ? null : $this->option('db-password'),
            'admin_email' => $this->option('admin-email'),
            'admin_password' => $this->option('admin-password'),
            'site_name' => $this->option('site-name'),
            'site_url' => $this->option('site-url'),
            'app_key' => $this->option('app-key'),
            's3_enabled' => $this->option('s3'),
            'digitalocean_enabled' => $this->option('digitalocean'),
            'smtp_enabled' => $this->option('smtp'),
            'lock_storage' => $this->option('lock'),
            'skip_migration' => $this->option('skip-migration'),
        ];

        // Add S3 configuration if enabled
        if ($config['s3_enabled']) {
            $config['s3_bucket'] = $this->option('s3-bucket');
            $config['s3_region'] = $this->option('s3-region');
            $config['s3_key'] = $this->option('s3-key');
            $config['s3_secret'] = $this->option('s3-secret');
        }

        // Add DigitalOcean configuration if enabled
        if ($config['digitalocean_enabled']) {
            $config['do_bucket'] = $this->option('do-bucket');
            $config['do_region'] = $this->option('do-region');
            $config['do_key'] = $this->option('do-key');
            $config['do_secret'] = $this->option('do-secret');
        }

        // Add SMTP configuration if enabled
        if ($config['smtp_enabled']) {
            $config['smtp_host'] = $this->option('smtp-host');
            $config['smtp_port'] = $this->option('smtp-port');
            $config['smtp_username'] = $this->option('smtp-username');
            $config['smtp_password'] = $this->option('smtp-password');
            $config['smtp_encryption'] = $this->option('smtp-encryption');
            $config['smtp_from'] = $this->option('smtp-from');
        }

        return $config;
    }

    /**
     * Display configuration summary
     */
    protected function displayConfigurationSummary(array $config): void
    {
        $this->info('[INFO] Deployment Configuration Summary:');
        $this->info('');

        $tableRows = [
            ['Database Driver', $config['db_driver']],
        ];

        if ($config['db_driver'] !== 'sqlite') {
            $tableRows = array_merge($tableRows, [
                ['Database Host', $config['db_host']],
                ['Database Port', $config['db_port']],
                ['Database Name', $config['db_database']],
                ['Database User', $config['db_username']],
                ['Database Password', str_repeat('*', strlen($config['db_password']))],
            ]);
        } else {
            $tableRows[] = ['Database File', $config['db_database']];
        }

        $tableRows = array_merge($tableRows, [
            ['Admin Email', $config['admin_email']],
            ['Admin Password', str_repeat('*', strlen($config['admin_password']))],
            ['Site Name', $config['site_name']],
            ['Site URL', $config['site_url']],
            ['Custom App Key', $config['app_key'] ? 'Yes' : 'Will generate'],
            ['S3 Storage', $config['s3_enabled'] ? 'Enabled' : 'Disabled'],
            ['DigitalOcean Storage', $config['digitalocean_enabled'] ? 'Enabled' : 'Disabled'],
            ['SMTP Configuration', $config['smtp_enabled'] ? 'Enabled' : 'Disabled'],
            ['Lock Storage Settings', $config['lock_storage'] ? 'Yes' : 'No'],
            ['Skip Migrations', $config['skip_migration'] ? 'Yes' : 'No'],
        ]);

        $this->table(['Setting', 'Value'], $tableRows);

        if ($config['s3_enabled']) {
            $this->info('');
            $this->info('[INFO] S3 Configuration:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['S3 Bucket', $config['s3_bucket']],
                    ['S3 Region', $config['s3_region']],
                    ['S3 Access Key', substr($config['s3_key'], 0, 4).str_repeat('*', strlen($config['s3_key']) - 4)],
                    ['S3 Secret Key', str_repeat('*', strlen($config['s3_secret']))],
                ]
            );
        }

        if ($config['digitalocean_enabled']) {
            $this->info('');
            $this->info('[INFO] DigitalOcean Spaces Configuration:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['Space Name', $config['do_bucket']],
                    ['Region', $config['do_region']],
                    ['Access Key', substr($config['do_key'], 0, 4).str_repeat('*', strlen($config['do_key']) - 4)],
                    ['Secret Key', str_repeat('*', strlen($config['do_secret']))],
                    ['Endpoint', 'https://'.strtolower($config['do_region']).'.digitaloceanspaces.com'],
                ]
            );
        }

        if ($config['smtp_enabled']) {
            $this->info('[INFO] SMTP Configuration:');
            $this->table(
                ['Setting', 'Value'],
                [
                    ['SMTP Host', $config['smtp_host']],
                    ['SMTP Port', $config['smtp_port']],
                    ['SMTP Username', $config['smtp_username']],
                    ['SMTP Password', str_repeat('*', strlen($config['smtp_password']))],
                    ['SMTP Encryption', $config['smtp_encryption']],
                    ['From Address', $config['smtp_from']],
                ]
            );
        }
    }

    /**
     * Perform the deployment
     */
    protected function performDeployment(array $config): void
    {
        // Copy .env.example to .env if it doesn't exist
        $this->info('[INFO] Setting up environment configuration...');
        if (! file_exists(base_path('.env'))) {
            copy(base_path('.env.example'), base_path('.env'));
            $this->info('[SUCCESS] Created .env file from template');
        }

        // Update .env file with database configuration for connection testing
        $this->info('[INFO] Configuring database connection...');
        $envData = [
            'DB_CONNECTION' => $config['db_driver'],
        ];

        if ($config['db_driver'] === 'sqlite') {
            $envData['DB_DATABASE'] = $config['db_database'];

            // Create the database file if it doesn't exist
            if (! file_exists($config['db_database'])) {
                $dbDir = dirname($config['db_database']);
                if (! is_dir($dbDir)) {
                    mkdir($dbDir, 0755, true);
                }
                touch($config['db_database']);
                $this->info('[SUCCESS] SQLite database file created');
            }
        } else {
            $envData = array_merge($envData, [
                'DB_HOST' => $config['db_host'],
                'DB_PORT' => $config['db_port'],
                'DB_DATABASE' => $config['db_database'],
                'DB_USERNAME' => $config['db_username'],
                'DB_PASSWORD' => $config['db_password'],
            ]);
        }

        $this->updateEnv($envData);

        // Update the config repository manually for database connection
        $configData = [
            'database.default' => $config['db_driver'],
        ];

        if ($config['db_driver'] === 'sqlite') {
            $configData['database.connections.sqlite.database'] = $config['db_database'];
        } else {
            $configData = array_merge($configData, [
                "database.connections.{$config['db_driver']}.host" => $config['db_host'],
                "database.connections.{$config['db_driver']}.port" => $config['db_port'],
                "database.connections.{$config['db_driver']}.database" => $config['db_database'],
                "database.connections.{$config['db_driver']}.username" => $config['db_username'],
                "database.connections.{$config['db_driver']}.password" => $config['db_password'],
            ]);
        }

        config($configData);

        // Clear config cache
        $this->info('[INFO] Clearing configuration cache...');
        $this->call('config:clear');
        $this->info('[SUCCESS] Configuration cache cleared');

        // Test database connection and check if database exists
        $this->info('[INFO] Testing database connection...');
        $isUpdate = false;
        try {
            DB::connection()->getPdo();
            $this->info('[SUCCESS] Database connection successful');

            // Check if this is an existing database by looking for migrations table
            try {
                $tables = DB::select("SHOW TABLES LIKE 'migrations'");
                if (count($tables) > 0) {
                    $isUpdate = true;
                    $this->info('[INFO] Existing database detected - this is an update deployment');
                } else {
                    $this->info('[INFO] New database detected - this is a fresh deployment');
                }
            } catch (Exception $e) {
                // For SQLite or other databases, try a different approach
                try {
                    DB::table('migrations')->count();
                    $isUpdate = true;
                    $this->info('[INFO] Existing database detected - this is an update deployment');
                } catch (Exception $e) {
                    $this->info('[INFO] New database detected - this is a fresh deployment');
                }
            }
        } catch (Exception $e) {
            throw new Exception('Database connection failed: '.$e->getMessage());
        }

        $this->performMainDeployment($config, $isUpdate);
    }

    /**
     * Perform the main deployment steps
     */
    protected function performMainDeployment(array $config, bool $isUpdate): void
    {
        if ($isUpdate) {
            $this->info('[INFO] Performing update deployment...');
        } else {
            $this->info('[INFO] Performing fresh deployment...');
        }

        // Generate or set application key
        if ($config['app_key']) {
            $this->info('[INFO] Setting custom application key...');
            $this->updateEnv(['APP_KEY' => $config['app_key']]);
            // Clear config cache to reload APP_KEY
            $this->call('config:clear');
            $this->info('[SUCCESS] Custom application key set');
        } else {
            $this->info('[INFO] Generating application security key...');
            $this->call('key:generate');
            // Clear config cache to reload APP_KEY
            $this->call('config:clear');
            $this->info('[SUCCESS] Application key generated');
        }

        // Update remaining environment configuration
        $envData = [
            'APP_URL' => $config['site_url'],
            'APP_ENV' => 'production',
        ];

        // Only update site name for fresh deployments
        if (! $isUpdate) {
            $envData['APP_NAME'] = $config['site_name'];
        }

        // Configure S3 if enabled
        if ($config['s3_enabled']) {
            $this->info('[INFO] Configuring S3 storage...');
            $envData = array_merge($envData, [
                'FILESYSTEM_DISK' => 's3',
                'AWS_BUCKET' => $config['s3_bucket'],
                'AWS_DEFAULT_REGION' => $config['s3_region'],
                'AWS_ACCESS_KEY_ID' => $config['s3_key'],
                'AWS_SECRET_ACCESS_KEY' => $config['s3_secret'],
            ]);
            $this->info('[SUCCESS] S3 storage configured');
        }

        // Configure DigitalOcean Spaces if enabled
        if ($config['digitalocean_enabled']) {
            $this->info('[INFO] Configuring DigitalOcean Spaces storage...');
            $endpoint = 'https://'.strtolower($config['do_region']).'.digitaloceanspaces.com';
            $envData = array_merge($envData, [
                'FILESYSTEM_DISK' => 'digitalocean',
                'DO_SPACES_KEY' => $config['do_key'],
                'DO_SPACES_SECRET' => $config['do_secret'],
                'DO_SPACES_REGION' => 'us-east-1', // AWS SDK compatibility
                'DO_SPACES_BUCKET' => $config['do_bucket'],
                'DO_SPACES_ENDPOINT' => $endpoint,
                'DO_SPACES_USE_PATH_STYLE' => 'true',
            ]);
            $this->info('[SUCCESS] DigitalOcean Spaces storage configured');
        }

        // Configure SMTP if enabled
        if ($config['smtp_enabled']) {
            $this->info('[INFO] Configuring SMTP...');
            $envData = array_merge($envData, [
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => $config['smtp_host'],
                'MAIL_PORT' => $config['smtp_port'],
                'MAIL_USERNAME' => $config['smtp_username'],
                'MAIL_PASSWORD' => $config['smtp_password'],
                'MAIL_ENCRYPTION' => $config['smtp_encryption'] !== 'none' ? $config['smtp_encryption'] : 'null',
                'MAIL_FROM_ADDRESS' => $config['smtp_from'],
                'MAIL_FROM_NAME' => $config['site_name'],
            ]);
            $this->info('[SUCCESS] SMTP configured');
        }

        $this->updateEnv($envData);
        $this->info('[SUCCESS] Environment configuration updated');

        // Update app environment config
        config(['app.env' => 'production']);

        // Run migrations (skip if --skip-migration is provided)
        if ($config['skip_migration']) {
            $this->info('[INFO] Skipping database migrations and seeding (--skip-migration flag provided)');
        } else {
            if ($isUpdate) {
                $this->info('[INFO] Running database migrations...');
            } else {
                $this->info('[INFO] Creating database tables...');
            }
            $this->call('migrate', ['--force' => true]);
            $this->info('[SUCCESS] Database migrations completed');

            // Skip user creation and role seeding for updates, but ensure settings are initialized
            if (! $isUpdate) {
                // Create admin user
                $this->info('[INFO] Creating admin user...');
                $this->call('fynix:create-user', [
                    'email' => $config['admin_email'],
                    'password' => $config['admin_password'],
                ]);
                $this->info('[SUCCESS] Admin user created');

                // Seed database
                $this->info('[INFO] Seeding database with defaults...');
                $this->call('db:seed', ['--class' => 'SettingsSeeder', '--force' => true]);
                $this->call('db:seed', ['--class' => 'RolePermissionSeeder', '--force' => true]);
                $this->info('[SUCCESS] Database seeded');
            } else {
                $this->info('[INFO] Skipping user creation for update deployment');

                // Ensure settings and roles are properly initialized for updates
                $this->info('[INFO] Ensuring settings are initialized...');
                $this->call('db:seed', ['--class' => 'SettingsSeeder', '--force' => true]);
                $this->info('[SUCCESS] Settings initialized');
            }
        }

        // Set site configuration
        $this->info('[INFO] Configuring site settings...');

        // Only update site name for fresh deployments
        if (! $isUpdate) {
            $this->call('settings:set', [
                'key' => 'general.name',
                'value' => $config['site_name'],
            ]);
        }

        $this->call('settings:set', [
            'key' => 'general.url',
            'value' => $config['site_url'],
        ]);

        // Configure storage settings
        if ($config['s3_enabled']) {
            $this->call('settings:set', [
                'key' => 'storage.driver',
                'value' => 's3',
            ]);
            $this->call('settings:set', [
                'key' => 'storage.s3.bucket',
                'value' => $config['s3_bucket'],
            ]);
            $this->call('settings:set', [
                'key' => 'storage.s3.region',
                'value' => $config['s3_region'],
            ]);
            $this->call('settings:set', [
                'key' => 'storage.s3.key',
                'value' => Crypt::encryptString($config['s3_key']),
            ]);
            $this->call('settings:set', [
                'key' => 'storage.s3.secret',
                'value' => Crypt::encryptString($config['s3_secret']),
            ]);
            $this->info('[SUCCESS] S3 storage settings configured');
        } elseif ($config['digitalocean_enabled']) {
            $this->call('settings:set', [
                'key' => 'storage.driver',
                'value' => 'digitalocean',
            ]);
            $this->call('settings:set', [
                'key' => 'storage.digitalocean.bucket',
                'value' => $config['do_bucket'],
            ]);
            $this->call('settings:set', [
                'key' => 'storage.digitalocean.region',
                'value' => $config['do_region'],
            ]);
            $this->call('settings:set', [
                'key' => 'storage.digitalocean.key',
                'value' => Crypt::encryptString($config['do_key']),
            ]);
            $this->call('settings:set', [
                'key' => 'storage.digitalocean.secret',
                'value' => Crypt::encryptString($config['do_secret']),
            ]);
            $this->info('[SUCCESS] DigitalOcean Spaces storage settings configured');
        } else {
            $this->call('settings:set', [
                'key' => 'storage.driver',
                'value' => 'local',
            ]);
        }

        // Configure SMTP settings if enabled
        if ($config['smtp_enabled']) {
            $this->call('settings:set', [
                'key' => 'mail.host',
                'value' => $config['smtp_host'],
            ]);
            $this->call('settings:set', [
                'key' => 'mail.port',
                'value' => $config['smtp_port'],
            ]);
            $this->call('settings:set', [
                'key' => 'mail.username',
                'value' => $config['smtp_username'],
            ]);
            $this->call('settings:set', [
                'key' => 'mail.password',
                'value' => Crypt::encryptString($config['smtp_password']),
            ]);
            $this->call('settings:set', [
                'key' => 'mail.encryption',
                'value' => $config['smtp_encryption'],
            ]);
            $this->call('settings:set', [
                'key' => 'mail.from',
                'value' => $config['smtp_from'],
            ]);
            $this->info('[SUCCESS] SMTP settings configured');
        }

        // Set storage lock setting
        $this->call('settings:set', [
            'key' => 'storage.locked',
            'value' => $config['lock_storage'] ? 'true' : 'false',
        ]);

        $this->info('[SUCCESS] Site settings configured');

        $this->performCommonDeploymentSteps();
    }

    /**
     * Perform common deployment steps (for both fresh and update deployments)
     */
    protected function performCommonDeploymentSteps(): void
    {
        // Link storage
        $this->info('[INFO] Linking public storage...');
        $this->call('storage:link');
        $this->info('[SUCCESS] Storage linked');

        // Build assets (skip in containerized environments where assets are pre-built)
        if (file_exists(base_path('node_modules'))) {
            $this->info('[INFO] Building front-end assets...');
            exec('npm install && npm run build', $output, $returnCode);
            if ($returnCode === 0) {
                $this->info('[SUCCESS] Front-end assets built');
            } else {
                $this->warn('[WARNING] Asset building may have failed. Check manually.');
            }
        } else {
            $this->info('[INFO] Skipping asset build (pre-built assets detected)');
        }

        // Set production permissions (skip in containerized environments)
        // Detect container environment: no node_modules means pre-built Docker image
        $isContainer = ! file_exists(base_path('node_modules'));

        if (PHP_OS === 'Linux' && ! $isContainer) {
            $this->info('[INFO] Setting file permissions...');

            // Check if set_permissions script exists and run it
            if (file_exists(base_path('set_permissions'))) {
                exec('./set_permissions', $output, $returnCode);
                if ($returnCode === 0) {
                    $this->info('[SUCCESS] File permissions set using set_permissions script');
                } else {
                    $this->warn('[WARNING] set_permissions script failed, falling back to manual permissions');
                    $this->setManualPermissions();
                }
            } else {
                $this->info('[INFO] set_permissions script not found, setting manual permissions');
                $this->setManualPermissions();
            }
        } else {
            $this->info('[INFO] Skipping permission setting (containerized environment - permissions set at build time)');
        }
    }

    /**
     * Display success message
     */
    protected function displaySuccess(): void
    {
        $this->info('');
        $this->info('[SUCCESS] ════════════════════════════════════════════════════════════════');
        $this->info('[SUCCESS]  Fynix Cyber Audit has been successfully deployed!');
        $this->info('[SUCCESS] ════════════════════════════════════════════════════════════════');
        $this->info('');
        $this->info('[INFO] Next Steps:');
        $this->info('   • Configure your web server to point to the public/ directory');
        $this->info('   • Set up SSL certificates for HTTPS');
        $this->info('   • Configure backup procedures for your database');
        $this->info('   • Review and adjust file permissions as needed');
        $this->info('   • Set up monitoring and log rotation');
        $this->info('');
        $this->info('[INFO] Access your Fynix Cyber Audit installation at: '.$this->option('site-url'));
        $this->info('[INFO] Login with: '.$this->option('admin-email'));
        $this->info('');
    }

    /**
     * Set manual file permissions as fallback
     */
    protected function setManualPermissions(): void
    {
        exec('find storage -type f -exec chmod 644 {} \;');
        exec('find storage -type d -exec chmod 755 {} \;');
        exec('find bootstrap/cache -type f -exec chmod 644 {} \;');
        exec('find bootstrap/cache -type d -exec chmod 755 {} \;');
        $this->info('[SUCCESS] Manual file permissions set');
    }

    /**
     * Update the .env file with the given key-value pairs.
     */
    protected function updateEnv(array $data): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        foreach ($data as $key => $value) {
            // Escape special characters in the value
            $escapedValue = addslashes($value);

            // Check if the key already exists
            if (preg_match("/^{$key}=/m", $envContent)) {
                // Update existing key
                $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$escapedValue}\"", $envContent);
            } else {
                // Add new key at the end
                $envContent .= "\n{$key}=\"{$escapedValue}\"";
            }
        }

        file_put_contents($envPath, $envContent);
    }
}
