<?php
namespace Laravel\Installer\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CommandRunner
{
    public function run(
        array $commands,
        ?string $workingPath = null,
        array $env = []
    ): void {
        $process = $this->createProcess($commands, $workingPath, $env);
        $process->mustRun();
    }

    public function runSilent(
        array $commands,
        ?string $workingPath = null,
        array $env = []
    ): void {
        $process = $this->createProcess($commands, $workingPath, $env);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(
                "Command failed: {$process->getCommandLine()}\n" . $process->getErrorOutput()
            );
        }
    }

    public function runWithOutput(
        array $commands,
        InputInterface $input,
        OutputInterface $output,
        ?string $workingPath = null,
        array $env = []
    ): Process {
        $commands = $this->addFlags($commands, $input, $output);
        $process = $this->createProcess($commands, $workingPath, $env);

        if (Process::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> ' . $e->getMessage() . PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    ' . $line);
        });

        return $process;
    }

    private function createProcess(array $commands, ?string $workingPath, array $env): Process
    {
        return Process::fromShellCommandline(
            implode(' && ', $commands),
            $workingPath,
            $env,
            null,
            null
        );
    }

    private function addFlags(array $commands, InputInterface $input, OutputInterface $output): array
    {
        if (!$output->isDecorated()) {
            $commands = array_map(function ($value) {
                if ($this->shouldSkipFlag($value)) {
                    return $value;
                }
                return $value . ' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if ($this->shouldSkipFlag($value)) {
                    return $value;
                }
                return $value . ' --quiet';
            }, $commands);
        }

        return $commands;
    }

    private function shouldSkipFlag(string $command): bool
    {
        return Str::startsWith($command, ['chmod', 'rm', 'git', 'php ./vendor/bin/pest']);
    }
}
