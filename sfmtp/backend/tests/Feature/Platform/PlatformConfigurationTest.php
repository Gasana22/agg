<?php

namespace Tests\Feature\Platform;

use App\Modules\Identity\Application\TokenService;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Users, settings, integrations and system pages. */
class PlatformConfigurationTest extends TestCase
{
    public function test_settings_have_defaults_and_are_validated(): void
    {
        $admin = $this->platformAdmin();

        $settings = collect($this->asUser($admin)->getJson('/api/v1/admin/settings')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(7, $settings['billing.grace_days']['value']);

        $this->asUser($admin)->putJson('/api/v1/admin/settings', ['settings' => ['billing.grace_days' => 10]])->assertOk();
        $this->assertSame(10, collect($this->asUser($admin)->getJson('/api/v1/admin/settings')->json('data'))->firstWhere('key', 'billing.grace_days')['value']);

        $this->asUser($admin)->putJson('/api/v1/admin/settings', ['settings' => ['billing.grace_days' => 500]])->assertUnprocessable()->assertJsonValidationErrors('settings.billing.grace_days');
        $this->asUser($admin)->putJson('/api/v1/admin/settings', ['settings' => ['billing.default_plan_code' => 'gold']])->assertUnprocessable();
        $this->asUser($admin)->putJson('/api/v1/admin/settings', ['settings' => ['platform.coffee' => 'yes']])->assertUnprocessable();

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.settings_updated']);
    }

    public function test_integration_secrets_are_encrypted_and_never_returned(): void
    {
        $admin = $this->platformAdmin();

        $created = $this->asUser($admin)->postJson('/api/v1/admin/integrations', [
            'kind' => 'sms', 'provider' => 'africas_talking', 'name' => "Africa's Talking",
            'config' => ['username' => 'sfmtp', 'api_key' => 'atsk_live_1234567890abcd'], 'is_default' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('sfmtp', $created['config']['username']);
        $this->assertSame('••••abcd', $created['config']['api_key']);
        $this->assertStringNotContainsString('atsk_live', DB::table('integration_providers')->value('config'));
        $this->assertStringNotContainsString('atsk_live', json_encode(DB::table('audit_logs')->pluck('new_values')));

        // Sending the masked value back keeps the secret; null removes a key.
        $this->asUser($admin)->patchJson("/api/v1/admin/integrations/{$created['id']}", ['config' => ['api_key' => '••••abcd', 'username' => null, 'sender_id' => 'SFMTP']])->assertOk();
        $this->assertSame(['api_key' => 'atsk_live_1234567890abcd', 'sender_id' => 'SFMTP'], IntegrationProvider::first()->config);
    }

    public function test_only_one_default_per_kind_and_providers_must_match_the_kind(): void
    {
        $admin = $this->platformAdmin();
        $a = $this->asUser($admin)->postJson('/api/v1/admin/integrations', ['kind' => 'sms', 'provider' => 'africas_talking', 'name' => 'AT', 'is_default' => true])->json('data');
        $this->asUser($admin)->postJson('/api/v1/admin/integrations', ['kind' => 'sms', 'provider' => 'twilio', 'name' => 'Twilio', 'is_default' => true])->assertCreated();

        $this->assertFalse(IntegrationProvider::find($a['id'])->is_default);
        $this->asUser($admin)->postJson('/api/v1/admin/integrations', ['kind' => 'sms', 'provider' => 'mapbox', 'name' => 'Nope'])->assertUnprocessable();
        $this->asUser($admin)->postJson('/api/v1/admin/integrations', ['kind' => 'sms', 'provider' => 'twilio', 'name' => 'Again'])->assertUnprocessable();
        $this->asUser($admin)->deleteJson("/api/v1/admin/integrations/{$a['id']}")->assertNoContent();
    }

    public function test_disabling_an_account_signs_it_out_everywhere(): void
    {
        $admin = $this->platformAdmin();
        $farmer = $this->member();
        $farmerToken = $this->app->make(TokenService::class)->issue($farmer, 'web', null)->accessToken;
        $this->withToken($farmerToken)->getJson('/api/v1/me')->assertOk();

        $this->asUser($admin)->patchJson("/api/v1/admin/users/{$farmer->id}", ['status' => 'disabled'])->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->app['auth']->forgetGuards();
        $this->withToken($farmerToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertProblem($this->asUser($admin)->patchJson("/api/v1/admin/users/{$admin->id}", ['status' => 'disabled']), 403, 'cannot_modify_self');
    }

    public function test_staff_can_be_invited_with_platform_roles(): void
    {
        Notification::fake();
        $admin = $this->platformAdmin();

        $staff = $this->asUser($admin)->postJson('/api/v1/admin/users', ['name' => 'Sam', 'email' => 'sam@sfmtp.test', 'platform_roles' => ['support']])
            ->assertCreated()->assertJsonPath('data.user_type', 'platform_admin')->assertJsonPath('data.platform_roles', ['support'])->json('data');

        $this->asUser($admin)->patchJson("/api/v1/admin/users/{$staff['id']}", ['platform_roles' => ['support', 'billing']])->assertOk()
            ->assertJsonPath('data.platform_roles', ['billing', 'support']);
        $this->asUser($admin)->patchJson("/api/v1/admin/users/{$this->member()->id}", ['platform_roles' => ['support']])->assertUnprocessable();
        $this->asUser($admin)->getJson('/api/v1/admin/users?filter[user_type]=platform_admin')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_system_pages_report_health_backups_and_platform_audit(): void
    {
        $admin = $this->platformAdmin(['support']);
        Artisan::call('platform:record-backup', ['status' => 'success', '--location' => '/backups/x.dump', '--size' => 1024]);

        $this->asUser($admin)->getJson('/api/v1/admin/system/health')->assertOk()->assertJsonPath('data.checks.database.status', 'ok');
        $this->asUser($admin)->getJson('/api/v1/admin/system/backups')->assertOk()->assertJsonPath('data.0.status', 'success')->assertJsonPath('data.0.size_bytes', 1024);
        $this->asUser($admin)->getJson('/api/v1/admin/system/failed-jobs')->assertOk();

        $kpis = collect($this->asUser($admin)->getJson('/api/v1/admin/dashboard')->json('data.kpis'))->keyBy('key');
        $this->assertSame('ok', $kpis['backup.last']['value']);

        $this->asUser($this->platformAdmin())->putJson('/api/v1/admin/settings', ['settings' => ['billing.trial_days' => 14]]);
        $actions = array_column($this->asUser($admin)->getJson('/api/v1/admin/system/audit-logs')->assertOk()->json('data'), 'action');
        $this->assertContains('admin.settings_updated', $actions);
    }
}
