<?php
namespace Laravel\Installer\Services;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectBuilder
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly CommandRunner $commandRunner,
        private readonly PhpBinaryLocator $phpBinary
    ) {}

    public function build(ProjectConfiguration $config): void
    {
        $commands = $this->buildCreationCommands($config);

        $this->commandRunner->run(
            $commands,
            workingPath: $config->directory !== '.' ? null : getcwd(),
            env: []
        );
    }

    public function installAndBuildAssets(
        ProjectConfiguration $config,
        InputInterface $input,
        OutputInterface $output
    ): void {
        if (!$config->packageManager) {
            return;
        }

        $commands = [
            $config->packageManager->installCommand(),
            $config->packageManager->buildCommand()
        ];

        $this->commandRunner->runWithOutput(
            $commands,
            $input,
            $output,
            $config->directory
        );
    }

    private function buildCreationCommands(ProjectConfiguration $config): array
    {
        $composer = $this->getComposerBinary();
        $phpBinary = $this->phpBinary->find();
        $directory = escapeshellarg($config->directory);

        $commands = [];

        // Handle force deletion
        if ($config->directory !== '.' && $config->force) {
            $commands[] = $this->getDeleteCommand($config->directory);
        }

        // Create project command
        $commands[] = $this->buildCreateProjectCommand($config, $composer, $directory);

        // Post-installation commands
        $commands[] = "{$composer} run post-root-package-install -d {$directory}";
        $commands[] = "{$phpBinary} {$directory}/artisan key:generate --ansi";

        // Set permissions on Unix systems
        if (PHP_OS_FAMILY !== 'Windows') {
            $commands[] = "chmod 755 {$directory}/artisan";
        }

        return $commands;
    }

    private function buildCreateProjectCommand(
        ProjectConfiguration $config,
        string $composer,
        string $directory
    ): string {
        if (!$config->starterKit) {
            return "{$composer} create-project laravel/laravel {$directory} {$config->version} --remove-vcs --prefer-dist --no-scripts";
        }

        $command = "{$composer} create-project {$config->starterKit} {$directory} --stability=dev";

        // Handle Laravel starter kit variants
        if ($config->isUsingLaravelStarterKit()) {
            if ($config->useLivewireClassComponents) {
                $command = str_replace(
                    " {$config->starterKit} ",
                    " {$config->starterKit}:dev-components ",
                    $command
                );
            }

            if ($config->useWorkOS) {
                $command = str_replace(
                    " {$config->starterKit} ",
                    " {$config->starterKit}:dev-workos ",
                    $command
                );
            }
        }

        // Handle external starter kits (URLs)
        if (!$config->isUsingLaravelStarterKit() && str_contains($config->starterKit, '://')) {
            return "npx tiged@latest {$config->starterKit} {$directory} && cd {$directory} && {$composer} install";
        }

        return $command;
    }

    private function getDeleteCommand(string $directory): string
    {
        $escapedDir = escapeshellarg($directory);

        return PHP_OS_FAMILY === 'Windows'
            ? "(if exist {$escapedDir} rd /s /q {$escapedDir})"
            : "rm -rf {$escapedDir}";
    }

    private function getComposerBinary(): string
    {
        $composer = new Composer($this->files, getcwd());
        return implode(' ', $composer->findComposer());
    }
}
