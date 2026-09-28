<?php

namespace App\Modules\Sync\Console;

use App\Modules\Access\Domain\Models\FarmRole;
use App\Modules\Identity\Application\TokenService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\FarmService;
use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Models\FarmUser;
use App\Modules\Tenancy\TenantContext;
use App\Modules\Workforce\Application\Activities;
use App\Modules\Workforce\Application\Workers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sets up a separate farm for the load test (infra/load/README.md): a
 * manager, N field workers with phone tokens and a day of tasks each. For
 * development and staging only; it refuses to run in production.
 */
class PrepareLoadTest extends Command
{
    protected $signature = 'sync:loadtest-prepare {--devices=60 : Field workers, one phone each} {--tasks=20 : Tasks per worker} {--out= : Write the plan (JSON) here}';

    protected $description = 'Create a load-test farm with field workers, tasks and phone tokens (never in production).';

    public function handle(FarmService $farms, TenantContext $context, TokenService $tokens): int
    {
        if (app()->isProduction()) {
            $this->error('The load test creates accounts and tokens: run it against development or staging only.');

            return self::FAILURE;
        }

        if (! DB::table('global_activity_types')->where('code', 'general_labour')->exists()) {
            $this->error('The global catalogues are missing: run the database seeder first.');

            return self::FAILURE;
        }

        $devices = max(1, (int) $this->option('devices'));
        $taskCount = max(1, (int) $this->option('tasks'));
        $run = Str::lower(Str::random(6));
        $user = fn (string $name, string $email) => (new User)->forceFill([
            'name' => $name, 'email' => $email, 'user_type' => 'member', 'password' => Str::random(40), 'status' => 'active', 'email_verified_at' => now(), 'mfa_enabled_at' => now(),
        ])->saveOrFail() ? User::where('email', $email)->firstOrFail() : null;

        $owner = $user('Load Owner', "owner-{$run}@loadtest.invalid");
        $farm = $farms->create($owner, ['name' => "Load Test Farm {$run}", 'size_ha' => 100]);
        $farm->forceFill(['status' => FarmStatus::Active])->save();

        // Members are written directly, past the plan's user limit: this farm only exists to be measured.
        $join = function (User $u, string $role) use ($context, $farm): FarmUser {
            return $context->run($farm, function () use ($u, $role, $farm) {
                $member = FarmUser::create(['farm_id' => $farm->id, 'user_id' => $u->id, 'status' => 'active', 'joined_at' => now()]);
                DB::table('farm_user_roles')->insert(['farm_id' => $farm->id, 'farm_user_id' => $member->id,
                    'farm_role_id' => FarmRole::where('key', $role)->value('id'), 'created_at' => now()]);

                return $member;
            });
        };

        $manager = $user('Load Manager', "manager-{$run}@loadtest.invalid");
        $managerMember = $join($manager, 'manager');
        $people = [];
        for ($i = 1; $i <= $devices; $i++) {
            $u = $user("Load Worker {$i}", sprintf('worker-%s-%03d@loadtest.invalid', $run, $i));
            $people[] = [$u, $join($u, 'field_worker')];
        }

        // The manager registers the workers and plans the day: one task per worker per activity.
        Auth::setUser($manager);
        $plan = $context->run($farm, function () use ($people, $taskCount) {
            $workers = collect($people)->map(fn ($p, $i) => app(Workers::class)->create([
                'full_name' => $p[0]->name, 'employment_type' => 'casual', 'farm_user_id' => $p[1]->id,
            ]));
            $type = DB::table('global_activity_types')->where('code', 'general_labour')->value('id');
            for ($t = 1; $t <= $taskCount; $t++) {
                app(Activities::class)->create(['activity_type_id' => $type, 'title' => "Load task {$t}", 'worker_ids' => $workers->pluck('id')->all()]);
            }

            return $workers->map(fn ($w) => DB::table('worker_tasks')->where('worker_id', $w->id)->orderBy('created_at')->pluck('id')->all())->all();
        }, $managerMember);

        $result = [
            'farm_id' => $farm->id,
            'farm_code' => $farm->code,
            'manager_token' => $tokens->issue($manager, 'web', null)->accessToken,
            'devices' => collect($people)->map(fn ($p, $i) => ['token' => $tokens->issue($p[0], 'mobile', null)->accessToken, 'tasks' => $plan[$i]])->all(),
        ];

        $json = json_encode($result, JSON_PRETTY_PRINT);
        $this->option('out') ? file_put_contents($this->option('out'), $json) : $this->line($json);
        $this->info("Load-test farm {$farm->code}: {$devices} phones, {$taskCount} tasks each. Tokens last ".(int) (config('sfmtp.jwt.access_ttl') / 60).' minutes.');

        return self::SUCCESS;
    }
}
