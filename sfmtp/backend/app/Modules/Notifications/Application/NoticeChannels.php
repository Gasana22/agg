<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Identity\Domain\Models\User;

/**
 * Email and SMS copies of important notices (ADR-0018). The inbox and push
 * always carry every notice; a copy goes out only for the kinds listed here,
 * on the channels the person turned on. Email is on by default; SMS costs
 * money, so it is off until chosen.
 */
final class NoticeChannels
{
    /** Notice kind => channels it may be copied to. */
    public const COPIED = [
        'task_assigned' => ['sms', 'email'],
        'task_rejected' => ['sms', 'email'],
        'sales_order' => ['email', 'sms'],
        'supplier_response' => ['email'],
        'supplier_dispatch' => ['email', 'sms'],
        'supplier_invoice' => ['email'],
        'export_ready' => ['email'],
    ];

    public const DEFAULTS = ['email' => true, 'sms' => false];

    /** @return array{email:bool, sms:bool} */
    public static function of(User $user): array
    {
        $stored = is_array($user->notification_channels) ? $user->notification_channels : (json_decode((string) $user->notification_channels, true) ?: []);

        return ['email' => (bool) ($stored['email'] ?? self::DEFAULTS['email']), 'sms' => (bool) ($stored['sms'] ?? self::DEFAULTS['sms'])];
    }

    /** @return array<int, string> the channels a notice of this kind goes to for this person */
    public static function for(User $user, string $kind): array
    {
        $prefs = self::of($user);

        return array_values(array_filter(self::COPIED[$kind] ?? [], fn ($c) => $prefs[$c] && ($c !== 'sms' || $user->phone) && ($c !== 'email' || $user->email)));
    }
}
