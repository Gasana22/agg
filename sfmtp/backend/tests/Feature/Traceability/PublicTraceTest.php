<?php

namespace Tests\Feature\Traceability;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Farm;
use App\Modules\Traceability\Application\PublicPayload;
use App\Modules\Traceability\Application\PublicSigner;
use App\Modules\Traceability\Application\Recorder;
use App\Modules\Traceability\Domain\Enums\BatchKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 gate (docs/10): the public payload holds only approved fields,
 * a revoked code shows a notice, and the public rate limit holds.
 */
class PublicTraceTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private string $packed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->farm->forceFill(['district' => 'Mukono', 'country' => 'UG'])->save();
        $this->owner = $this->ownerOf($this->farm);
        $this->packed = $this->scenario();
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}/traceability{$path}";
    }

    /** Maize from a seed lot on plot B-3, sprayed, harvested, dried and packed; a worker's GPS on the spray. */
    private function scenario(): string
    {
        $o = $this->asUser($this->owner);
        $plot = $o->postJson("/api/v1/farms/{$this->farm->id}/structure/plots", ['name' => 'B-3', 'code' => 'B-3', 'declared_area_ha' => 2])->json('data.id');
        $crop = $o->postJson("/api/v1/farms/{$this->farm->id}/crops", ['global_variety_id' => DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id')])->json('data.id');
        $seed = $this->inFarm($this->farm, fn () => app(Recorder::class)->createBatch(BatchKind::SeedLot, ['name' => 'Longe 5 seed', 'quantity' => '25', 'unit' => 'kg'],
            ['payload' => ['lot_number' => 'SC-2291', 'supplier' => 'Seed Co Ltd', 'unit_price' => 9000]]));
        $cycle = $o->postJson("/api/v1/farms/{$this->farm->id}/crop-cycles", ['plot_id' => $plot, 'crop_id' => $crop, 'seed_batch_id' => $seed->id, 'planted_on' => now()->subDays(130)->toDateString()])->assertCreated()->json('data');
        $o->postJson("/api/v1/farms/{$this->farm->id}/crop-operations", ['cycle_id' => $cycle['id'], 'type' => 'spraying', 'occurred_at' => now()->subDays(60)->toIso8601String(),
            'latitude' => 0.3412345, 'longitude' => 32.5812345, 'inputs' => [['product_name' => 'Emamectin benzoate', 'quantity' => 0.4, 'unit' => 'kg', 'withholding_days' => 14]]])->assertCreated();
        $harvest = $o->postJson("/api/v1/farms/{$this->farm->id}/harvests", ['cycle_id' => $cycle['id'], 'harvested_on' => now()->subDays(10)->toDateString(), 'quantity' => 1000, 'unit' => 'kg'])->json('data.batch.id');
        $dried = $o->postJson($this->url("/batches/{$harvest}/process"), ['output' => ['name' => 'Dried maize', 'quantity' => 950, 'unit' => 'kg', 'method' => 'Sun-dried']])->json('data.id');
        $packed = $o->postJson($this->url("/batches/{$dried}/package"), ['output' => ['name' => 'Maize grain 50 kg bags', 'package_count' => 19, 'package_size' => '50 kg']])->assertCreated()->json('data.id');
        $o->postJson($this->url("/batches/{$packed}/events"), ['event_type' => 'certification', 'payload' => ['note' => 'UNBS quality mark Q-2291']])->assertCreated();

        return $packed;
    }

    private function publish(array $fields): array
    {
        $this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/approvals"), ['public_fields' => $fields])->assertCreated();

        return $this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/qr-codes"), ['label' => 'Bags run 1'])->assertCreated()->json('data');
    }

    public function test_the_public_page_shows_only_approved_fields(): void
    {
        // A code needs an approval first.
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/qr-codes")), 422, 'not_approved');
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/approvals"), ['public_fields' => ['product', 'cost']]), 422, 'validation_failed');

        // The approver previews exactly what will be public.
        $preview = $this->asUser($this->owner)->getJson($this->url("/batches/{$this->packed}/public-preview?fields[]=product&fields[]=region"))->assertOk()->json('data');
        $this->assertSame(['product', 'region'], array_keys($preview));

        $qr = $this->publish(['product', 'farm', 'region', 'crop', 'dates', 'processing']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{10}$/', $qr['code']);
        $this->assertStringEndsWith("/q/{$qr['code']}", $qr['url']);

        $public = $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $data = $public['data'];
        $this->assertSame('active', $data['status']);
        $this->assertNull($data['notice']);
        $this->assertSame(['product', 'farm', 'region', 'crop', 'dates', 'processing'], array_keys($data['fields']));
        $this->assertEquals(['name' => 'Maize grain 50 kg bags', 'kind' => 'Packaged'], $data['fields']['product']);
        $this->assertEquals(['district' => 'Mukono', 'country' => 'UG'], $data['fields']['region']);
        $this->assertSame(['Maize (Longe 5)'], $data['fields']['crop']);
        $this->assertEqualsCanonicalizing(['planted', 'harvested', 'processed', 'packed'], array_keys($data['fields']['dates']));
        $this->assertSame(['Processed', 'Packed'], array_column($data['fields']['processing'], 'step'));

        // Nothing unapproved or never-public anywhere in the response.
        $json = json_encode($public);
        foreach (['batch_code', 'B-3', 'SC-2291', 'Seed Co', 'Emamectin', 'UNBS', 'unit_price', '9000', 'latitude', '0.341', '32.58', 'worker', 'actor', $this->owner->name, $this->owner->email, 'quantity', '950'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked");
        }

        // Approving more fields widens the page; never-public values still do not appear.
        $this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/approvals"), ['public_fields' => array_keys(PublicPayload::FIELDS)])->assertCreated();
        $all = $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk()->json();
        $fields = $all['data']['fields'];
        $this->assertSame(['B-3'], $fields['origin']);
        $this->assertEquals([['name' => 'Longe 5 seed', 'lot_number' => 'SC-2291', 'supplier' => 'Seed Co Ltd']], $fields['seed_source']);
        $this->assertSame('Emamectin benzoate', $fields['inputs'][0]['product']);
        $this->assertSame(14, $fields['inputs'][0]['withholding_days']);
        $this->assertSame('UNBS quality mark Q-2291', $fields['certifications'][0]['note']);
        $this->assertSame(['Seed lot', 'Crop lot', 'Harvest', 'Processed', 'Packaged'], array_column($fields['journey'], 'kind'));
        $json = json_encode($all);
        foreach (['unit_price', '9000', 'latitude', '0.341', '32.58', $this->owner->name, $this->owner->email, 'worker'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "{$secret} leaked with every field approved");
        }

        // Lower case and look-alike letters still find the code; unknown codes are 404.
        $this->getJson('/api/v1/traceability/qr/'.strtolower($qr['code']))->assertOk();
        $this->getJson('/api/v1/public/trace/ZZZZZZZZZZ')->assertNotFound();
    }

    public function test_the_payload_is_signed(): void
    {
        $qr = $this->publish(['product', 'farm']);
        $res = $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk()->json();
        $key = $this->getJson('/api/v1/public/trace/keys')->assertOk()->json('data.0');

        $this->assertSame('Ed25519', $res['signature']['alg']);
        $this->assertSame($key['key_id'], $res['signature']['key_id']);
        $message = PublicSigner::canonical($res['data']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($res['signature']['value']), $message, base64_decode($key['public_key'])));
        $res['data']['fields']['farm'] = 'Someone else';
        $this->assertFalse(sodium_crypto_sign_verify_detached(base64_decode($res['signature']['value']), PublicSigner::canonical($res['data']), base64_decode($key['public_key'])));
    }

    public function test_a_revoked_code_and_a_recall_show_a_notice(): void
    {
        $qr = $this->publish(['product', 'farm', 'region', 'dates']);
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url("/qr-codes/{$qr['id']}/revoke"), ['reason' => 'x']), 422, 'validation_failed');
        $this->asUser($this->owner)->postJson($this->url("/qr-codes/{$qr['id']}/revoke"), ['reason' => 'Label printed with the wrong date'])->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url("/qr-codes/{$qr['id']}/revoke"), ['reason' => 'again']), 409, 'invalid_state_transition');

        $withdrawn = $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk()->json('data');
        $this->assertSame('withdrawn', $withdrawn['status']);
        $this->assertSame('withdrawn', $withdrawn['notice']['type']);
        $this->assertSame(['product', 'farm'], array_keys($withdrawn['fields']));
        $this->assertStringNotContainsString('wrong date', json_encode($withdrawn), 'the internal reason stays internal');

        // A recall revokes the other codes and shows the recall notice.
        $second = $this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/qr-codes"))->assertCreated()->json('data');
        $this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/recall"), ['reason' => 'Aflatoxin above limit'])->assertOk();
        $this->asUser($this->owner)->getJson($this->url("/qr-codes/{$second['id']}"))->assertJsonPath('data.status', 'revoked');
        $recalled = $this->getJson("/api/v1/public/trace/{$second['code']}")->assertOk()->json('data');
        $this->assertSame('recalled', $recalled['status']);
        $this->assertSame('This product has been recalled', $recalled['notice']['title']);
        $this->assertStringNotContainsString('Aflatoxin', json_encode($recalled));
        // A recalled batch cannot be published again.
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url("/batches/{$this->packed}/approvals"), ['public_fields' => ['product']]), 409, 'trace_batch_recalled');
    }

    public function test_scans_are_counted_without_personal_data_and_rate_limited(): void
    {
        RateLimiter::clear('public:127.0.0.1');
        $qr = $this->publish(['product']);
        $this->getJson("/api/v1/public/trace/{$qr['code']}", ['CF-IPCountry' => 'ug'])->assertOk();
        $this->getJson("/api/v1/public/trace/{$qr['code']}", ['CF-IPCountry' => 'KE'])->assertOk();
        $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk();

        $this->asUser($this->owner)->getJson($this->url("/qr-codes/{$qr['id']}"))->assertOk()
            ->assertJsonPath('data.scan_count', 3)
            ->assertJsonPath('meta.scans.total', 3);
        $stats = $this->asUser($this->owner)->getJson($this->url('/qr-stats?days=7'))->assertOk()->json('data');
        $this->assertSame(3, $stats['total']);
        $this->assertEqualsCanonicalizing(['UG', 'KE', 'ZZ'], array_column($stats['countries'], 'country'));
        $this->assertSame(['farm_id', 'qr_code_id', 'day', 'country', 'scans'], Schema::getColumnListing('trace_qr_scans'));

        // 60 a minute per IP: the 61st is refused.
        for ($i = 4; $i <= 60; $i++) {
            $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk();
        }
        $this->getJson("/api/v1/public/trace/{$qr['code']}")->assertStatus(429);
        $this->getJson('/api/v1/public/trace/keys')->assertStatus(429);
        // Another address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->getJson("/api/v1/public/trace/{$qr['code']}")->assertOk();
    }

    public function test_labels_and_permissions(): void
    {
        $qr = $this->publish(['product', 'farm']);
        $pdf = $this->asUser($this->owner)->get($this->url("/qr-codes/{$qr['id']}/labels.pdf?copies=30"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $body = $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $body);
        $this->assertStringContainsString('/Count 2', $body, '30 labels need two A4 sheets of 24');
        $this->assertStringContainsString("({$qr['code']}) Tj", $body);
        $this->assertStringEndsWith("%%EOF\n", $body);
        $svg = $this->asUser($this->owner)->get($this->url("/qr-codes/{$qr['id']}/image.svg"))->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->getContent();
        $this->assertStringContainsString('<svg', $svg);

        // Store staff see codes but cannot publish or revoke; field workers see nothing.
        $store = $this->memberWithRole($this->farm, 'store_manager');
        $this->asUser($store)->getJson($this->url('/qr-codes'))->assertOk()->assertJsonPath('data.0.code', $qr['code']);
        $this->asUser($store)->postJson($this->url("/batches/{$this->packed}/approvals"), ['public_fields' => ['product']])->assertForbidden();
        $this->asUser($store)->postJson($this->url("/batches/{$this->packed}/qr-codes"))->assertForbidden();
        $this->asUser($store)->postJson($this->url("/qr-codes/{$qr['id']}/revoke"), ['reason' => 'nope nope'])->assertForbidden();
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $this->asUser($worker)->getJson($this->url('/qr-codes'))->assertForbidden();
        // The agronomist publishes crop products.
        $agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->asUser($agronomist)->postJson($this->url("/batches/{$this->packed}/qr-codes"))->assertCreated();

        // History: approvals are listed with the field catalogue, and publishing is on the batch's record.
        $approvals = $this->asUser($store)->getJson($this->url("/batches/{$this->packed}/approvals"))->assertOk()->json();
        $this->assertTrue($approvals['data'][0]['current']);
        $this->assertContains('seed_source', array_column($approvals['meta']['fields'], 'key'));
        $types = collect($this->asUser($store)->getJson($this->url("/batches/{$this->packed}/events"))->json('data'))->pluck('event_type')->all();
        $this->assertContains('public_fields_approved', $types);
        $this->assertContains('qr_issued', $types);
    }
}
