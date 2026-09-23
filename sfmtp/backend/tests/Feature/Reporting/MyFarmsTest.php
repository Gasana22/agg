<?php

namespace Tests\Feature\Reporting;

use Tests\Feature\FarmStructure\FarmStructureTest;
use Tests\TestCase;

class MyFarmsTest extends TestCase
{
    public function test_overview_lists_each_farm_with_the_numbers_the_member_may_see(): void
    {
        $owner = $this->withMfa($this->member());
        $crop = $this->farm($owner, ['name' => 'B Crop Farm']);
        $mixed = $this->farm($owner, ['name' => 'A Mixed Farm']);
        $this->asUser($owner)->postJson("/api/v1/farms/{$crop->id}/structure/plots", ['name' => 'P', 'boundary' => FarmStructureTest::square(30, 0, 0.01)])->assertCreated();

        $cards = $this->asUser($owner)->getJson('/api/v1/me/farms/overview')->assertOk()->json('data');
        $this->assertSame(['A Mixed Farm', 'B Crop Farm'], array_column($cards, 'name'));
        $this->assertSame(1, $cards[1]['metrics']['plots']);
        $this->assertEqualsWithDelta(123.9, $cards[1]['metrics']['mapped_area_ha'], 0.5);
        $this->assertSame(0, $cards[0]['metrics']['plots']);
        $this->assertSame('owner', $cards[0]['home_dashboard']);

        // A field worker sees the farm, not its member or batch counts.
        $worker = $this->memberWithRole($mixed, 'field_worker');
        $card = $this->asUser($worker)->getJson('/api/v1/me/farms/overview')->assertOk()->json('data.0');
        $this->assertSame(['Field Worker'], $card['roles']);
        $this->assertArrayNotHasKey('members', $card['metrics']);
        $this->assertArrayNotHasKey('open_batches', $card['metrics']);
    }

    public function test_owner_dashboard_shows_structure_kpis_and_checklist(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);

        $summary = $this->asUser($owner)->getJson("/api/v1/farms/{$farm->id}/dashboards/owner")->assertOk()->json('data');
        $this->assertContains('structure.plots', array_column($summary['kpis'], 'key'));
        $this->assertContains('invite_member', array_column($summary['quick_actions'], 'key'));

        $checklist = collect($summary['widgets'])->firstWhere('key', 'setup_checklist')['data']['items'];
        $this->assertFalse(collect($checklist)->firstWhere('key', 'structure')['done']);
    }
}
