<?php

// File: src/ServiceProvider.php
namespace Laravel\Installer;

use Psr\Log\LoggerInterface;
use Illuminate\Filesystem\Filesystem;
use Laravel\Installer\Console\NewCommand;
use Laravel\Installer\Services\CommandRunner;
use Laravel\Installer\Services\DatabaseConfigurator;
use Laravel\Installer\Services\GitHubService;
use Laravel\Installer\Services\GitService;
use Laravel\Installer\Services\PackageManagerDetector;
use Laravel\Installer\Services\PestInstaller;
use Laravel\Installer\Services\PhpBinaryLocator;
use Laravel\Installer\Services\ProjectBuilder;
use Laravel\Installer\Services\VersionChecker;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

class ServiceProvider
{
    public static function createNewCommand(ContainerInterface $container = null): NewCommand
    {
        // If no container provided, build dependencies manually
        if (!$container) {
            return self::buildManually();
        }

        // Use container to resolve dependencies
        return new NewCommand(
            $container->get(ProjectBuilder::class),
            $container->get(DatabaseConfigurator::class),
            $container->get(GitService::class),
            $container->get(GitHubService::class),
            $container->get(PackageManagerDetector::class),
            $container->get(PestInstaller::class),
            $container->get(VersionChecker::class),
            $container->get(LoggerInterface::class)
        );
    }

    private static function buildManually(): NewCommand
    {
        $files = new Filesystem();
        $phpBinary = new PhpBinaryLocator();
        $commandRunner = new CommandRunner();
        $logger = new NullLogger();

        $projectBuilder = new ProjectBuilder($files, $commandRunner, $phpBinary);
        $databaseConfigurator = new DatabaseConfigurator($files, $commandRunner, $phpBinary);
        $gitService = new GitService($commandRunner);
        $githubService = new GitHubService($commandRunner);
        $packageManagerDetector = new PackageManagerDetector($files);
        $pestInstaller = new PestInstaller($files, $commandRunner, $phpBinary, $gitService);
        $versionChecker = new VersionChecker($commandRunner, $logger);

        return new NewCommand(
            $projectBuilder,
            $databaseConfigurator,
            $gitService,
            $githubService,
            $packageManagerDetector,
            $pestInstaller,
            $versionChecker,
            $logger
        );
    }

    /**
     * Register services in a PSR-11 compatible container
     */
    public static function register(ContainerInterface $container): void
    {
        // Register basic dependencies
        $container->set(Filesystem::class, fn() => new Filesystem());
        $container->set(PhpBinaryLocator::class, fn() => new PhpBinaryLocator());
        $container->set(CommandRunner::class, fn() => new CommandRunner());

        // Register logger (or use NullLogger as fallback)
        if (!$container->has(LoggerInterface::class)) {
            $container->set(LoggerInterface::class, fn() => new NullLogger());
        }

        // Register services
        $container->set(ProjectBuilder::class, fn($c) => new ProjectBuilder(
            $c->get(Filesystem::class),
            $c->get(CommandRunner::class),
            $c->get(PhpBinaryLocator::class)
        ));

        $container->set(DatabaseConfigurator::class, fn($c) => new DatabaseConfigurator(
            $c->get(Filesystem::class),
            $c->get(CommandRunner::class),
            $c->get(PhpBinaryLocator::class)
        ));

        $container->set(GitService::class, fn($c) => new GitService(
            $c->get(CommandRunner::class)
        ));

        $container->set(GitHubService::class, fn($c) => new GitHubService(
            $c->get(CommandRunner::class)
        ));

        $container->set(PackageManagerDetector::class, fn($c) => new PackageManagerDetector(
            $c->get(Filesystem::class)
        ));

        $container->set(GitService::class, fn($c) => new GitService(
            $c->get(CommandRunner::class)
        ));

        $container->set(PestInstaller::class, fn($c) => new PestInstaller(
            $c->get(Filesystem::class),
            $c->get(CommandRunner::class),
            $c->get(PhpBinaryLocator::class),
            $c->get(GitService::class)
        ));

        $container->set(VersionChecker::class, fn($c) => new VersionChecker(
            $c->get(CommandRunner::class),
            $c->get(LoggerInterface::class)
        ));

        // Register the command itself
        $container->set(NewCommand::class, fn($c) => new NewCommand(
            $c->get(ProjectBuilder::class),
            $c->get(DatabaseConfigurator::class),
            $c->get(GitService::class),
            $c->get(GitHubService::class),
            $c->get(PackageManagerDetector::class),
            $c->get(PestInstaller::class),
            $c->get(VersionChecker::class),
            $c->get(LoggerInterface::class)
        ));
    }
}
