<?php

namespace Tests\Feature\Reporting;

use App\Modules\Catalog\Database\seeders\CatalogSeeder;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reporting\Console\BenchDashboards;
use App\Modules\Reporting\Domain\Models\ReportExport;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 13: metric catalogue, health scores, standard reports, queued
 * exports with their correctness, label print runs, the activity heat map
 * and the dashboard performance gate.
 */
class AnalyticsTest extends TestCase
{
    private Farm $farm;

    private User $owner;

    private User $agronomist;

    private User $keeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Africa/Kampala')->setTime(12, 0)->utc());
        Storage::fake('local');
        $this->seed(CatalogSeeder::class);
        $this->farm = $this->farm();
        $this->owner = $this->ownerOf($this->farm);
        $this->agronomist = $this->memberWithRole($this->farm, 'agronomist');
        $this->keeper = $this->memberWithRole($this->farm, 'livestock_manager');
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    /** Two maize cycles: one healthy, one with a high and a low incident. */
    private function cycles(): array
    {
        $variety = DB::table('global_crop_varieties')->where('code', 'longe_5')->value('id');
        $crop = $this->asUser($this->agronomist)->postJson($this->url('/crops'), ['global_variety_id' => $variety])->assertCreated()->json('data.id');
        $cycles = [];
        foreach (['North', 'South'] as $i => $name) {
            $plot = $this->asUser($this->owner)->postJson($this->url('/structure/plots'), ['name' => $name, 'declared_area_ha' => 2 + $i])->assertCreated()->json('data.id');
            $cycles[] = $this->asUser($this->agronomist)->postJson($this->url('/crop-cycles'), [
                'plot_id' => $plot, 'crop_id' => $crop, 'planted_on' => now()->subDays(60)->toDateString(),
            ])->assertCreated()->json('data');
        }
        foreach ([['high', 'Fall armyworm'], ['low', 'Leaf spot']] as [$severity, $title]) {
            $this->asUser($this->agronomist)->postJson($this->url('/crop-observations'), [
                'cycle_id' => $cycles[1]['id'], 'kind' => $severity === 'high' ? 'pest' : 'disease', 'severity' => $severity, 'title' => $title,
                'latitude' => 0.3476, 'longitude' => 32.5825,
            ])->assertCreated();
        }

        return $cycles;
    }

    private function animal(array $data = []): array
    {
        return $this->asUser($this->keeper)->postJson($this->url('/animals'), $data + [
            'species_id' => DB::table('global_animal_species')->where('code', 'cattle')->value('id'),
            'sex' => 'female', 'origin' => 'purchased', 'acquired_on' => now()->subYear()->toDateString(),
        ])->assertCreated()->json('data');
    }

    public function test_metric_catalogue_lists_what_the_member_may_see_with_definitions_and_series(): void
    {
        $all = collect($this->asUser($this->owner)->getJson($this->url('/metrics'))->assertOk()->json('data'))->keyBy('key');
        $this->assertTrue($all->has('finance.revenue'));
        $this->assertSame('crop', $all['crop.health_score']['module']);
        $this->assertStringContainsString('loses 5, 15, 30 or 50 points', $all['crop.health_score']['description']);
        $this->assertSame(['owner', 'agronomist'], $all['crop.health_score']['dashboards']);
        $this->assertTrue($all['trace.events']['period_based']);
        $this->assertFalse($all['inventory.items']['period_based']);
        $this->assertTrue($all->every(fn ($m) => $m['description'] !== null), 'every metric has a definition');

        $agronomist = collect($this->asUser($this->agronomist)->getJson($this->url('/metrics'))->json('data'))->pluck('key');
        $this->assertContains('crop.health_score', $agronomist);
        $this->assertNotContains('finance.revenue', $agronomist);

        $metric = $this->asUser($this->owner)->getJson($this->url('/metrics/trace.events?period=7d'))->assertOk()->json('data');
        $this->assertSame('7d', $metric['period']['key']);
        $this->assertCount(7, $metric['series']);
        $this->assertSame(now('Africa/Kampala')->toDateString(), end($metric['series'])['label']);
        $this->assertEquals($metric['value'], array_sum(array_column($metric['series'], 'value')), 'the series adds up to the period value');

        $ytd = $this->asUser($this->owner)->getJson($this->url('/metrics/finance.revenue?period=90d'))->assertOk()->json('data');
        $this->assertLessThanOrEqual(31, count($ytd['series']), '90 days are shown per week');

        $this->asUser($this->owner)->getJson($this->url('/metrics/nope.nope'))->assertNotFound();
        $this->assertProblem($this->asUser($this->agronomist)->getJson($this->url('/metrics/finance.revenue')), 403, 'forbidden');
    }

