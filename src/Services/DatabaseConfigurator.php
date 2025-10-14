<?php
namespace Laravel\Installer\Services;

use Illuminate\Filesystem\Filesystem;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class DatabaseConfigurator
{
    private const DEFAULT_PORTS = [
        'pgsql' => '5432',
        'sqlsrv' => '1433',
    ];

    public function __construct(
        private readonly Filesystem $files,
        private readonly CommandRunner $commandRunner,
        private readonly PhpBinaryLocator $phpBinary
    ) {}

    public function configure(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        [$database, $shouldMigrate] = $this->promptForDatabaseOptions($config, $input);

        $this->replaceInFile(
            'APP_URL=http://localhost',
            'APP_URL=' . $this->generateAppUrl($config),
            $config->directory . '/.env'
        );

        $this->configureConnection($config->directory, $database, $config->name);

        if ($shouldMigrate) {
            $this->runMigrations($config, $database, $input, $output);
        }
    }

    private function promptForDatabaseOptions(
        ProjectConfiguration $config,
        InputInterface $input
    ): array {
        $databaseOptions = $this->getAvailableDatabases();
        $defaultDatabase = array_key_first($databaseOptions);

        // Starter kits handle their own migrations
        if ($config->isUsingStarterKit()) {
            $input->setOption('database', $config->database);
            return [$config->database, false];
        }

        if (!$config->database && $config->isInteractive) {
            $selected = select(
                label: 'Which database will your application use?',
                options: $databaseOptions,
                default: $defaultDatabase
            );

            $input->setOption('database', $selected);

            $shouldMigrate = $selected === 'sqlite'
                ? true
                : confirm(label: 'Would you like to run the default database migrations?');

            return [$selected, $shouldMigrate];
        }

        return [$config->database ?: $defaultDatabase, $input->hasOption('database')];
    }

    private function configureConnection(string $directory, string $database, string $name): void
    {
        // Update database connection
        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION=' . $database,
            $directory . '/.env'
        );

        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION=' . $database,
            $directory . '/.env.example'
        );

        if ($database === 'sqlite') {
            $this->configureSqlite($directory);
            return;
        }

        $this->configureNonSqlite($directory, $database, $name);
    }

    private function configureSqlite(string $directory): void
    {
        $environment = $this->files->get($directory . '/.env');

        // Comment out unused database options for SQLite
        if (!str_contains($environment, '# DB_HOST=127.0.0.1')) {
            $defaults = [
                'DB_HOST=127.0.0.1',
                'DB_PORT=3306',
                'DB_DATABASE=laravel',
                'DB_USERNAME=root',
                'DB_PASSWORD=',
            ];

            $commented = array_map(fn($default) => "# {$default}", $defaults);

            $this->replaceInFile($defaults, $commented, $directory . '/.env');
            $this->replaceInFile($defaults, $commented, $directory . '/.env.example');
        }
    }

    private function configureNonSqlite(string $directory, string $database, string $name): void
    {
        // Uncomment database configuration
        $defaults = [
            '# DB_HOST=127.0.0.1',
            '# DB_PORT=3306',
            '# DB_DATABASE=laravel',
            '# DB_USERNAME=root',
            '# DB_PASSWORD=',
        ];

        $uncommented = array_map(fn($default) => substr($default, 2), $defaults);

        $this->replaceInFile($defaults, $uncommented, $directory . '/.env');
        $this->replaceInFile($defaults, $uncommented, $directory . '/.env.example');

        // Update port if needed
        if (isset(self::DEFAULT_PORTS[$database])) {
            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT=' . self::DEFAULT_PORTS[$database],
                $directory . '/.env'
            );

            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT=' . self::DEFAULT_PORTS[$database],
                $directory . '/.env.example'
            );
        }

        // Update database name
        $dbName = str_replace('-', '_', strtolower($name));
        $this->replaceInFile('DB_DATABASE=laravel', "DB_DATABASE={$dbName}", $directory . '/.env');
        $this->replaceInFile('DB_DATABASE=laravel', "DB_DATABASE={$dbName}", $directory . '/.env.example');
    }

    private function runMigrations(
        ProjectConfiguration $config,
        string $database,
        InputInterface $input,
        OutputInterface $output
    ): void {
        if ($database === 'sqlite') {
            $dbPath = $config->directory . '/database/database.sqlite';
            if (!$this->files->exists($dbPath)) {
                $this->files->put($dbPath, '');
            }
        }

        $commands = [
            trim(sprintf(
                $this->phpBinary->find() . ' artisan migrate %s',
                !$config->isInteractive ? '--no-interaction' : ''
            ))
        ];

        $this->commandRunner->runWithOutput($commands, $input, $output, $config->directory);
    }

    private function getAvailableDatabases(): array
    {
        $databases = [
            'sqlite' => ['SQLite', extension_loaded('pdo_sqlite')],
            'mysql' => ['MySQL', extension_loaded('pdo_mysql')],
            'mariadb' => ['MariaDB', extension_loaded('pdo_mysql')],
            'pgsql' => ['PostgreSQL', extension_loaded('pdo_pgsql')],
            'sqlsrv' => ['SQL Server', extension_loaded('pdo_sqlsrv')],
        ];

        uasort($databases, fn($a, $b) => $b[1] <=> $a[1]);

        return array_map(
            fn($db) => $db[0] . ($db[1] ? '' : ' (Missing PDO extension)'),
            $databases
        );
    }

    private function generateAppUrl(ProjectConfiguration $config): string
    {
        // This would integrate with Herd/Valet detection
        return 'http://localhost:8000';
    }

    private function replaceInFile(string|array $search, string|array $replace, string $file): void
    {
        $contents = $this->files->get($file);
        $this->files->put($file, str_replace($search, $replace, $contents));
    }

    private function pregReplaceInFile(string $pattern, string $replace, string $file): void
    {
        $contents = $this->files->get($file);
        $this->files->put($file, preg_replace($pattern, $replace, $contents));
    }
}
