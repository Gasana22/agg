<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Application\ChartOfAccounts;
use App\Modules\Tenancy\Domain\Concerns\BelongsToFarm;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An account in the farm's chart of accounts.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $type asset | liability | equity | income | expense
 * @property bool $is_system
 * @property bool $is_cash money account (cash, mobile money, bank)
 * @property bool $is_active
 */
class LedgerAccount extends Model
{
    use BelongsToFarm, HasUuids;

    protected $fillable = ['farm_id', 'code', 'name', 'description', 'type', 'is_cash', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_cash' => 'boolean', 'is_active' => 'boolean'];
    }

    /** Only its documents post to a control account (ChartOfAccounts::CONTROL). */
    public function isControl(): bool
    {
        return $this->is_system && in_array($this->code, ChartOfAccounts::CONTROL, true);
    }

    /** Debit-normal accounts grow with debits. */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, ['asset', 'expense'], true);
    }
}