    public function test_crop_and_animal_health_scores_explain_their_deductions(): void
    {
        $cycles = $this->cycles();

        $dash = $this->asUser($this->agronomist)->getJson($this->url('/dashboards/agronomist'))->assertOk()->json('data');
        $kpi = collect($dash['kpis'])->keyBy('key')['crop.health_score'];
        // (100 + (100 − 30 − 5)) / 2
        $this->assertSame(['value' => '82.5', 'unit' => '/100'], $kpi['value']);
        $this->assertSame(['good' => 1, 'watch' => 1, 'poor' => 0], $kpi['meta']['bands']);

        $map = collect($dash['widgets'])->keyBy('key')['crop_health'];
        $this->assertSame('health_map', $map['type']);
        $this->assertSame($cycles[1]['id'], $map['data']['points'][0]['id'], 'worst first');
        $this->assertSame(65, $map['data']['points'][0]['score']);
        $this->assertSame('watch', $map['data']['points'][0]['band']);
        $this->assertStringContainsString('High: Fall armyworm', $map['data']['points'][0]['subtitle']);

        $cow = $this->animal(['name' => 'Bella']);
        $this->animal(['name' => 'Kisa']);
        $this->asUser($this->keeper)->postJson($this->url('/animal-health'), [
            'animal_id' => $cow['id'], 'kind' => 'treatment', 'given_on' => now()->subDays(3)->toDateString(), 'diagnosis' => 'Mastitis', 'product_name' => 'Oxytetracycline',
        ])->assertCreated();
        $this->asUser($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $cow['id'], 'weighed_on' => now()->subDays(40)->toDateString(), 'weight_kg' => 420])->assertCreated();
        $this->asUser($this->keeper)->postJson($this->url('/animal-weights'), ['animal_id' => $cow['id'], 'weighed_on' => now()->subDay()->toDateString(), 'weight_kg' => 390])->assertCreated();

