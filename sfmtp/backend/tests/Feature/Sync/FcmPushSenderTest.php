<?php

namespace Tests\Feature\Sync;

use App\Modules\Notifications\Application\FcmPushSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FcmPushSenderTest extends TestCase
{
    public function test_it_signs_in_with_the_service_account_and_sends(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $public = openssl_pkey_get_details($key)['key'];
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*/messages:send' => Http::sequence()
                ->push(['name' => 'projects/sfmtp/messages/1'])
                ->push(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);

        $sender = new FcmPushSender(['project_id' => 'sfmtp', 'client_email' => 'push@sfmtp.iam.gserviceaccount.com', 'private_key' => $pem]);
        $this->assertTrue($sender->send('device-token', 'Work approved', 'Dig the trench', ['kind' => 'task_verified', 'task_id' => 'abc']));
        $this->assertFalse($sender->send('old-token', 'Hello', null, []), 'an unregistered token is reported so it is forgotten');

        Http::assertSent(function (Request $r) use ($public) {
            if (! str_contains($r->url(), 'oauth2')) {
                return false;
            }
            [$h, $p, $sig] = explode('.', $r['assertion']);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);

            return $claims['scope'] === 'https://www.googleapis.com/auth/firebase.messaging'
                && openssl_verify("{$h}.{$p}", base64_decode(strtr($sig, '-_', '+/')), $public, OPENSSL_ALGO_SHA256) === 1;
        });
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'projects/sfmtp/messages:send')
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['token'] === 'device-token'
            && $r['message']['data'] === ['kind' => 'task_verified', 'task_id' => 'abc']);
    }
}
