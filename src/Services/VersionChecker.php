<?php
namespace Laravel\Installer\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;

use function Illuminate\Filesystem\join_paths;
use function Laravel\Prompts\confirm;

class VersionChecker
{
    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly CommandRunner $commandRunner,
        private readonly LoggerInterface $logger
    ) {}

    public function checkAndPromptForUpdate(InputInterface $input, OutputInterface $output): void
    {
        $currentVersion = $this->getCurrentVersion();
        $latestVersion = $this->getLatestVersion();

        if (!$latestVersion || version_compare($currentVersion, $latestVersion) >= 0) {
            return;
        }

        $output->writeln(
            "  <bg=yellow;fg=black> WARN </> A new version of the Laravel installer is available. " .
            "You have version {$currentVersion} installed, the latest version is {$latestVersion}."
        );

        $this->promptForUpdate($input, $output);
    }

    private function promptForUpdate(InputInterface $input, OutputInterface $output): void
    {
        $installerPath = (new ExecutableFinder())->find('laravel') ?? '';

        if ($this->isHerdInstaller($installerPath)) {
            $this->handleHerdUpdate($input, $output);
        } elseif ($this->isHerdLiteInstaller($installerPath)) {
            $this->handleHerdLiteUpdate($input, $output);
        } else {
            $this->handleComposerUpdate($input, $output);
        }
    }

    private function handleHerdUpdate(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(
            '  To update, open <options=bold>Herd</> > <options=bold>Settings</> > ' .
            '<options=bold>PHP</> > <options=bold>Laravel Installer</> and click the ' .
            '<options=bold>"Update"</> button.'
        );

        if (confirm(label: 'Would you like to update now?', yes: 'I have updated', no: 'Not now')) {
            $this->proxyToLaravel($input, $output);
        }
    }

    private function handleHerdLiteUpdate(InputInterface $input, OutputInterface $output): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Windows' => 'Set-ExecutionPolicy Bypass -Scope Process -Force; ' .
                '[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; ' .
                "iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows'))",
            'Darwin' => '/bin/bash -c "$(curl -fsSL https://php.new/install/mac)"',
            default => '/bin/bash -c "$(curl -fsSL https://php.new/install/linux)"',
        };

        $output->writeln('');
        $output->writeln('  To update, run the following command in your terminal:');
        $output->writeln("  {$command}");

        if (confirm(label: 'Would you like to update now?', yes: 'I have updated', no: 'Not now')) {
            $this->proxyToLaravel($input, $output);
        }
    }

    private function handleComposerUpdate(InputInterface $input, OutputInterface $output): void
    {
        if (confirm(label: 'Would you like to update now?')) {
            $this->commandRunner->runWithOutput(
                ['composer global update laravel/installer'],
                $input,
                $output,
                null
            );
            $this->proxyToLaravel($input, $output);
        }
    }

    private function proxyToLaravel(InputInterface $input, OutputInterface $output): void
    {
        $output->writeln('');
        $this->commandRunner->runWithOutput(['laravel ' . $input], $input, $output, getcwd());
        exit;
    }

    private function getCurrentVersion(): string
    {
        // This would come from the application instance
        return '5.0.0'; // Placeholder
    }

    private function getLatestVersion(): ?string
    {
        try {
            $data = $this->fetchVersionData();
            if (!$data) {
                return null;
            }

            $decoded = json_decode($data, true);
            return ltrim($decoded['packages']['laravel/installer'][0]['version'] ?? '', 'v');
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch latest version', ['exception' => $e]);
            return null;
        }
    }

    private function fetchVersionData(): ?string
    {
        $cachedPath = $this->getCachePath();
        $lastModifiedPath = $this->getLastModifiedPath();

        // Check cache age
        if (file_exists($cachedPath) && filemtime($cachedPath) > time() - self::CACHE_TTL_SECONDS) {
            return file_get_contents($cachedPath);
        }

        $curl = curl_init();
        $headers = ['User-Agent: Laravel Installer'];

        if (file_exists($lastModifiedPath)) {
            $headers[] = 'If-Modified-Since: ' . file_get_contents($lastModifiedPath);
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://repo.packagist.org/p2/laravel/installer.json',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        try {
            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            curl_close($curl);

            if ($httpCode === 304 && file_exists($cachedPath)) {
                touch($cachedPath);
                return file_get_contents($cachedPath);
            }

            if ($httpCode === 200) {
                $body = substr($response, $headerSize);
                file_put_contents($cachedPath, $body);

                // Extract Last-Modified header
                if (preg_match('/^Last-Modified:\s*(.+)$/mi', substr($response, 0, $headerSize), $matches)) {
                    file_put_contents($lastModifiedPath, trim($matches[1]));
                }

                return $body;
            }
        } catch (\Throwable $e) {
            $this->logger->error('cURL request failed', ['exception' => $e]);
        }

        return file_exists($cachedPath) ? file_get_contents($cachedPath) : null;
    }

    private function getCachePath(): string
    {
        return join_paths(sys_get_temp_dir(), 'laravel-installer-version-check.json');
    }

    private function getLastModifiedPath(): string
    {
        return join_paths(sys_get_temp_dir(), 'laravel-installer-last-modified');
    }

    private function isHerdInstaller(string $path): bool
    {
        return str_contains($path, DIRECTORY_SEPARATOR . 'Herd' . DIRECTORY_SEPARATOR);
    }

    private function isHerdLiteInstaller(string $path): bool
    {
        return str_contains($path, DIRECTORY_SEPARATOR . 'herd-lite' . DIRECTORY_SEPARATOR);
    }
}
