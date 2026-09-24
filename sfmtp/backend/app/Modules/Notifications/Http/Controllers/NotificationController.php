<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Domain\Models\UserDevice;
use App\Modules\Notifications\Domain\Models\MemberNotification;
use App\Support\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['unread' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = MemberNotification::where('user_id', Auth::id())
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate((int) ($data['per_page'] ?? 25));

        return new JsonResponse([
            'data' => collect($page->items())->map->toArrayForMember()->all(),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'unread' => MemberNotification::where('user_id', Auth::id())->whereNull('read_at')->count()],
        ]);
    }

    public function read(string $farm, string $notification): JsonResponse
    {
        $n = MemberNotification::where('user_id', Auth::id())->find($notification) ?? throw ApiException::notFound();
        if ($n->read_at === null) {
            $n->forceFill(['read_at' => now()])->save();
        }

        return new JsonResponse(['data' => $n->toArrayForMember()]);
    }

    public function readAll(): JsonResponse
    {
        $count = MemberNotification::where('user_id', Auth::id())->whereNull('read_at')->get()->each(fn ($n) => $n->forceFill(['read_at' => now()])->save())->count();

        return new JsonResponse(['data' => ['marked' => $count]]);
    }

    /** The phone registers where to push (the device comes from the access token). */
    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['present', 'nullable', 'string', 'max:4096'], 'platform' => ['required_with:token', 'nullable', 'in:fcm,apns']]);
        $deviceId = $request->attributes->get('device_id') ?? throw ApiException::unprocessable('no_device', 'Sign in from the app to register for notifications.');
        $device = UserDevice::where('user_id', Auth::id())->whereKey($deviceId)->firstOrFail();
        $device->forceFill(['push_token' => $data['token'], 'push_platform' => $data['token'] ? $data['platform'] : null, 'push_token_at' => now()])->save();

        return new JsonResponse(['data' => ['registered' => $data['token'] !== null]]);
    }
}
