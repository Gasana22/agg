<?php

namespace App\Modules\Platform\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Integrations\Application\IntegrationUnavailable;
use App\Modules\Integrations\Application\ProviderFailure;
use App\Modules\Integrations\Contracts\ProviderDirectory;
use App\Modules\Integrations\Domain\Models\OnlinePayment;
use App\Modules\Integrations\Email\EmailGateway;
use App\Modules\Integrations\Maps\MapTiles;
use App\Modules\Integrations\Payments\OnlinePayments;
use App\Modules\Integrations\Sms\SmsGateway;
use App\Modules\Integrations\Weather\WeatherService;
use App\Modules\Notifications\Application\FcmPushSender;
use App\Modules\Platform\Domain\Models\IntegrationProvider;
use App\Support\Http\ApiException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mime\Email;

/**
 * "Test" on the admin integrations page: one harmless real call to that
 * provider alone (no failover), reported in its health. SMS and email go to
 * the administrator; payment checks the keys by looking up an unknown
 * reference; weather asks for Kampala.
 */
class IntegrationTester
{
    public function __construct(private readonly ProviderDirectory $directory) {}

    /** @return array{ok:bool, message:string} */
    public function test(IntegrationProvider $provider, User $admin, ?string $phone): array
    {
        try {
            $message = match ($provider->kind) {
                'sms' => $this->sms($provider, $phone ?? $admin->phone),
                'email' => $this->email($provider, $admin),
                'weather' => $this->weather($provider),
                'maps' => $this->maps($provider),
                'payment' => $this->payment($provider),
                'push' => $this->push($provider),
                default => throw ApiException::unprocessable('not_testable', 'This kind of provider has no test yet.'),
            };

            return ['ok' => true, 'message' => $message];
        } catch (ProviderFailure $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (IntegrationUnavailable $e) {
            return ['ok' => false, 'message' => $e->errors ? implode('; ', $e->errors) : 'The provider is not enabled.'];
        }
    }

    private function sms(IntegrationProvider $p, ?string $phone): string
    {
        if (! $phone) {
            throw ApiException::unprocessable('validation_failed', 'The given data was invalid.', ['phone' => ['Give a phone number to send the test message to.']]);
        }
        $sent = app(SmsGateway::class)->send($phone, 'SFMTP test message: your SMS provider works.', $p->id);

        return "Sent to {$phone} (message {$sent['message_id']}).";
    }

    private function email(IntegrationProvider $p, User $admin): string
    {
        $email = (new Email)->from(config('mail.from.address'))->to($admin->email)
            ->subject('SFMTP test email')->text("Your email provider \"{$p->name}\" works.");
        app(EmailGateway::class)->send($email, $p->id);

        return "Sent to {$admin->email}.";
    }

    private function weather(IntegrationProvider $p): string
    {
        Cache::forget(sprintf('weather:%.2f:%.2f:%s:%s', 0.35, 32.58, 'Africa/Kampala', $p->id));
        $f = app(WeatherService::class)->forecast(0.3476, 32.5825, 'Africa/Kampala', $p->id);
        if (! $f['available']) {
            throw new ProviderFailure('No forecast came back.');
        }

        return "Kampala now: {$f['current']['temp_c']} °C, {$f['current']['description']}; ".count($f['daily']).' days of forecast.';
    }

    private function maps(IntegrationProvider $p): string
    {
        Cache::forget("maps:config:{$p->id}");
        $config = app(MapTiles::class)->config($p->id);
        if ($config['provider'] !== $p->provider) {
            throw new ProviderFailure('The map provider did not answer; OpenStreetMap would be used.');
        }

        return 'Tiles ready from '.$config['provider'].'.';
    }

    private function payment(IntegrationProvider $p): string
    {
        $config = $this->directory->find($p->id);
        $adapter = app(OnlinePayments::ADAPTERS[$p->provider] ?? throw ApiException::unprocessable('not_testable', 'This payment provider takes no online payments.'));
        $probe = new OnlinePayment(['reference' => 'SFMTP-TEST-'.now()->format('YmdHis')]);
        $adapter->verify($config, $probe);
        $this->directory->report($p->id, true);

        return 'The keys work.'.($config->get('webhook_hash') ? '' : ' Set the webhook hash to receive payment notices.');
    }

    private function push(IntegrationProvider $p): string
    {
        $sender = FcmPushSender::fromJson($this->directory->find($p->id)?->get('service_account'))
            ?? throw new ProviderFailure('Paste the Firebase service account JSON as "service_account".');
        $project = $sender->check();
        $this->directory->report($p->id, true);

        return "Signed in to Firebase project {$project}.";
    }
}
