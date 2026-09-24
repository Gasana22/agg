<?php

namespace App\Modules\Sales\Portal;

use App\Modules\Parties\Contracts\PortalSubject;
use App\Modules\Sales\Domain\Models\Customer;

/** A farm's customer, opened to the customer portal. */
class CustomerPortalSubject implements PortalSubject
{
    public function kind(): string
    {
        return 'customer';
    }

    public function managePermission(): string
    {
        return 'customers.manage';
    }

    public function find(string $id): ?array
    {
        $c = Customer::find($id);

        return $c ? ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'email' => $c->email, 'is_active' => $c->is_active] : null;
    }

    public function attach(string $id, ?string $partyId): void
    {
        Customer::whereKey($id)->first()?->forceFill(['party_id' => $partyId])->save();
    }
}
