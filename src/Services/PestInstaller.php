<?php
namespace Laravel\Installer\Services;

use Illuminate\Filesystem\Filesystem;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PestInstaller
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly CommandRunner $commandRunner,
        private readonly PhpBinaryLocator $phpBinary,
        private readonly GitService $gitService
    ) {}

    public function install(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        $composer = $this->getComposerBinary();
        $php = $this->phpBinary->find();

        $commands = [
            "{$composer} remove phpunit/phpunit --dev --no-update",
            "{$composer} require pestphp/pest pestphp/pest-plugin-laravel --no-update --dev",
            "{$composer} update",
            "{$php} ./vendor/bin/pest --init",
            "{$composer} require pestphp/pest-plugin-drift --dev",
            "{$php} ./vendor/bin/pest --drift",
            "{$composer} remove pestphp/pest-plugin-drift --dev",
        ];

        $this->commandRunner->runWithOutput(
            $commands,
            $input,
            $output,
            $config->directory,
            ['PEST_NO_SUPPORT' => 'true']
        );

        if ($config->isUsingStarterKit()) {
            $this->configureStarterKit($config);
        }

        if ($config->useGit) {
            $this->gitService->commitChanges('Install Pest', $config->directory);
        }
    }

    private function configureStarterKit(ProjectConfiguration $config): void
    {
        // Update GitHub workflows
        $workflowPath = $config->directory . '/.github/workflows/tests.yml';
        if ($this->files->exists($workflowPath)) {
            $this->replaceInFile(
                './vendor/bin/phpunit',
                './vendor/bin/pest',
                $workflowPath
            );
        }

        // Enable RefreshDatabase in Pest.php
        $pestPath = $config->directory . '/tests/Pest.php';
        if ($this->files->exists($pestPath)) {
            $contents = $this->files->get($pestPath);
            $contents = str_replace(
                ' // ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)',
                '    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)',
                $contents
            );
            $this->files->put($pestPath, $contents);
        }

        // Remove RefreshDatabase from individual test files
        $this->removeRefreshDatabaseFromTests($config->directory . '/tests');
    }

    private function removeRefreshDatabaseFromTests(string $testsDirectory): void
    {
        if (!$this->files->isDirectory($testsDirectory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsDirectory)
        );

        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = $this->files->get($file->getPathname());
            $updated = str_replace(
                "\n\nuses(\Illuminate\Foundation\Testing\RefreshDatabase::class);",
                '',
                $contents
            );

            if ($contents !== $updated) {
                $this->files->put($file->getPathname(), $updated);
            }
        }
    }

    private function replaceInFile(string $search, string $replace, string $file): void
    {
        $contents = $this->files->get($file);
        $this->files->put($file, str_replace($search, $replace, $contents));
    }

    private function getComposerBinary(): string
    {
        $composer = new \Illuminate\Support\Composer($this->files, getcwd());
        return implode(' ', $composer->findComposer());
    }
}
