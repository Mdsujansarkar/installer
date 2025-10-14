<?php
namespace Laravel\Installer\Services;

use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class GitService
{
    public function __construct(
        private readonly CommandRunner $commandRunner
    ) {}

    public function getDefaultBranch(): string
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);
        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    public function initialize(ProjectConfiguration $config, OutputInterface $output): void
    {
        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Set up a fresh Laravel app"',
            "git branch -M {$config->gitBranch}",
        ];

        $this->commandRunner->runSilent($commands, $config->directory);
    }

    public function commitChanges(string $message, string $directory): void
    {
        $commands = [
            'git add .',
            "git commit -q -m \"{$message}\"",
        ];

        $this->commandRunner->runSilent($commands, $directory);
    }
}
