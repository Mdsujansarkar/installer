<?php
namespace Laravel\Installer\ValueObjects;

use Laravel\Installer\Console\Enums\NodePackageManager;

readonly class PackageManagerSelection
{
    public function __construct(
        public NodePackageManager $manager,
        public bool $shouldRun
    ) {}
}


