<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DatabaseSnapshotCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:snapshot
        {--connection= : Database connection name (defaults to database.default)}
        {--keep=30 : Number of recent backups to keep before pruning}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a SQL dump of the configured database and prune snapshots older than N days';

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (!$connection) {
            $this->error("Database connection [{$connectionName}] not found in config/database.php");
            return self::FAILURE;
        }

        $directory = storage_path('app/backups');
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $timestamp = now()->format('Ymd_His');
        $database = $connection['database'] ?? 'database';
        $filename = sprintf('%s_%s.sql', Str::slug($database, '_'), $timestamp);
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        try {
            $process = $this->buildDumpProcess($connection, $path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Creating backup for [{$database}] using connection [{$connectionName}]...");

        try {
            $process->mustRun(function ($type, $buffer) {
                if ($this->output->isVerbose()) {
                    $this->output->write($buffer);
                }
            });
        } catch (ProcessFailedException $exception) {
            if (File::exists($path)) {
                File::delete($path);
            }
            $this->error('Backup process failed: '.$exception->getMessage());
            return self::FAILURE;
        }

        $this->info("Backup stored at {$path}");

        $keep = (int) $this->option('keep') ?: 30;
        $this->pruneOldBackups($directory, $keep);

        return self::SUCCESS;
    }

    private function buildDumpProcess(array $config, string $outputPath): Process
    {
        $driver = $config['driver'] ?? 'mysql';
        $host = $config['host'] ?? '127.0.0.1';
        $port = (string) ($config['port'] ?? ($driver === 'pgsql' ? 5432 : 3306));
        $username = $config['username'] ?? $config['user'] ?? null;
        $password = $config['password'] ?? null;
        $database = $config['database'] ?? null;

        if (!$username || !$database) {
            throw new \InvalidArgumentException('Database username and name are required to create a snapshot.');
        }

        if ($driver === 'mysql') {
            $command = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --single-transaction --quick --routines %s > %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($database),
                escapeshellarg($outputPath)
            );

            return Process::fromShellCommandline($command, base_path(), array_filter([
                'MYSQL_PWD' => $password,
            ]), null, 1800);
        }

        if ($driver === 'pgsql') {
            $command = sprintf(
                'pg_dump --host=%s --port=%s --username=%s --format=plain --no-owner --no-privileges --file=%s %s',
                escapeshellarg($host),
                escapeshellarg($port),
                escapeshellarg($username),
                escapeshellarg($outputPath),
                escapeshellarg($database)
            );

            return Process::fromShellCommandline($command, base_path(), array_filter([
                'PGPASSWORD' => $password,
            ]), null, 1800);
        }

        throw new \InvalidArgumentException("Driver [{$driver}] is not supported for automated snapshots.");
    }

    private function pruneOldBackups(string $directory, int $keep): void
    {
        $files = collect(File::files($directory))
            ->sortByDesc(fn ($file) => $file->getCTime())
            ->values();

        $orphans = $files->slice($keep);
        foreach ($orphans as $file) {
            File::delete($file->getPathname());
        }

        if ($orphans->isNotEmpty()) {
            $this->info(sprintf('Pruned %d old backup(s), keeping the most recent %d.', $orphans->count(), $keep));
        }
    }
}
