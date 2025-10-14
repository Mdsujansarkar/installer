<?php
namespace Laravel\Installer\Services;

use Illuminate\Support\ProcessUtils;
use Symfony\Component\Process\PhpExecutableFinder;

class PhpBinaryLocator
{
    public function find(): string
    {
        $phpBinary = function_exists('Illuminate\Support\php_binary')
            ? \Illuminate\Support\php_binary()
            : (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }
}
