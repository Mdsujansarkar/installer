<?php

// File: tests/Unit/Services/
namespace Tests\Unit\Services;

use Illuminate\Filesystem\Filesystem;
use Laravel\Installer\Services\CommandRunner;
use Laravel\Installer\Services\PhpBinaryLocator;
use Laravel\Installer\Services\ProjectBuilder;
use Laravel\Installer\ValueObjects\ProjectConfiguration;
use Laravel\Installer\Console\Enums\NodePackageManager;
use PHPUnit\Framework\TestCase;
use Mockery;

class ProjectBuilderTest extends TestCase
{
    private Filesystem $files;
    private CommandRunner $commandRunner;
    private PhpBinaryLocator $phpBinary;
    private ProjectBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files = Mockery::mock(Filesystem::class);
        $this->commandRunner = Mockery::mock(CommandRunner::class);
        $this->phpBinary = Mockery::mock(PhpBinaryLocator::class);

        $this->builder = new ProjectBuilder(
            $this->files,
            $this->commandRunner,
            $this->phpBinary
        );
    }
}
