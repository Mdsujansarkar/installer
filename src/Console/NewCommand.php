<?php

// File: src/Console/NewCommand.php
namespace Laravel\Installer\Console;

use Illuminate\Support\ProcessUtils;
use Laravel\Installer\Console\Concerns\ConfiguresPrompts;
use Laravel\Installer\Console\Concerns\InteractsWithHerdOrValet;
use Laravel\Installer\Console\Enums\NodePackageManager;
use Laravel\Installer\Services\DatabaseConfigurator;
use Laravel\Installer\Services\GitService;
use Laravel\Installer\Services\GitHubService;
use Laravel\Installer\Services\PackageManagerDetector;
use Laravel\Installer\Services\PestInstaller;
use Laravel\Installer\Services\ProjectBuilder;
use Laravel\Installer\Services\VersionChecker;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use ConfiguresPrompts;
    use InteractsWithHerdOrValet;

    private const DATABASE_DRIVERS = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'];
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly ProjectBuilder $projectBuilder,
        private readonly DatabaseConfigurator $databaseConfigurator,
        private readonly GitService $gitService,
        private readonly GitHubService $githubService,
        private readonly PackageManagerDetector $packageManagerDetector,
        private readonly PestInstaller $pestInstaller,
        private readonly VersionChecker $versionChecker,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Laravel application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Install the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->getDefaultBranch())
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, 'The database driver your application will use. Possible values are: ' . implode(', ', self::DATABASE_DRIVERS))
            ->addOption('react', null, InputOption::VALUE_NONE, 'Install the React Starter Kit')
            ->addOption('vue', null, InputOption::VALUE_NONE, 'Install the Vue Starter Kit')
            ->addOption('livewire', null, InputOption::VALUE_NONE, 'Install the Livewire Starter Kit')
            ->addOption('livewire-class-components', null, InputOption::VALUE_NONE, 'Generate stand-alone Livewire class components')
            ->addOption('workos', null, InputOption::VALUE_NONE, 'Use WorkOS for authentication')
            ->addOption('no-authentication', null, InputOption::VALUE_NONE, 'Do not generate authentication scaffolding')
            ->addOption('pest', null, InputOption::VALUE_NONE, 'Install the Pest testing framework')
            ->addOption('phpunit', null, InputOption::VALUE_NONE, 'Install the PHPUnit testing framework')
            ->addOption('npm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies')
            ->addOption('pnpm', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via PNPM')
            ->addOption('bun', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Bun')
            ->addOption('yarn', null, InputOption::VALUE_NONE, 'Install and build NPM dependencies via Yarn')
            ->addOption('using', null, InputOption::VALUE_OPTIONAL, 'Install a custom starter kit from a community maintained package')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);
        $this->displayBanner($output);
        $this->ensureExtensionsAreAvailable();
        $this->checkForUpdates($input, $output);

        $this->promptForProjectName($input);
        $this->verifyProjectDirectory($input);
        $this->promptForStarterKit($input);
        $this->promptForTestingFramework($input);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->buildConfiguration($input, $output);

            $this->projectBuilder->build($config);

            $this->configureDatabase($config, $input, $output);
            $this->initializeVersionControl($config, $input, $output);
            $this->installTestingFramework($config, $input, $output);
            $this->setupPackageManager($config, $input, $output);

            $this->displaySuccessMessage($config, $output);

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            $this->logger->error('Project creation failed', [
                'exception' => $e,
                'project' => $input->getArgument('name')
            ]);

            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function displayBanner(OutputInterface $output): void
    {
        $output->write(PHP_EOL . '  <fg=red> _                               _
  | |                             | |
  | |     __ _ _ __ __ ___   _____| |
  | |    / _` |  __/ _` \ \ / / _ \ |
  | |___| (_| | | | (_| |\ V /  __/ |
  |______\__,_|_|  \__,_| \_/ \___|_|</>' . PHP_EOL . PHP_EOL);
    }

    private function ensureExtensionsAreAvailable(): void
    {
        $required = ['ctype', 'filter', 'hash', 'mbstring', 'openssl', 'session', 'tokenizer'];
        $available = get_loaded_extensions();
        $missing = array_diff($required, $available);

        if (!empty($missing)) {
            throw new RuntimeException(
                'The following PHP extensions are required but are not installed: ' .
                implode(', ', $missing)
            );
        }
    }

    private function checkForUpdates(InputInterface $input, OutputInterface $output): void
    {
        try {
            $this->versionChecker->checkAndPromptForUpdate($input, $output);
        } catch (\Exception $e) {
            $this->logger->warning('Version check failed', ['exception' => $e]);
            // Continue without blocking installation
        }
    }

    private function promptForProjectName(InputInterface $input): void
    {
        if ($input->getArgument('name')) {
            return;
        }

        $input->setArgument('name', text(
            label: 'What is the name of your project?',
            placeholder: 'E.g. example-app',
            required: 'The project name is required.',
            validate: fn($value) => $this->validateProjectName($value, $input)
        ));
    }

    private function validateProjectName(string $name, InputInterface $input): ?string
    {
        if (preg_match('/[^\pL\pN\-_.]/', $name) !== 0) {
            return 'The name may only contain letters, numbers, dashes, underscores, and periods.';
        }

        if ($input->getOption('force')) {
            return null;
        }

        $directory = $this->getInstallationDirectory($name);
        if ($this->projectExists($directory)) {
            return 'Application already exists.';
        }

        return null;
    }

    private function verifyProjectDirectory(InputInterface $input): void
    {
        if ($input->getOption('force')) {
            $directory = $this->getInstallationDirectory($input->getArgument('name'));
            if ($directory === '.') {
                throw new RuntimeException('Cannot use --force option when using current directory for installation!');
            }
            return;
        }

        $directory = $this->getInstallationDirectory($input->getArgument('name'));
        if ($this->projectExists($directory)) {
            throw new RuntimeException('Application already exists!');
        }
    }

    private function promptForStarterKit(InputInterface $input): void
    {
        if ($this->hasStarterKitOption($input)) {
            return;
        }

        $kit = select(
            label: 'Which starter kit would you like to install?',
            options: [
                'none' => 'None',
                'react' => 'React',
                'vue' => 'Vue',
                'livewire' => 'Livewire',
            ],
            default: 'none',
        );

        match ($kit) {
            'react' => $input->setOption('react', true),
            'vue' => $input->setOption('vue', true),
            'livewire' => $input->setOption('livewire', true),
            default => null,
        };

        if ($this->isUsingLaravelStarterKit($input)) {
            $this->promptForAuthentication($input);
        }

        if ($this->shouldPromptForVolt($input)) {
            $input->setOption('livewire-class-components', !confirm(
                label: 'Would you like to use Laravel Volt?',
                default: true,
            ));
        }
    }

    private function promptForAuthentication(InputInterface $input): void
    {
        $auth = select(
            label: 'Which authentication provider do you prefer?',
            options: [
                'laravel' => "Laravel's built-in authentication",
                'workos' => 'WorkOS (Requires WorkOS account)',
                'none' => 'No authentication scaffolding',
            ],
            default: 'laravel',
        );

        match ($auth) {
            'laravel' => $input->setOption('workos', false),
            'workos' => $input->setOption('workos', true),
            'none' => $input->setOption('no-authentication', true),
            default => null,
        };
    }

    private function promptForTestingFramework(InputInterface $input): void
    {
        if ($input->getOption('phpunit') || $input->getOption('pest')) {
            return;
        }

        $framework = select(
            label: 'Which testing framework do you prefer?',
            options: ['Pest', 'PHPUnit'],
            default: 'Pest',
        );

        $input->setOption('pest', $framework === 'Pest');
    }

    private function buildConfiguration(InputInterface $input, OutputInterface $output): ProjectConfiguration
    {
        $this->validateDatabaseDriver($input);

        $name = rtrim($input->getArgument('name'), '/\\');
        $directory = $this->getInstallationDirectory($name);

        return new ProjectConfiguration(
            name: $name,
            directory: $directory,
            version: $this->getVersion($input),
            starterKit: $this->getStarterKit($input),
            database: $input->getOption('database') ?? 'sqlite',
            shouldMigrate: false, // Will be determined later
            useGit: $input->getOption('git') || $input->getOption('github') !== false,
            gitBranch: $input->getOption('branch') ?: $this->getDefaultBranch(),
            useGitHub: $input->getOption('github') !== false,
            githubOrganization: $input->getOption('organization'),
            githubFlags: $input->getOption('github') ?: '--private',
            usePest: $input->getOption('pest'),
            packageManager: null, // Will be determined later
            shouldRunPackageManager: false,
            force: $input->getOption('force'),
            isDev: $input->getOption('dev'),
            isInteractive: $input->isInteractive(),
            useLivewireClassComponents: $input->getOption('livewire-class-components'),
            useWorkOS: $input->getOption('workos'),
        );
    }

    private function configureDatabase(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        if ($config->directory === '.') {
            return;
        }

        $this->databaseConfigurator->configure($config, $input, $output);
    }

    private function initializeVersionControl(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        if (!$config->useGit) {
            return;
        }

        $this->gitService->initialize($config, $output);

        if ($config->useGitHub) {
            $this->githubService->createAndPush($config, $output);
            $output->writeln('');
        }
    }

    private function installTestingFramework(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        if (!$config->usePest) {
            return;
        }

        $this->pestInstaller->install($config, $input, $output);
        $output->writeln('');
    }

    private function setupPackageManager(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        $selection = $this->packageManagerDetector->detect($config->directory, $input);

        $config->packageManager = $selection->manager;
        $config->shouldRunPackageManager = $selection->shouldRun;

        if (!$selection->shouldRun && $config->isInteractive) {
            $config->shouldRunPackageManager = confirm(
                label: 'Would you like to run <options=bold>' .
                $selection->manager->installCommand() .
                '</> and <options=bold>' .
                $selection->manager->buildCommand() .
                '</>?'
            );
        }

        $this->packageManagerDetector->cleanupLockFiles($config);

        if ($config->shouldRunPackageManager) {
            $this->projectBuilder->installAndBuildAssets($config, $input, $output);
        }
    }

    private function displaySuccessMessage(ProjectConfiguration $config, OutputInterface $output): void
    {
        $output->writeln(
            "  <bg=blue;fg=white> INFO </> Application ready in <options=bold>[{$config->name}]</>. " .
            "You can start your local development using:" . PHP_EOL
        );

        $output->writeln('<fg=gray>➜</> <options=bold>cd ' . $config->name . '</>');

        if (!$config->shouldRunPackageManager && $config->packageManager) {
            $output->writeln(
                '<fg=gray>➜</> <options=bold>' .
                $config->packageManager->installCommand() . ' && ' .
                $config->packageManager->buildCommand() .
                '</>'
            );
        }

        if ($this->isParkedOnHerdOrValet($config->directory)) {
            $url = $this->generateAppUrl($config->name, $config->directory);
            $output->writeln('<fg=gray>➜</> Open: <options=bold;href=' . $url . '>' . $url . '</>');
        } else {
            $output->writeln('<fg=gray>➜</> <options=bold>composer run dev</>');
        }

        $output->writeln('');
        $output->writeln('  New to Laravel? Check out our <href=https://laravel.com/docs/installation#next-steps>documentation</>. <options=bold>Build something amazing!</>');
        $output->writeln('');
    }

    // Helper methods

    private function getDefaultBranch(): string
    {
        return $this->gitService->getDefaultBranch();
    }

    private function projectExists(string $directory): bool
    {
        return (is_dir($directory) || is_file($directory)) && $directory !== getcwd();
    }

    private function hasStarterKitOption(InputInterface $input): bool
    {
        return $input->getOption('react')
            || $input->getOption('vue')
            || $input->getOption('livewire')
            || $input->getOption('using');
    }

    private function isUsingLaravelStarterKit(InputInterface $input): bool
    {
        $kit = $this->getStarterKit($input);
        return $kit && str_starts_with($kit, 'laravel/');
    }

    private function shouldPromptForVolt(InputInterface $input): bool
    {
        return $input->getOption('livewire')
            && !$input->getOption('workos')
            && !$input->getOption('no-authentication');
    }

    private function getStarterKit(InputInterface $input): ?string
    {
        if ($input->getOption('no-authentication')) {
            return match (true) {
                $input->getOption('react') => 'laravel/blank-react-starter-kit',
                $input->getOption('vue') => 'laravel/blank-vue-starter-kit',
                $input->getOption('livewire') => 'laravel/blank-livewire-starter-kit',
                default => $input->getOption('using'),
            };
        }

        return match (true) {
            $input->getOption('react') => 'laravel/react-starter-kit',
            $input->getOption('vue') => 'laravel/vue-starter-kit',
            $input->getOption('livewire') => 'laravel/livewire-starter-kit',
            default => $input->getOption('using'),
        };
    }

    private function validateDatabaseDriver(InputInterface $input): void
    {
        $database = $input->getOption('database');

        if ($database && !in_array($database, self::DATABASE_DRIVERS, true)) {
            throw new \InvalidArgumentException(
                "Invalid database driver [{$database}]. Possible values are: " .
                implode(', ', self::DATABASE_DRIVERS) . '.'
            );
        }
    }

    private function getInstallationDirectory(string $name): string
    {
        return $name !== '.' ? getcwd() . '/' . $name : '.';
    }

    private function getVersion(InputInterface $input): string
    {
        return $input->getOption('dev') ? 'dev-master' : '';
    }

    private function generateAppUrl(string $name, string $directory): string
    {
        if (!$this->isParkedOnHerdOrValet($directory)) {
            return 'http://localhost:8000';
        }

        $hostname = mb_strtolower($name) . '.' . $this->getTld();

        return $this->canResolveHostname($hostname)
            ? 'http://' . $hostname
            : 'http://localhost';
    }

    private function getTld(): string
    {
        return $this->runOnValetOrHerd('tld') ?: 'test';
    }

    private function canResolveHostname(string $hostname): bool
    {
        return gethostbyname($hostname . '.') !== $hostname . '.';
    }
}
