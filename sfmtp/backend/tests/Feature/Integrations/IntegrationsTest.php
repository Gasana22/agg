<?php

namespace Tests\Feature\Integrations;

use App\Modules\Billing\Domain\Models\Subscription;
use App\Modules\Billing\Domain\Models\SubscriptionPayment;
use App\Modules\Finance\Domain\Models\Payment;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Integrations\Application\IntegrationUnavailable;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Application\Router;
use App\Modules\Integrations\Contracts\ProviderDirectory;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Modules\Integrations\Sms\SmsGateway;
use App\Modules\Integrations\Weather\WeatherService;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Mail\NoticeMail;
use App\Modules\Parties\Domain\Models\Party;
use App\Modules\Parties\Domain\Models\PartyLink;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use App\Modules\Sales\Domain\Models\Customer;
use App\Modules\Tenancy\Application\FarmSettings;
use App\Modules\Tenancy\Domain\Models\Farm;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 14 (ADR-0018): a contract test per adapter (the request each
 * provider receives and how its answers are read), failover and the circuit
 * breaker, online payments end to end, notice copies, IoT readings and the
 * admin provider test.
 */
class IntegrationsTest extends TestCase
{
    private Farm $farm;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->farm = $this->farm();
    }

    private function provider(string $kind, string $provider, array $config, bool $default = false, int $priority = 100): IntegrationProvider
    {
        return IntegrationProvider::create(['kind' => $kind, 'provider' => $provider, 'name' => ucfirst(str_replace('_', ' ', $provider)),
            'config' => $config, 'is_enabled' => true, 'is_default' => $default, 'priority' => $priority]);
    }

    private function url(string $path): string
    {
        return "/api/v1/farms/{$this->farm->id}{$path}";
    }

    // ---- SMS ----------------------------------------------------------

    public function test_africas_talking_contract_and_number_normalisation(): void
    {
        $this->provider('sms', 'africas_talking', ['username' => 'sfmtp', 'api_key' => 'at-key', 'sender_id' => 'SFMTP'], default: true);
        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Message' => 'Sent to 1/1', 'Recipients' => [
            ['statusCode' => 101, 'number' => '+256772100200', 'status' => 'Success', 'cost' => 'UGX 25', 'messageId' => 'ATXid_1'],
        ]]], 201)]);

        $sent = app(SmsGateway::class)->send('0772 100 200', 'Hello');

        $this->assertSame(['provider' => 'africas_talking', 'message_id' => 'ATXid_1'], $sent);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.africastalking.com/version1/messaging'
            && $r->hasHeader('apiKey', 'at-key') && $r['username'] === 'sfmtp' && $r['to'] === '+256772100200' && $r['message'] === 'Hello' && $r['from'] === 'SFMTP');
        $this->assertSame('+254712345678', SmsGateway::normalize('00254 712 345 678'));
        $this->assertNull(SmsGateway::normalize('12'));
    }

    public function test_sms_fails_over_to_twilio_but_not_for_a_refused_number(): void
    {
        $at = $this->provider('sms', 'africas_talking', ['username' => 'sfmtp', 'api_key' => 'at-key'], default: true);
        $tw = $this->provider('sms', 'twilio', ['account_sid' => 'AC123', 'auth_token' => 'tw-secret', 'from' => '+15005550006']);
        Http::fake([
            'api.africastalking.com/*' => Http::sequence()
                ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 405, 'status' => 'InsufficientBalance']]]], 201)
                ->push(['SMSMessageData' => ['Recipients' => [['statusCode' => 403, 'status' => 'InvalidPhoneNumber']]]], 201),
            'api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201),
        ]);

        // Out of credit at Africa's Talking: Twilio delivers.
        $this->assertSame(['provider' => 'twilio', 'message_id' => 'SM1'], app(SmsGateway::class)->send('+256772100200', 'Hi'));
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('AC123:tw-secret')) && $r['To'] === '+256772100200' && $r['From'] === '+15005550006' && $r['Body'] === 'Hi');
        $this->assertSame(1, $at->refresh()->consecutive_failures);
        $this->assertNotNull($tw->refresh()->last_success_at);

        // An invalid number stops at the first provider: Twilio is not asked.
        try {
            app(SmsGateway::class)->send('+256772100200', 'Hi');
            $this->fail('A refused number must not fail over.');
        } catch (ProviderFailure $e) {
            $this->assertFalse($e->retryable);
        }
        Http::assertSentCount(3);
    }

    public function test_a_provider_failing_three_times_is_skipped_for_five_minutes(): void
    {
        $at = $this->provider('sms', 'africas_talking', ['username' => 'sfmtp', 'api_key' => 'k'], default: true);
        $this->provider('sms', 'twilio', ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+15005550006']);
        Http::fake(['api.africastalking.com/*' => Http::response('', 503), 'api.twilio.com/*' => Http::response(['sid' => 'SM'], 201)]);

        foreach (range(1, 3) as $i) {
            app(SmsGateway::class)->send('+256772100200', "try {$i}");
        }
        $this->assertTrue(app(Router::class)->isOpen(app(ProviderDirectory::class)->find($at->id)));
        Http::assertSentCount(6);

        // Circuit open: Twilio goes first, Africa's Talking is not called.
        app(SmsGateway::class)->send('+256772100200', 'fourth');
        Http::assertSentCount(7);
        $this->assertSame(3, $at->refresh()->consecutive_failures);

        // After five minutes it is tried again (and fails over again).
        $this->travel(6)->minutes();
        app(SmsGateway::class)->send('+256772100200', 'fifth');
        Http::assertSentCount(9);
    }

    public function test_nothing_configured_or_everything_down_is_reported(): void
    {
        try {
            app(SmsGateway::class)->send('+256772100200', 'x');
            $this->fail();
        } catch (IntegrationUnavailable $e) {
            $this->assertSame([], $e->errors);
        }
        $this->provider('sms', 'twilio', ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+1'], default: true);
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'Authenticate'], 401)]);
        $this->expectException(IntegrationUnavailable::class);
        app(SmsGateway::class)->send('+256772100200', 'x');
    }

    // ---- Email --------------------------------------------------------

    public function test_mail_goes_through_sendgrid_after_smtp_fails(): void
    {
        $this->provider('email', 'smtp', ['host' => '127.0.0.1', 'port' => '1'], default: true);   // nothing listens on port 1
        $this->provider('email', 'sendgrid', ['api_key' => 'SG.key', 'from_address' => 'noreply@sfmtp.test', 'from_name' => 'SFMTP']);
        Http::fake(['api.sendgrid.com/*' => Http::response('', 202, ['X-Message-Id' => 'sg-1'])]);

        Mail::mailer('providers')->raw('Your export is ready.', fn ($m) => $m->to('grace@farm.test', 'Grace')->subject('Export ready'));

        Http::assertSent(function (HttpRequest $r) {
            return $r->url() === 'https://api.sendgrid.com/v3/mail/send' && $r->hasHeader('Authorization', 'Bearer SG.key')
                && $r['personalizations'][0]['to'] === [['email' => 'grace@farm.test', 'name' => 'Grace']]
                && $r['from'] === ['email' => 'noreply@sfmtp.test', 'name' => 'SFMTP'] && $r['subject'] === 'Export ready'
                && $r['content'][0] === ['type' => 'text/plain', 'value' => 'Your export is ready.'];
        });
        $this->assertSame(1, IntegrationProvider::where('provider', 'smtp')->value('consecutive_failures'));
    }

    // ---- Weather and maps --------------------------------------------

    private function openWeather(): array
    {
        return ['current' => ['temp' => 24.3, 'humidity' => 70, 'wind_speed' => 3, 'weather' => [['id' => 802, 'description' => 'scattered clouds']]],
            'daily' => [
                ['dt' => strtotime('today 12:00 UTC'), 'temp' => ['min' => 17.2, 'max' => 27.9], 'pop' => 0.9, 'rain' => 24.5, 'weather' => [['id' => 501]]],
                ['dt' => strtotime('tomorrow 12:00 UTC'), 'temp' => ['min' => 17, 'max' => 33.1], 'pop' => 0.2, 'weather' => [['id' => 800]]],
                ['dt' => strtotime('+2 days 12:00 UTC'), 'temp' => ['min' => 16, 'max' => 26], 'pop' => 0.1, 'weather' => [['id' => 211]]],
            ]];
    }

    public function test_weather_normalises_both_providers_and_fails_over(): void
    {
        $this->provider('weather', 'openweather', ['api_key' => 'ow'], default: true);
        $this->provider('weather', 'tomorrow_io', ['api_key' => 'tm']);
        Http::fake([
            'api.openweathermap.org/*' => Http::sequence()->push($this->openWeather())->push('', 500),
            'api.tomorrow.io/*' => Http::response(['timelines' => [
                'hourly' => [['time' => now()->toIso8601ZuluString(), 'values' => ['temperature' => 22, 'humidity' => 80, 'windSpeed' => 2, 'weatherCode' => 4001]]],
                'daily' => [['time' => now()->startOfDay()->toIso8601ZuluString(), 'values' => ['temperatureMin' => 15, 'temperatureMax' => 25, 'precipitationProbabilityAvg' => 40, 'rainAccumulationSum' => 3.2, 'weatherCodeMax' => 1101]]],
            ]]),
        ]);
        $weather = app(WeatherService::class);

        $ow = $weather->forecast(0.3476, 32.5825, 'Africa/Kampala');
        $this->assertSame('openweather', $ow['provider']);
        $this->assertSame(['temp_c' => 24.3, 'humidity_pct' => 70, 'wind_kmh' => 10.8, 'condition' => 'partly_cloudy', 'description' => 'Scattered clouds'], $ow['current']);
        $this->assertSame(['date' => now('Africa/Kampala')->toDateString(), 'min_c' => 17.2, 'max_c' => 27.9, 'rain_mm' => 24.5, 'rain_chance_pct' => 90, 'condition' => 'rain'], $ow['daily'][0]);
        $this->assertSame('storm', $ow['daily'][2]['condition']);
        $this->assertSame(['heavy_rain', 'heat', 'heavy_rain'], array_column($ow['advisories'], 'kind'));
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.openweathermap.org/data/3.0/onecall') && $r['appid'] === 'ow' && $r['units'] === 'metric');

        // Cached for 30 minutes: no second call.
        $this->assertSame($ow, $weather->forecast(0.3476, 32.5825, 'Africa/Kampala'));
        Http::assertSentCount(1);

        // Elsewhere, OpenWeather fails and Tomorrow.io answers.
        $tm = $weather->forecast(1.2, 33.1, 'Africa/Kampala');
        $this->assertSame('tomorrow_io', $tm['provider']);
        $this->assertSame(['temp_c' => 22.0, 'humidity_pct' => 80, 'wind_kmh' => 7.2, 'condition' => 'rain', 'description' => 'Rain'], $tm['current']);
        $this->assertSame('partly_cloudy', $tm['daily'][0]['condition']);
    }

    public function test_farm_weather_uses_the_mapped_farm_and_shows_on_dashboards(): void
    {
        $owner = $this->ownerOf($this->farm);
        $this->asUser($owner)->getJson($this->url('/weather'))->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'no_location');
        $this->asUser($owner)->postJson($this->url('/structure/locations'), ['name' => 'Store', 'kind' => 'store', 'latitude' => 0.40, 'longitude' => 32.39])->assertCreated();
        $this->asUser($owner)->getJson($this->url('/weather'))->assertJsonPath('data.reason', 'not_configured');

        $this->provider('weather', 'openweather', ['api_key' => 'ow'], default: true);
        Http::fake(['api.openweathermap.org/*' => Http::response($this->openWeather())]);
        $this->asUser($owner)->getJson($this->url('/weather'))->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.location', ['lat' => 0.4, 'lng' => 32.39]);
        Http::assertSent(fn (HttpRequest $r) => (float) $r['lat'] === 0.4 && (float) $r['lon'] === 32.39);

        $widgets = collect($this->asUser($owner)->getJson($this->url('/dashboards/owner'))->json('data.widgets'))->keyBy('key');
        $this->assertFalse($widgets['weather']['inline']);
        $this->asUser($owner)->getJson($this->url('/dashboards/owner/widgets/weather'))->assertOk()->assertJsonPath('data.type', 'weather')->assertJsonPath('data.available', true);
    }

    public function test_map_tiles_come_from_the_default_provider_and_fall_back_to_openstreetmap(): void
    {
        $owner = $this->ownerOf($this->farm);
        $this->asUser($owner)->getJson('/api/v1/map-config')->assertOk()->assertJsonPath('data.provider', 'osm');

        Cache::flush();
        $mapbox = $this->provider('maps', 'mapbox', ['access_token' => 'sk.secret'], default: true);
        $this->asUser($owner)->getJson('/api/v1/map-config')->assertJsonPath('data.provider', 'osm');   // a secret token never reaches a browser

        Cache::flush();
        $mapbox->update(['config' => ['access_token' => 'pk.public', 'style' => 'mapbox/outdoors-v12']]);
        $this->asUser($owner)->getJson('/api/v1/map-config')->assertJsonPath('data.provider', 'mapbox')
            ->assertJsonPath('data.tile_url', 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}@2x?access_token=pk.public');

        Cache::flush();
        $mapbox->update(['is_default' => false, 'is_enabled' => false]);
        $this->provider('maps', 'google', ['api_key' => 'g-key'], default: true);
        Http::fake(['tile.googleapis.com/*' => Http::response(['session' => 'sess-1', 'expiry' => (string) (time() + 14 * 86400)])]);
        $this->asUser($owner)->getJson('/api/v1/map-config')->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.tile_url', 'https://tile.googleapis.com/v1/2dtiles/{z}/{x}/{y}?session=sess-1&key=g-key');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://tile.googleapis.com/v1/createSession?key=g-key' && $r['mapType'] === 'satellite');
    }

    // ---- Payments -----------------------------------------------------

    private function flutterwave(array $config = []): IntegrationProvider
    {
        return $this->provider('payment', 'flutterwave', $config + ['secret_key' => 'FLWSECK_TEST-x', 'webhook_hash' => 'whsec-123'], default: true);
    }

    private function verified(OnlinePayment $p, string $status = 'successful', ?float $amount = null, string $id = '4455'): array
    {
        return ['status' => 'success', 'data' => ['id' => (int) $id, 'tx_ref' => $p->reference, 'status' => $status, 'amount' => $amount ?? (float) $p->amount, 'currency' => $p->currency]];
    }

    public function test_an_owner_pays_the_subscription_online(): void
    {
        $owner = $this->ownerOf($this->farm);
        $subscription = Subscription::with('plan')->where('organization_id', $this->farm->organization_id)->firstOrFail();
        $this->assertProblem($this->asUser($owner)->postJson('/api/v1/billing/subscription/pay'), 409, 'online_payment_unavailable');

        $this->flutterwave();
        // The gateway unreachable: a clear 502, never a 500.
        Http::fake(['api.flutterwave.com/v3/payments' => Http::sequence()->pushFailedConnection()
            ->push(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/abc']])]);
        $this->assertProblem($this->asUser($owner)->postJson('/api/v1/billing/subscription/pay'), 502, 'payment_gateway_error');
        $payment = $this->asUser($owner)->postJson('/api/v1/billing/subscription/pay')->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.checkout_url', 'https://checkout.flutterwave.com/v3/hosted/pay/abc')->json('data');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.flutterwave.com/v3/payments' && $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-x')
            && $r['tx_ref'] === $payment['reference'] && (float) $r['amount'] === (float) $subscription->plan->price && $r['currency'] === $subscription->plan->currency
            && $r['redirect_url'] === config('sfmtp.web_url').'/payments/return?reference='.$payment['reference'] && $r['customer']['email'] === $owner->email
            && ! isset($r['subaccounts']));
        // Pressing "Pay" again reuses the open checkout.
        $this->assertSame($payment['reference'], $this->asUser($owner)->postJson('/api/v1/billing/subscription/pay')->json('data.reference'));

        // Not yet paid: still pending.
        $model = $this->unscoped(fn () => OnlinePayment::where('reference', $payment['reference'])->firstOrFail());
        Http::fake(['api.flutterwave.com/v3/transactions/*' => Http::sequence()
            ->push(['status' => 'error', 'message' => 'No transaction was found for this id', 'data' => null], 400)
            ->push($this->verified($model))]);
        $this->asUser($owner)->getJson("/api/v1/online-payments/{$payment['reference']}")->assertJsonPath('data.status', 'pending');

        // Paid: the next look confirms it with Flutterwave and extends the subscription.
        $this->travel(6)->seconds();
        $this->asUser($owner)->getJson("/api/v1/online-payments/{$payment['reference']}")->assertJsonPath('data.status', 'succeeded')->assertJsonPath('data.checkout_url', null);
        $this->assertSame('active', $subscription->refresh()->status->value);
        $this->assertSame(1, SubscriptionPayment::where('subscription_id', $subscription->id)->where('provider', 'flutterwave')->where('provider_ref', '4455')->count());
        $this->assertNotNull($this->unscoped(fn () => $model->refresh())->fulfilled_at);

        // Someone else's payment is a 404.
        $this->asUser($this->member())->getJson("/api/v1/online-payments/{$payment['reference']}")->assertNotFound();
    }

    private function customerWithInvoice(): array
    {
        $owner = $this->ownerOf($this->farm);
        $buyer = User::factory()->create(['user_type' => UserType::Party, 'phone' => '+256700000001']);
        $party = Party::create(['name' => 'Kampala Millers', 'status' => 'active']);
        $party->users()->attach($buyer->id, ['id' => (string) Str::uuid7(), 'created_at' => now()]);
        $customer = $this->inFarm($this->farm, fn () => tap(new Customer(['code' => 'CUS-001', 'name' => 'Kampala Millers', 'is_active' => true]))->forceFill(['party_id' => $party->id])->save() ? Customer::where('code', 'CUS-001')->first() : null);
        $this->inFarm($this->farm, fn () => PartyLink::create(['party_id' => $party->id, 'farm_id' => $this->farm->id, 'kind' => 'customer', 'record_id' => $customer->id, 'status' => 'active', 'linked_by' => $owner->id, 'linked_at' => now()]));
        // Reading the accounts sets up the farm's chart of accounts.
        $income = collect($this->asUser($owner)->getJson($this->url('/ledger/accounts'))->assertOk()->json('data'))->firstWhere('code', '4000')['id'];
        $invoice = $this->asUser($owner)->postJson($this->url('/customer-invoices'), ['customer_id' => $customer->id, 'lines' => [
            ['description' => 'Maize 10 bags', 'quantity' => 10, 'unit_price' => 95000, 'account_id' => $income],
        ]])->assertCreated()->json('data.id');
        $this->asUser($owner)->postJson($this->url("/customer-invoices/{$invoice}/issue"))->assertOk();

        return [$buyer, $party, $invoice];
    }

    public function test_a_customer_pays_an_invoice_online_into_the_farm_subaccount(): void
    {
        [$buyer, $party, $invoice] = $this->customerWithInvoice();
        $this->flutterwave();
        $portal = "/api/v1/customer/{$party->id}";
        $this->assertFalse($this->asUser($buyer)->getJson("{$portal}/invoices")->json('data.0.pay_online'));
        $this->assertProblem($this->asUser($buyer)->postJson("{$portal}/farms/{$this->farm->id}/invoices/{$invoice}/pay"), 409, 'online_payment_unavailable');

        // The owner turns online payments on with the farm's subaccount.
        $owner = $this->ownerOf($this->farm);
        $this->asUser($owner)->patchJson($this->url('/settings'), ['online_payments' => ['enabled' => true]])->assertStatus(422);
        $this->asUser($owner)->patchJson($this->url('/settings'), ['online_payments' => ['enabled' => true, 'subaccount_id' => 'RS_A1B2C3']])->assertOk();
        $this->assertTrue($this->asUser($buyer)->getJson("{$portal}/invoices")->json('data.0.pay_online'));

        Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/pay/x']])]);
        $payment = $this->asUser($buyer)->postJson("{$portal}/farms/{$this->farm->id}/invoices/{$invoice}/pay")->assertCreated()
            ->assertJsonPath('data.amount', 950000)->assertJsonPath('data.return_path', "/customer/{$party->id}/invoices")->json('data');
        Http::assertSent(fn (HttpRequest $r) => $r['subaccounts'] === [['id' => 'RS_A1B2C3']] && $r['customer']['phonenumber'] === '+256700000001');

        // Flutterwave calls the webhook: a wrong signature is refused, the right one confirms the payment.
        $model = $this->unscoped(fn () => OnlinePayment::where('reference', $payment['reference'])->firstOrFail());
        Http::fake(['api.flutterwave.com/v3/transactions/*' => Http::response($this->verified($model, id: '9001'))]);
        $hook = ['event' => 'charge.completed', 'data' => ['id' => 9001, 'tx_ref' => $payment['reference'], 'status' => 'successful']];
        $this->withHeaders(['verif-hash' => 'wrong'])->postJson('/api/v1/webhooks/payments/flutterwave', $hook)->assertUnauthorized();
        $this->assertSame('pending', $this->unscoped(fn () => $model->refresh())->status);
        $this->withHeaders(['verif-hash' => 'whsec-123'])->postJson('/api/v1/webhooks/payments/flutterwave', $hook)->assertOk();
        $this->withHeaders(['verif-hash' => 'whsec-123'])->postJson('/api/v1/webhooks/payments/flutterwave', $hook)->assertOk();   // repeated

        $this->assertSame('succeeded', $this->unscoped(fn () => $model->refresh())->status);
        $this->inFarm($this->farm, function () use ($invoice) {
            $payments = Payment::where('payable_id', $invoice)->get();
            $this->assertCount(1, $payments, 'recorded once');
            $this->assertSame(['mobile_money', 'flutterwave:9001', '950000.00'], [$payments[0]->method, $payments[0]->reference, (string) $payments[0]->amount]);
            $this->assertNull($payments[0]->recorded_by);
            $this->assertSame('paid', DB::table('customer_invoices')->where('id', $invoice)->value('status'));
        });
    }

    public function test_an_underpaid_or_failed_payment_is_never_booked(): void
    {
        [$buyer, $party, $invoice] = $this->customerWithInvoice();
        $this->flutterwave();
        $this->inFarm($this->farm, fn () => app(FarmSettings::class)->update($this->farm, ['online_payments' => ['enabled' => true, 'subaccount_id' => 'RS_1']]));
        Http::fake(['api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://x']])]);
        $ref = $this->asUser($buyer)->postJson("/api/v1/customer/{$party->id}/farms/{$this->farm->id}/invoices/{$invoice}/pay")->json('data.reference');
        $model = $this->unscoped(fn () => OnlinePayment::where('reference', $ref)->firstOrFail());

        Http::fake(['api.flutterwave.com/v3/transactions/*' => Http::response($this->verified($model, amount: 1000))]);
        $this->asUser($buyer)->getJson("/api/v1/online-payments/{$ref}")->assertJsonPath('data.status', 'failed');
        $this->assertStringContainsString('instead of 950000', $this->unscoped(fn () => $model->refresh())->failure_reason);
        $this->assertSame(0, $this->inFarm($this->farm, fn () => Payment::count()));
    }

    // ---- Notice copies ------------------------------------------------

    public function test_important_notices_are_copied_by_email_and_sms_as_chosen(): void
    {
        Mail::fake();
        $this->provider('sms', 'africas_talking', ['username' => 'sfmtp', 'api_key' => 'k'], default: true);
        Http::fake(['api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'messageId' => 'm1']]]], 201)]);
        $worker = $this->memberWithRole($this->farm, 'field_worker');
        $worker->forceFill(['phone' => '0772 100 200'])->save();

        $this->asUser($worker)->getJson('/api/v1/me/notification-preferences')->assertOk()->assertJsonPath('data.email', true)->assertJsonPath('data.sms', false);
        $this->asUser($worker)->putJson('/api/v1/me/notification-preferences', ['sms' => true])->assertOk()->assertJsonPath('data.sms', true)->assertJsonPath('data.email', true);

        $this->inFarm($this->farm, fn () => app(Inbox::class)->notify([$worker->id], 'task_assigned', 'New task: Spray plot A-1', 'Due today', '/farms/x/my-day'));
        Mail::assertSent(NoticeMail::class, fn ($m) => $m->hasTo($worker->email));
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '+256772100200' && str_contains($r['message'], 'New task: Spray plot A-1'));

        // Kinds that are not copied stay in the inbox and push only.
        $this->inFarm($this->farm, fn () => app(Inbox::class)->notify([$worker->id], 'sync_conflict', 'Choose which details to keep'));
        Mail::assertSentCount(1);
        Http::assertSentCount(1);

        $noPhone = $this->memberWithRole($this->farm, 'agronomist');
        $noPhone->forceFill(['phone' => null])->save();
        $this->asUser($noPhone)->putJson('/api/v1/me/notification-preferences', ['sms' => true])->assertStatus(422);
    }

    // ---- IoT -----------------------------------------------------------

    public function test_sensors_post_readings_with_their_own_token(): void
    {
        $owner = $this->ownerOf($this->farm);
        $device = $this->asUser($owner)->postJson($this->url('/iot-devices'), ['name' => 'Soil probe A-1', 'kind' => 'soil_probe'])->assertCreated();
        $token = $device->json('meta.token');
        $this->assertStringStartsWith('sfmtpd_', $token);
        $id = $device->json('data.id');

        $this->withToken($token)->postJson('/api/v1/iot/readings', ['readings' => [
            ['metric' => 'soil_moisture_pct', 'value' => 31.5, 'recorded_at' => now()->subHour()->toIso8601String()],
            ['metric' => 'soil_moisture_pct', 'value' => 29.25],
            ['metric' => 'temperature_c', 'value' => 22.1],
        ]])->assertCreated()->assertJsonPath('data.stored', 3);
        $this->withToken('sfmtpd_wrong')->postJson('/api/v1/iot/readings', ['readings' => [['metric' => 'x1', 'value' => 1]]])->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/iot/readings', ['readings' => [['metric' => 'Bad Metric', 'value' => 1]]])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/iot/readings', ['readings' => [['metric' => 'rain_mm', 'value' => 1, 'recorded_at' => now()->subDays(8)->toIso8601String()]]])->assertStatus(422);

        $readings = $this->asUser($owner)->getJson($this->url("/iot-devices/{$id}/readings"))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([['soil_moisture_pct', 29.25], ['temperature_c', 22.1]], array_map(fn ($r) => [$r['metric'], $r['value']], $readings['latest']));
        $this->assertCount(3, $readings['series']);

        // Rotating the token stops the old one; another farm cannot see the device.
        $new = $this->asUser($owner)->postJson($this->url("/iot-devices/{$id}/rotate-token"))->assertOk()->json('meta.token');
        $this->withToken($token)->postJson('/api/v1/iot/readings', ['readings' => [['metric' => 'x1', 'value' => 1]]])->assertUnauthorized();
        $this->withToken($new)->postJson('/api/v1/iot/readings', ['readings' => [['metric' => 'x1', 'value' => 1]]])->assertCreated();
        $other = $this->farm();
        $this->asUser($this->ownerOf($other))->getJson("/api/v1/farms/{$other->id}/iot-devices/{$id}/readings")->assertNotFound();
    }

    // ---- Admin ---------------------------------------------------------

    public function test_an_admin_tests_a_provider_and_sees_its_health(): void
    {
        $admin = $this->platformAdmin();
        $sms = $this->provider('sms', 'africas_talking', ['username' => 'sfmtp', 'api_key' => 'k'], default: true);
        $weather = $this->provider('weather', 'openweather', ['api_key' => 'bad'], default: true);
        Http::fake([
            'api.africastalking.com/*' => Http::response(['SMSMessageData' => ['Recipients' => [['statusCode' => 101, 'messageId' => 'ATX9']]]], 201),
            'api.openweathermap.org/*' => Http::response(['cod' => 401], 401),
        ]);

        $this->asUser($admin)->postJson("/api/v1/admin/integrations/{$sms->id}/test", ['phone' => '0772100200'])->assertOk()
            ->assertJsonPath('data.ok', true)->assertJsonPath('data.provider.health.status', 'ok');
        $this->asUser($admin)->postJson("/api/v1/admin/integrations/{$weather->id}/test")->assertOk()
            ->assertJsonPath('data.ok', false)->assertJsonPath('data.provider.health.status', 'degraded')
            ->assertJsonPath('data.provider.health.last_error', 'OpenWeather answered HTTP 401');
        $this->asUser($admin)->getJson('/api/v1/admin/integrations')->assertOk()->assertJsonPath('data.0.config.api_key', '••••k');
    }

    public function test_journal_export_lists_every_ledger_line_for_accounting_software(): void
    {
        [, , $invoice] = $this->customerWithInvoice();
        $journal = $this->asUser($this->ownerOf($this->farm))->getJson($this->url('/standard-reports/journal'))->assertOk()->json('data');
        $this->assertSame(['date', 'number', 'narration', 'account_code', 'account', 'description', 'debit', 'credit', 'amount', 'cost_centre'], array_column($journal['columns'], 'key'));
        $codes = array_column($journal['rows'], 'account_code');
        $this->assertContains('1200', $codes);
        $this->assertContains('4000', $codes);
        $this->assertSame($journal['totals']['debit'], $journal['totals']['credit'], 'the journal balances');
        $this->assertSame('0.00', number_format(array_sum(array_map('floatval', array_column($journal['rows'], 'amount'))), 2, '.', ''));
    }
}
