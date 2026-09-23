<?php

namespace Tests\Feature\Support;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Audit\Domain\Models\AuditLog;
use App\Support\Database\AppendOnlyViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_farm_audit_log_shows_only_that_farms_entries(): void
    {
        [$a, $b] = [$this->farm(), $this->farm()];
        $this->inFarm($a, fn () => $this->app->make(AuditLogger::class)->record('test.a', ['type' => 'thing', 'id' => '1']));
        $this->inFarm($b, fn () => $this->app->make(AuditLogger::class)->record('test.b', ['type' => 'thing', 'id' => '2']));

        $actions = array_column($this->asUser($this->ownerOf($a))->getJson("/api/v1/farms/{$a->id}/audit-logs")->assertOk()->json('data'), 'action');

        $this->assertContains('test.a', $actions);
        $this->assertNotContains('test.b', $actions);
    }

    public function test_sensitive_values_are_masked(): void
    {
        $log = $this->app->make(AuditLogger::class)->record('test.secret', null, null, ['password' => 'hunter2', 'nested' => ['token' => 'abc'], 'name' => 'ok']);

        $this->assertSame(['password' => '***', 'nested' => ['token' => '***'], 'name' => 'ok'], $log->new_values);
    }

    public function test_audit_entries_are_append_only(): void
    {
        $log = $this->app->make(AuditLogger::class)->record('test.immutable');

        try {
            $log->update(['action' => 'changed']);
            $this->fail('Model update was allowed.');
        } catch (AppendOnlyViolation) {
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(AppendOnlyViolation::MARKER);
        DB::table('audit_logs')->where('id', $log->id)->delete();
    }

    public function test_failed_logins_are_audited_without_the_password(): void
    {
        $user = $this->member(['email' => 'amina@example.com']);
        $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'hunter2']);

        $entry = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('bad_password', $entry->new_values['reason']);
        $this->assertStringNotContainsString('hunter2', json_encode($entry->new_values));
    }
}