        $herd = $this->asUser($this->keeper)->getJson($this->url('/dashboards/livestock'))->assertOk()->json('data');
        // Bella: 100 − 25 (weight loss) − 15 (treated) = 60; Kisa 100.
        $this->assertSame(['value' => '80.0', 'unit' => '/100'], collect($herd['kpis'])->keyBy('key')['livestock.health_score']['value']);
        $list = collect($herd['widgets'])->keyBy('key')['animal_health']['data'];
        $this->assertCount(1, $list['items']);
        $this->assertSame('60/100', $list['items'][0]['badge']['label']);
        $this->assertSame('Lost 5% or more weight · Treated in the last 30 days', $list['items'][0]['subtitle']);
    }

    public function test_standard_reports_follow_permissions_and_hide_money_columns(): void
    {
        $this->cycles();
        $keys = collect($this->asUser($this->owner)->getJson($this->url('/standard-reports'))->assertOk()->json('data'))->pluck('key');
        $this->assertContains('aged_receivables', $keys);
        $this->assertContains('stock_valuation', $keys);
        $this->assertCount(19, $keys);

        $agronomist = collect($this->asUser($this->agronomist)->getJson($this->url('/standard-reports'))->json('data'))->pluck('key')->all();
        $this->assertContains('harvests', $agronomist);
        $this->assertNotContains('profit_and_loss', $agronomist);
        $this->assertNotContains('stock_valuation', $agronomist);
        $this->assertProblem($this->asUser($this->agronomist)->getJson($this->url('/standard-reports/profit_and_loss')), 403, 'report_not_available');
        $this->asUser($this->owner)->getJson($this->url('/standard-reports/nope'))->assertNotFound();

        // Field work: the cost column needs finance.values.view.
        $ops = $this->asUser($this->agronomist)->getJson($this->url('/standard-reports/crop_operations'))->assertOk()->json('data');
        $this->assertNotContains('cost', array_column($ops['columns'], 'key'));
        $this->assertContains('cost', array_column($this->asUser($this->owner)->getJson($this->url('/standard-reports/crop_operations'))->json('data.columns'), 'key'));

        $this->asUser($this->owner)->getJson($this->url('/standard-reports/harvests?from=2026-02-01&to=2026-01-01'))->assertStatus(422);
        $this->asUser($this->owner)->getJson($this->url('/standard-reports/expiring_lots?days=999'))->assertStatus(422);
    }

    public function test_exports_match_the_preview_in_every_format(): void
    {
        $this->cycles();
        // A supplier invoice overdue for 40 days and one not yet due.
        $this->inFarm($this->farm, function () {
            DB::table('suppliers')->insert(['id' => $supplier = (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'code' => 'SUP-001', 'name' => '=Agro "Vet", Ltd',
                'is_active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('purchase_orders')->insert(['id' => $order = (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'code' => 'PO-0001', 'supplier_id' => $supplier,
                'status' => 'received', 'currency' => 'UGX', 'total_amount' => 1500000.5, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ([['SI-1', 1200000.5, now()->subDays(40)], ['SI-2', 300000, now()->addDays(5)]] as [$code, $amount, $due]) {
                DB::table('supplier_invoices')->insert(['id' => (string) Str::uuid7(), 'farm_id' => $this->farm->id, 'code' => $code, 'invoice_number' => $code,
                    'supplier_id' => $supplier, 'order_id' => $order, 'invoice_date' => now()->subDays(60)->toDateString(), 'due_on' => $due->toDateString(), 'amount' => $amount,
                    'paid_amount' => 0, 'status' => 'recorded', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $preview = $this->asUser($this->owner)->getJson($this->url('/standard-reports/aged_payables'))->assertOk()->json('data');
        $this->assertSame([['supplier' => '=Agro "Vet", Ltd (SUP-001)', 'invoices' => 2, 'current' => '300000.00', 'd1_30' => '0.00', 'd31_60' => '1200000.50',
            'd61_90' => '0.00', 'd90_plus' => '0.00', 'total' => '1500000.50']], $preview['rows']);
        $this->assertSame('1500000.50', $preview['totals']['total']);

        $files = [];
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $export = $this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'aged_payables', 'format' => $format])
                ->assertStatus(202)->json('data');
            // The queue runs inline in tests: the export is built already.
            $this->assertSame('ready', $export['status'], json_encode($export));
            $this->assertSame(1, $export['row_count']);
            $response = $this->asUser($this->owner)->get($this->url("/exports/{$export['id']}/download"))->assertOk();
            $this->assertStringStartsWith(ReportExport::MIME[$format], $response->headers->get('Content-Type'));
            $files[$format] = $response->streamedContent();
        }

        // CSV: BOM, header labels, the formula-looking name defused, totals.
        $lines = array_map('str_getcsv', explode("\n", trim(substr($files['csv'], 3))));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $files['csv']);
        $this->assertSame(['Supplier', 'Invoices', 'Not yet due', '1–30 days', '31–60 days', '61–90 days', 'Over 90 days', 'Total'], $lines[0]);
        $this->assertSame(["'=Agro \"Vet\", Ltd (SUP-001)", '2', '300000.00', '0.00', '1200000.50', '0.00', '0.00', '1500000.50'], $lines[1]);
        $this->assertSame(['Total', '2', '300000.00', '0.00', '1200000.50', '0.00', '0.00', '1500000.50'], $lines[2]);

        // XLSX: a valid workbook with the same cells, numbers as numbers.
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $files['xlsx']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $sheet = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($path);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $rows[] = array_map(fn ($c) => isset($c->is) ? (string) $c->is->t : (string) $c->v, iterator_to_array($row->c, false));
        }
        $this->assertSame('=Agro "Vet", Ltd (SUP-001)', $rows[1][0], 'an inline string is never a formula');
        $this->assertSame('1500000.5', $rows[1][7]);
        $this->assertSame(['Total', '2', '300000', '0', '1200000.5', '0', '0', '1500000.5'], $rows[2]);

        // PDF: a document with the title, the row and the totals.
        $this->assertStringStartsWith('%PDF-1.4', $files['pdf']);
        $this->assertStringEndsWith("%%EOF\n", $files['pdf']);
        $this->assertStringContainsString('(Aged payables) Tj', $files['pdf']);
        $this->assertStringContainsString('(1,500,000.50) Tj', $files['pdf']);
        $this->assertStringContainsString('landscape', $this->pdfOrientation($files['pdf']));
    }

    private function pdfOrientation(string $pdf): string
    {
        preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $pdf, $m);

        return (float) $m[1] > (float) $m[2] ? 'landscape' : 'portrait';
    }

    public function test_exports_are_private_limited_and_expire_after_a_day(): void
    {
        $this->cycles();
        $mine = $this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'harvests', 'format' => 'csv'])->assertStatus(202)->json('data');

        // Another member cannot see or download it; the agronomist cannot export at all.
        $this->asUser($this->agronomist)->getJson($this->url("/exports/{$mine['id']}"))->assertNotFound();
        $this->asUser($this->agronomist)->get($this->url("/exports/{$mine['id']}/download"))->assertNotFound();
        $this->assertSame([], $this->asUser($this->agronomist)->getJson($this->url('/exports'))->json('data'));
        $this->assertProblem($this->asUser($this->agronomist)->postJson($this->url('/exports'), ['report' => 'harvests', 'format' => 'csv']), 403, 'export_not_allowed');
        $this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'nope', 'format' => 'csv'])->assertStatus(422);
        $this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'harvests', 'format' => 'docx'])->assertStatus(422);

        // The farm's limit on exports in progress.
        $this->inFarm($this->farm, fn () => ReportExport::whereKey($mine['id'])->update(['status' => 'queued']));
        $this->inFarm($this->farm, function () {
            foreach ([1, 2] as $i) {
                ReportExport::create(['farm_id' => $this->farm->id, 'kind' => 'report', 'report' => 'harvests', 'title' => 'Harvests', 'params' => [], 'format' => 'csv', 'status' => 'running', 'requested_by' => $this->owner->id]);
            }
        });
        $this->assertProblem($this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'harvests', 'format' => 'pdf']), 429, 'export_limit');
        $this->inFarm($this->farm, fn () => ReportExport::whereIn('status', ['queued', 'running'])->update(['status' => 'failed']));

        $ready = $this->asUser($this->owner)->postJson($this->url('/exports'), ['report' => 'harvests', 'format' => 'pdf'])->assertStatus(202)->json('data');
        $this->assertSame('ready', $ready['status']);
        $this->assertTrue($this->inFarm($this->farm, fn () => DB::table('member_notifications')->where('kind', 'export_ready')->where('user_id', $this->owner->id)->exists()));

        $this->travel(25)->hours();
        $this->asUser($this->owner)->getJson($this->url("/exports/{$ready['id']}"))->assertJsonPath('data.status', 'expired')->assertJsonPath('data.download_path', null);
        $this->assertProblem($this->asUser($this->owner)->get($this->url("/exports/{$ready['id']}/download")), 410, 'export_expired');
        Artisan::call('exports:prune');
        $this->assertSame([], Storage::disk('local')->allFiles("farms/{$this->farm->id}/exports/{$ready['id']}.pdf") ?: []);
        $this->assertFalse(Storage::disk('local')->exists("farms/{$this->farm->id}/exports/{$ready['id']}.pdf"));
        $this->assertSame('expired', $this->inFarm($this->farm, fn () => ReportExport::find($ready['id'])->status));
    }

    public function test_bulk_label_runs_use_the_chosen_template(): void
    {
        $ids = [];
        foreach (['Maize 50 kg', 'Beans 25 kg'] as $name) {
            $batch = $this->asUser($this->owner)->postJson($this->url('/traceability/batches'), ['kind' => 'packaged', 'name' => $name, 'quantity' => 10, 'unit' => 'bag'])->assertCreated()->json('data.id');
            $this->asUser($this->owner)->postJson($this->url("/traceability/batches/{$batch}/approvals"), ['public_fields' => ['product', 'farm']])->assertCreated();
            $ids[] = $this->asUser($this->owner)->postJson($this->url("/traceability/batches/{$batch}/qr-codes"))->assertCreated()->json('data');
        }

        $export = $this->asUser($this->owner)->postJson($this->url('/exports'), [
            'kind' => 'labels', 'template' => 'a4_4x10',
            'labels' => [['qr_code_id' => $ids[0]['id'], 'copies' => 30], ['qr_code_id' => $ids[1]['id'], 'copies' => 20]],
        ])->assertStatus(202)->json('data');
        $this->assertSame('ready', $export['status']);
        $this->assertSame(50, $export['row_count']);
        $pdf = $this->asUser($this->owner)->get($this->url("/exports/{$export['id']}/download"))->assertOk()->streamedContent();
        $this->assertStringContainsString('/Count 2', $pdf, '50 labels on sheets of 40');
        $this->assertStringContainsString("({$ids[0]['code']}) Tj", $pdf);
        $this->assertStringContainsString("({$ids[1]['code']}) Tj", $pdf);
        $this->assertSame(30, substr_count($pdf, '/QR0 Do'));
        $this->assertSame(20, substr_count($pdf, '/QR1 Do'));

        $this->asUser($this->owner)->postJson($this->url('/exports'), ['kind' => 'labels', 'labels' => [['qr_code_id' => (string) Str::uuid7()]]])->assertStatus(422);
        $this->asUser($this->owner)->get($this->url("/traceability/qr-codes/{$ids[0]['id']}/labels.pdf?template=a4_2x7&copies=15"))->assertOk();
    }

    public function test_activity_heat_map_counts_located_work_per_cell(): void
    {
        $this->cycles();   // two incidents at the same spot
        $store = $this->memberWithRole($this->farm, 'store_manager');
        $this->assertContains('gps', $this->asUser($store)->getJson($this->url('/maps/activity'))->assertOk()->json('data.hidden_layers'), 'the store manager cannot see worker GPS');
        $this->assertNotContains('observations', array_column($this->asUser($store)->getJson($this->url('/maps/activity'))->json('data.layers'), 'key'));
        $heat = $this->asUser($this->agronomist)->getJson($this->url('/maps/activity?period=7d&cell=100'))->assertOk()->json('data');
        $this->assertSame(100, $heat['cell_m']);
        $this->assertContains('observations', array_column($heat['layers'], 'key'));
        // Both reports were made at the same spot: one cell, counted per layer
        // (each report also records a located traceability event).
        $this->assertCount(1, $heat['cells']);
        $this->assertSame(2, $heat['cells'][0]['layers']['observations']);
        $this->assertSame(array_sum($heat['cells'][0]['layers']), $heat['cells'][0]['count']);
        $this->assertSame($heat['cells'][0]['count'], $heat['max']);
        $this->assertEqualsWithDelta(0.3476, $heat['cells'][0]['lat'], 0.001);

        $empty = $this->asUser($this->agronomist)->getJson($this->url('/maps/activity?period=custom&from=2020-01-01&to=2020-01-31'))->json('data');
        $this->assertSame([], $empty['cells']);
        $this->assertNull($empty['bbox']);
    }

    public function test_dashboards_stay_within_the_response_time_budget(): void
    {
        $this->cycles();
        $this->animal();
        $code = Artisan::call('reporting:bench', ['farm' => $this->farm->id, '--runs' => 5, '--json' => true]);
        $result = json_decode(Artisan::output(), true);
        foreach ($result['results'] as $row) {
            $this->assertLessThan(BenchDashboards::BUDGET_COLD_MS, $row['cold_p95'], "{$row['dashboard']} cold");
            $this->assertLessThan(BenchDashboards::BUDGET_WARM_MS, $row['warm_p95'], "{$row['dashboard']} cached");
        }
        $this->assertSame(0, $code);
    }
}
