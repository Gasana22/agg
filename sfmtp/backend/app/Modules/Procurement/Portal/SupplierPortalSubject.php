<?php

namespace App\Modules\Procurement\Portal;

use App\Modules\Parties\Contracts\PortalSubject;
use App\Modules\Procurement\Domain\Models\Supplier;

/** A farm's supplier, opened to the supplier portal. */
class SupplierPortalSubject implements PortalSubject
{
    public function kind(): string
    {
        return 'supplier';
    }

    public function managePermission(): string
    {
        return 'suppliers.manage';
    }

    public function find(string $id): ?array
    {
        $s = Supplier::find($id);

        return $s ? ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'email' => $s->email, 'is_active' => $s->is_active] : null;
    }

    public function attach(string $id, ?string $partyId): void
    {
        Supplier::whereKey($id)->first()?->forceFill(['party_id' => $partyId])->save();
    }
}
