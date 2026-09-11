<?php

namespace App\Http\Controllers\Api\FarmStructure;

use App\Http\Controllers\Controller;
use App\Http\Requests\FarmStructure\AddFarmMemberRequest;
use App\Http\Requests\FarmStructure\UpdateFarmMemberRequest;
use App\Http\Resources\FarmMemberResource;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class FarmMemberController extends Controller
{
    public function index(Request $request, Farm $farm): AnonymousResourceCollection
    {
        $this->authorizeManage($request->user(), $farm, allowView: true);

        return FarmMemberResource::collection(
            $farm->users()->orderBy('name')->get()
        );
    }

    public function store(AddFarmMemberRequest $request, Farm $farm): FarmMemberResource
    {
        $this->authorizeManage($request->user(), $farm);

        $member = User::where('email', $request->validated('email'))->firstOrFail();

        if ($farm->users()->where('user_id', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This user is already a member of this farm.',
            ]);
        }

        $farm->users()->attach($member->id, ['role_on_farm' => $request->validated('role_on_farm')]);

        return new FarmMemberResource($farm->users()->where('user_id', $member->id)->first());
    }

    public function update(UpdateFarmMemberRequest $request, Farm $farm, User $member): FarmMemberResource
    {
        $this->authorizeManage($request->user(), $farm);
        $this->guardNotOwner($farm, $member);

        $farm->users()->updateExistingPivot($member->id, [
            'role_on_farm' => $request->validated('role_on_farm'),
        ]);

        return new FarmMemberResource($farm->users()->where('user_id', $member->id)->first());
    }

    public function destroy(Request $request, Farm $farm, User $member): Response
    {
        $this->authorizeManage($request->user(), $farm);
        $this->guardNotOwner($farm, $member);

        $farm->users()->detach($member->id);

        return response()->noContent();
    }

    private function authorizeManage(User $user, Farm $farm, bool $allowView = false): void
    {
        if ($allowView && $user->canViewFarm($farm)) {
            return;
        }

        if (! $user->canManageFarm($farm)) {
            abort(403);
        }
    }

    /**
     * The farm's owner_id record can't be edited or removed here — that
     * would leave the farm without its designated owner. Ownership
     * transfer isn't built yet.
     */
    private function guardNotOwner(Farm $farm, User $member): void
    {
        if ($farm->owner_id === $member->id) {
            throw ValidationException::withMessages([
                'member' => 'The farm owner cannot be removed or reassigned here.',
            ]);
        }
    }
}
