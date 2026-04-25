<?php

namespace App\Console\Commands;

use App\Support\NativeDatabaseBootstrapper;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class PrepareNativeSeedDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'native:prepare-seed-database
        {--source= : Source SQLite database path}
        {--destination= : Destination seed database path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prepare the SQLite seed database bundled with NativePHP builds';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $files): int
    {
        $sourcePath = $this->option('source') ?: $this->currentSqliteDatabasePath();
        $destinationPath = $this->option('destination') ?: NativeDatabaseBootstrapper::bundledSeedDatabasePath();

        if (! is_string($sourcePath) || ! is_file($sourcePath)) {
            $this->error("Source SQLite database not found: {$sourcePath}");

            return self::FAILURE;
        }

        if (! is_string($destinationPath)) {
            $this->error('Destination seed database path is invalid.');

            return self::FAILURE;
        }

        $files->ensureDirectoryExists(dirname($destinationPath));
        $files->copy($sourcePath, $destinationPath);

        $this->info("NativePHP seed database prepared: {$destinationPath}");

        return self::SUCCESS;
    }

    private function currentSqliteDatabasePath(): string
    {
        $connection = config('database.default');
        $connectionConfig = config("database.connections.{$connection}");

        if (($connectionConfig['driver'] ?? null) !== 'sqlite') {
            return '';
        }

        $databasePath = $connectionConfig['database'] ?? '';

        if (! is_string($databasePath) || $databasePath === ':memory:') {
            return '';
        }

        if ($this->isAbsolutePath($databasePath)) {
            return $databasePath;
        }

        return base_path($databasePath);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
