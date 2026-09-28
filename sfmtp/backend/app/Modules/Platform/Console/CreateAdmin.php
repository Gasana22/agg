<?php

namespace App\Modules\Platform\Console;

use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Platform\Application\PlatformPermissions;
use App\Modules\Platform\Application\PlatformRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Creates the first platform administrator of a new installation
 * (docs/13-deployment.md §4). Later staff are added from the admin portal.
 * No password is set here: the person chooses one from the emailed reset
 * link, then must enrol MFA at first sign-in.
 */
class CreateAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {name} {--role=super_admin : Platform role}';

    protected $description = 'Create a platform administrator and email them a link to choose a password.';

    public function handle(PlatformPermissions $platform): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not an email address.');

            return self::INVALID;
        }
        if (! array_key_exists($role, PlatformRoles::roles())) {
            $this->error('Unknown role. Choose one of: '.implode(', ', array_keys(PlatformRoles::roles())));

            return self::INVALID;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('An account with this email already exists.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $role, $platform) {
            $user = (new User)->forceFill([
                'name' => (string) $this->argument('name'),
                'email' => $email,
                'user_type' => UserType::PlatformAdmin,
                'status' => 'active',
                'password' => bin2hex(random_bytes(32)),   // unusable until reset
                'email_verified_at' => now(),
            ]);
            $user->save();
            $platform->assign($user, $role);
        });

        $status = Password::sendResetLink(['email' => $email]);
        $this->info("Platform administrator {$email} created ({$role}).");
        $this->line($status === Password::RESET_LINK_SENT
            ? 'A link to choose a password was emailed. MFA setup follows at first sign-in.'
            : 'The password email could not be sent ('.$status.'); use "Forgot password" on the sign-in page once email works.');

        return self::SUCCESS;
    }
}
