<?php

namespace Laravel\Installer\Services;

use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class GitHubService
{
    public function __construct(
        private readonly CommandRunner $commandRunner
    ) {}

    public function createAndPush(ProjectConfiguration $config, OutputInterface $output): void
    {
        if (!$this->isAuthenticated()) {
            $output->writeln(
                '  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed ' .
                'and that you\'re authenticated to GitHub. Skipping...' . PHP_EOL
            );
            return;
        }

        $name = $config->githubOrganization
            ? "{$config->githubOrganization}/{$config->name}"
            : $config->name;

        $commands = [
            "gh repo create {$name} --source=. --push {$config->githubFlags}"
        ];

        $this->commandRunner->runSilent($commands, $config->directory, ['GIT_TERMINAL_PROMPT' => '0']);
    }

    private function isAuthenticated(): bool
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        return $process->isSuccessful();
    }
}
