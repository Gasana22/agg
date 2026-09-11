<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\Farm;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    public function view(User $user, Document $document): bool
    {
        return $user->canViewFarm($document->farm);
    }

    /**
     * Uploading is self-service — whoever has the vet certificate, the
     * invoice, or took the photo attaches it, same as the existing
     * check-in/task photo uploads.
     */
    public function create(User $user, Farm $farm): bool
    {
        return $user->canViewFarm($farm);
    }

    /**
     * The uploader can remove their own mistake; a manager can remove
     * anyone's.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->canManageFarm($document->farm) || $document->uploaded_by === $user->id;
    }
}
