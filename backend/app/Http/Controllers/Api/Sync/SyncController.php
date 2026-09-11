<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnimalResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\CropResource;
use App\Http\Resources\CropSeasonResource;
use App\Http\Resources\InventoryItemResource;
use App\Http\Resources\WorkerProfileResource;
use App\Models\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The pull side of offline support: before going offline, a client fetches
 * (and caches locally) the reference data it needs to let a field worker
 * keep working without a connection — who's on the farm, what's planted,
 * what animals and equipment exist, what's in stock. It's exactly the
 * lookup data the offline-critical create endpoints (attendance check-in,
 * health logs, activities, ...) need IDs from.
 *
 * Pass `since` (an ISO 8601 timestamp, normally the `synced_at` this
 * endpoint returned last time) to get only what changed — omit it to
 * pull everything, which a client does once on first login. Deletions
 * aren't tracked, so a record removed from the farm since the last sync
 * won't be reported as gone; the client is expected to reconcile that
 * the next time it's online long enough to do a full (unfiltered) pull.
 */
class SyncController extends Controller
{
    public function show(Request $request, Farm $farm): JsonResponse
    {
        abort_unless($request->user()->canViewFarm($farm), 403);

        $since = $request->query('since') ? Carbon::parse($request->query('since')) : null;

        $updatedSince = fn ($query) => $since ? $query->where('updated_at', '>=', $since) : $query;

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'since' => $since?->toIso8601String(),
            'worker_profiles' => WorkerProfileResource::collection(
                $updatedSince($farm->workerProfiles()->with(['user', 'supervisor']))->get()
            ),
            'crops' => CropResource::collection(
                $updatedSince($farm->crops())->get()
            ),
            'crop_seasons' => CropSeasonResource::collection(
                $updatedSince($farm->cropSeasons())->get()
            ),
            'animals' => AnimalResource::collection(
                $updatedSince($farm->animals())->get()
            ),
            'inventory_items' => InventoryItemResource::collection(
                $updatedSince($farm->inventoryItems())->get()
            ),
            'assets' => AssetResource::collection(
                $updatedSince($farm->assets())->get()
            ),
        ]);
    }
}
