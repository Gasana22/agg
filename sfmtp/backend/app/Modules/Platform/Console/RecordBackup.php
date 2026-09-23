<?php

namespace App\Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Called by infra/deploy/backup.sh after each database backup, so the admin
 * dashboard can show backup health (docs/01 §10: daily backups, RPO/RTO).
 */
class RecordBackup extends Command
{
    protected $signature = 'platform:record-backup
        {status : success or failed}
        {--location= : Where the backup was written}
        {--size= : Size in bytes}
        {--checksum= : SHA-256 of the backup file}
        {--started-at= : ISO-8601 start time}
        {--message= : Error or note}';

    protected $description = 'Record the outcome of a database backup run.';

    public function handle(): int
    {
        $status = $this->argument('status');
        if (! in_array($status, ['success', 'failed'], true)) {
            $this->error('status must be success or failed');

            return self::INVALID;
        }

        DB::table('backup_runs')->insert([
            'id' => (string) Str::uuid7(),
            'status' => $status,
            'started_at' => $this->option('started-at') ? now()->parse($this->option('started-at')) : null,
            'finished_at' => now(),
            'size_bytes' => $this->option('size') !== null ? (int) $this->option('size') : null,
            'location' => $this->option('location'),
            'checksum' => $this->option('checksum'),
            'message' => $this->option('message') ? Str::limit($this->option('message'), 990) : null,
            'created_at' => now(),
        ]);

        $this->info("Backup run recorded ({$status}).");

        return self::SUCCESS;
    }
}
