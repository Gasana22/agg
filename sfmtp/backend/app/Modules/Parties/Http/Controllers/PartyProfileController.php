<?php

namespace App\Modules\Parties\Http\Controllers;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Parties\Application\PartyContext;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The party's own details, shared by its supplier and customer portals.
 * Farms keep their own copy on their supplier / customer record.
 */
class PartyProfileController
{
    public function __construct(
        private readonly PartyContext $parties,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $party = $this->parties->party();

        return new JsonResponse(['data' => $party->toProfile() + [
            'people' => $party->users()->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->values(),
            'portals' => array_values(array_filter(['supplier', 'customer'], fn ($k) => $this->parties->links($k)->isNotEmpty())),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:40'],
            'version' => ['required', 'integer'],
        ]);
        $party = $this->parties->party();
        if ((int) $data['version'] !== $party->version) {
            throw ApiException::conflict('version_conflict', 'Someone else changed these details. Reload and try again.');
        }
        unset($data['version']);
        $old = $party->only(array_keys($data));
        $party->fill($data)->save();
        $this->audit->record('portal.party.updated', $party, $old, $data);

        return $this->show();
    }
}
