<?php
namespace Laravel\Installer\ValueObjects;

use Laravel\Installer\Console\Enums\NodePackageManager;

readonly class ProjectConfiguration
{
public function __construct(
public string $name,
public string $directory,
public string $version,
public ?string $starterKit,
public string $database,
public bool $shouldMigrate,
public bool $useGit,
public string $gitBranch,
public bool $useGitHub,
public ?string $githubOrganization,
public string $githubFlags,
public bool $usePest,
public ?NodePackageManager $packageManager,
public bool $shouldRunPackageManager,
public bool $force,
public bool $isDev,
public bool $isInteractive,
public bool $useLivewireClassComponents,
public bool $useWorkOS,
) {}

public function isUsingStarterKit(): bool
{
return $this->starterKit !== null;
}

public function isUsingLaravelStarterKit(): bool
{
return $this->starterKit && str_starts_with($this->starterKit, 'laravel/');
}
}
