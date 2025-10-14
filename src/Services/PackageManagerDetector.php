<?php
namespace Laravel\Installer\Services;

use Illuminate\Filesystem\Filesystem;
use Laravel\Installer\Console\Enums\NodePackageManager;
use Laravel\Installer\ValueObjects\PackageManagerSelection;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Symfony\Component\Console\Input\InputInterface;

class PackageManagerDetector
{
    public function __construct(
        private readonly Filesystem $files
    ) {}

    public function detect(string $directory, InputInterface $input): PackageManagerSelection
    {
        // Check for explicit CLI flags
        if ($input->getOption('pnpm')) {
            return new PackageManagerSelection(NodePackageManager::PNPM, true);
        }

        if ($input->getOption('bun')) {
            return new PackageManagerSelection(NodePackageManager::BUN, true);
        }

        if ($input->getOption('yarn')) {
            return new PackageManagerSelection(NodePackageManager::YARN, true);
        }

        if ($input->getOption('npm')) {
            return new PackageManagerSelection(NodePackageManager::NPM, true);
        }

        // Check for existing lock files
        foreach (NodePackageManager::cases() as $packageManager) {
            if ($packageManager === NodePackageManager::NPM) {
                continue;
            }

            foreach ($packageManager->lockFiles() as $lockFile) {
                if ($this->files->exists($directory . '/' . $lockFile)) {
                    return new PackageManagerSelection($packageManager, false);
                }
            }
        }

        return new PackageManagerSelection(NodePackageManager::NPM, false);
    }

    public function cleanupLockFiles(ProjectConfiguration $config): void
    {
        if (!$config->packageManager) {
            return;
        }

        $validLockFiles = $config->packageManager->lockFiles();
        $allLockFiles = NodePackageManager::allLockFiles();

        foreach ($allLockFiles as $lockFile) {
            if (!in_array($lockFile, $validLockFiles, true)) {
                $path = $config->directory . '/' . $lockFile;
                if ($this->files->exists($path)) {
                    $this->files->delete($path);
                }
            }
        }
    }
}
