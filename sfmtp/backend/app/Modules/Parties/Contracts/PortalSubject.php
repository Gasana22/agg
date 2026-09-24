<?php

namespace App\Modules\Parties\Contracts;

/**
 * A farm record a party can be linked to: Procurement's suppliers and
 * Sales' customers. Implementations are tagged `sfmtp.portal-subjects`, so
 * Parties does not depend on those modules. Every method runs inside the
 * record's farm context.
 */
interface PortalSubject
{
    /** supplier | customer */
    public function kind(): string;

    /** The farm permission needed to invite or unlink. */
    public function managePermission(): string;

    /** @return array{id:string, code:string, name:string, email:?string, is_active:bool}|null */
    public function find(string $id): ?array;

    /** Keep the record's party_id in step with its link (null when unlinked). */
    public function attach(string $id, ?string $partyId): void;
}
