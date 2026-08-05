<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'database:backup
                            {--path= : Relative backup directory under storage/app/backups}
                            {--compress= : Compression format (gzip|none)}';

    protected $description = 'Backup the database to storage/app/backups and optionally compress it.';

    public function handle(): int
    {
        $driver = Config::get('database.default');
        $backupDir = storage_path('app/backups/' . trim($this->option('path') ?? '', '/')); 
        File::ensureDirectoryExists($backupDir);

        $timestamp = now()->format('YmdHis');
        $compress = strtolower($this->option('compress') ?? 'none');
        $suffix = $compress === 'gzip' ? '.sql.gz' : '.sql';
        $backupFile = $backupDir . '/backup-' . $driver . '-' . $timestamp . $suffix;

        if ($driver === 'sqlite') {
            $databasePath = Config::get('database.connections.sqlite.database');

            if ($databasePath === ':memory:' || empty($databasePath)) {
                $this->error('SQLite in-memory or undefined database cannot be backed up with this command.');
                return self::FAILURE;
            }

            File::copy($databasePath, $backupDir . '/backup-' . $driver . '-' . $timestamp . '.sqlite');
            $this->info('SQLite backup copied to ' . $backupFile);
            return self::SUCCESS;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->error('Backup command only supports sqlite, mysql, and mariadb in this application.');
            return self::FAILURE;
        }

        $connection = Config::get('database.connections.' . $driver);
        $host = $connection['host'] ?? '127.0.0.1';
        $port = $connection['port'] ?? '3306';
        $database = $connection['database'];
        $username = $connection['username'] ?? 'root';
        $password = $connection['password'] ?? '';

        $this->info("Creating backup for {$database} on {$driver}...");

        $dumpFile = $backupDir . '/backup-' . $driver . '-' . $timestamp . '.sql';
        $process = new Process([
            'mysqldump',
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $username,
            '--password=' . $password,
            $database,
        ]);

        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Failed to execute mysqldump: ' . $process->getErrorOutput());
            return self::FAILURE;
        }

        File::put($dumpFile, $process->getOutput());

        if ($compress === 'gzip') {
            $process = new Process(['gzip', '-f', $dumpFile]);
            $process->setTimeout(60);
            $process->run();
            if (! $process->isSuccessful()) {
                $this->error('Backup created but compression failed: ' . $process->getErrorOutput());
                return self::FAILURE;
            }
        }

        $finalPath = $compress === 'gzip' ? $dumpFile . '.gz' : $dumpFile;
        $this->info('Database backup saved to ' . $finalPath);
        return self::SUCCESS;
    }
}
