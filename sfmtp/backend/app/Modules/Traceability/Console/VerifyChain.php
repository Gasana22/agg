<?php

namespace App\Modules\Traceability\Console;

use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Traceability\Application\ChainVerifier;
use Illuminate\Console\Command;

/**
 * Nightly tamper check (scheduled in routes/console.php).
 */
class VerifyChain extends Command
{
    protected $signature = 'trace:verify-chain {--farm= : Only verify this farm id}';

    protected $description = 'Verify every farm\'s traceability hash chain and record the result.';

    public function handle(TenantContext $context, ChainVerifier $verifier): int
    {
        $failed = 0;
        $farms = Farm::query()->when($this->option('farm'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->cursor();

        foreach ($farms as $farm) {
            $result = $context->run($farm, fn () => $verifier->verify());
            $line = sprintf('%s %s: %s (%d events)', $farm->code, $farm->id, strtoupper($result['result']), $result['events']);

            if ($result['result'] === 'fail') {
                $failed++;
                $this->error($line." — {$result['reason']} at seq {$result['first_bad_seq']}");
            } else {
                $this->line($line);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
