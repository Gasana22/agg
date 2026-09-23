<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
    }

    public function test_every_signed_in_user_reads_active_catalogue_entries(): void
    {
        $worker = $this->member();

        $crops = $this->asUser($worker)->getJson('/api/v1/catalog/crops')->assertOk()->json('data');
        $this->assertContains('maize', array_column($crops, 'code'));

        $maize = collect($crops)->firstWhere('code', 'maize');
        $varieties = $this->asUser($worker)->getJson("/api/v1/catalog/crop-varieties?filter[parent_id]={$maize['id']}")->json('data');
        $this->assertContains('longe_5', array_column($varieties, 'code'));

        $this->asUser($worker)->getJson('/api/v1/catalog/units?q=acre')->assertOk()->assertJsonPath('data.0.to_base', '0.40468564');
        $this->asUser($worker)->getJson('/api/v1/catalog/spaceships')->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/catalog/crops')->assertUnauthorized();
    }

    public function test_platform_admins_manage_entries_and_deactivate_instead_of_deleting(): void
    {
        $admin = $this->platformAdmin();

        $crop = $this->asUser($admin)->postJson('/api/v1/admin/catalog/crops', ['code' => 'vanilla', 'name' => 'Vanilla', 'category' => 'cash_crop'])
            ->assertCreated()->assertJsonPath('data.is_active', true)->json('data');

        $this->asUser($admin)->postJson('/api/v1/admin/catalog/crops', ['code' => 'vanilla', 'name' => 'Again', 'category' => 'cash_crop'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->asUser($admin)->postJson('/api/v1/admin/catalog/crops', ['code' => 'Bad Code', 'name' => 'X', 'category' => 'cash_crop'])->assertUnprocessable();
        $this->asUser($admin)->patchJson("/api/v1/admin/catalog/crops/{$crop['id']}", ['code' => 'renamed'])->assertUnprocessable();

        $this->asUser($admin)->patchJson("/api/v1/admin/catalog/crops/{$crop['id']}", ['name' => 'Vanilla (Bourbon)', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Vanilla (Bourbon)')->assertJsonPath('data.is_active', false);

        $this->assertNotContains('vanilla', array_column($this->asUser($this->member())->getJson('/api/v1/catalog/crops')->json('data'), 'code'));
        $this->assertContains('vanilla', array_column($this->asUser($admin)->getJson('/api/v1/admin/catalog/crops')->json('data'), 'code'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.catalog_updated']);
    }

    public function test_child_codes_are_unique_within_their_parent(): void
    {
        $admin = $this->platformAdmin();
        $crops = collect($this->asUser($admin)->getJson('/api/v1/admin/catalog/crops')->json('data'))->keyBy('code');

        $this->asUser($admin)->postJson('/api/v1/admin/catalog/crop-varieties', ['crop_id' => $crops['beans']['id'], 'code' => 'longe_5', 'name' => 'Same code, other crop'])->assertCreated();
        $this->asUser($admin)->postJson('/api/v1/admin/catalog/crop-varieties', ['crop_id' => $crops['maize']['id'], 'code' => 'longe_5', 'name' => 'Duplicate'])->assertUnprocessable();
        $this->asUser($admin)->postJson('/api/v1/admin/catalog/crop-varieties', ['crop_id' => '01a0d03d-0000-7000-8000-000000000000', 'code' => 'x', 'name' => 'Orphan'])->assertUnprocessable();
    }

    public function test_unit_conversion_factors_cannot_change_after_creation(): void
    {
        $admin = $this->platformAdmin();
        $kg = collect($this->asUser($admin)->getJson('/api/v1/admin/catalog/units')->json('data'))->firstWhere('code', 'kg');

        $this->asUser($admin)->patchJson("/api/v1/admin/catalog/units/{$kg['id']}", ['to_base' => 2])->assertUnprocessable();
        $this->asUser($admin)->patchJson("/api/v1/admin/catalog/units/{$kg['id']}", ['name' => 'Kilogramme'])->assertOk();
    }

    public function test_only_catalogue_managers_can_write(): void
    {
        $this->assertProblem($this->asUser($this->platformAdmin(['support']))->postJson('/api/v1/admin/catalog/crops', ['code' => 'x', 'name' => 'X', 'category' => 'other']), 403, 'forbidden');
    }
}
