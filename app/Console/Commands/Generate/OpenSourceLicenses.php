<?php

namespace App\Console\Commands\Generate;

use App\Services\OpenSourceLicenses as OpenSourceLicenseInventory;
use Illuminate\Console\Command;

class OpenSourceLicenses extends Command
{
    protected $signature = 'generate:open-source-licenses
        {--terminal-node-modules= : node_modules of docker/coolify-terminal (default: docker/coolify-terminal/node_modules)}';

    protected $description = 'Generate the open source notices from the lock files and the installed packages.';

    public function handle(): int
    {
        $inventory = OpenSourceLicenseInventory::generate(base_path(), $this->option('terminal-node-modules') ?: null);
        $path = base_path(OpenSourceLicenseInventory::INVENTORY_PATH);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $packages = collect($inventory['packages']);
        $this->info("Wrote {$packages->count()} packages to ".OpenSourceLicenseInventory::INVENTORY_PATH.'.');

        $missing = $packages->filter(fn (array $package): bool => $package['license_text'] === null);
        if ($missing->isNotEmpty()) {
            $this->warn("{$missing->count()} installed packages have no license file; the page links their SPDX license text instead:");
            $missing->each(fn (array $package) => $this->line("  {$package['key']}@{$package['version']}"));
        }

        return self::SUCCESS;
    }
}
