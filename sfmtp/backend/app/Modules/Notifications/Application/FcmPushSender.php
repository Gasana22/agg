<?php

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Contracts\PushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1. Signs in with the project's service
 * account (a JWT signed with its RSA key, exchanged for a one-hour access
 * token), then sends one message per device token.
 */
class FcmPushSender implements PushSender
{
    /** @param  array{project_id:string, client_email:string, private_key:string, token_uri?:string}  $account */
    public function __construct(private readonly array $account) {}

    public static function fromConfig(): ?self
    {
        $path = config('sfmtp.push.fcm_credentials');
        if (! $path || ! is_readable($path)) {
            return null;
        }
        $account = json_decode((string) file_get_contents($path), true);

        return is_array($account) && isset($account['project_id'], $account['client_email'], $account['private_key']) ? new self($account) : null;
    }

    public function send(string $token, string $title, ?string $body, array $data): bool
    {
        $res = Http::withToken($this->accessToken())->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send", ['message' => [
                'token' => $token,
                'notification' => array_filter(['title' => $title, 'body' => $body]),
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high'],
            ]]);
        if ($res->successful()) {
            return true;
        }
        // UNREGISTERED / INVALID_ARGUMENT on the token: forget it.
        $code = $res->json('error.details.0.errorCode') ?? $res->json('error.status');
        if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
            return false;
        }
        throw new RuntimeException("FCM send failed: {$res->status()}");
    }

    private function accessToken(): string
    {
        return Cache::remember('fcm:token:'.$this->account['client_email'], 3000, function () {
            $now = time();
            $segments = [
                self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::b64(json_encode([
                    'iss' => $this->account['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => $this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            openssl_sign(implode('.', $segments), $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256)
                || throw new RuntimeException('Could not sign the FCM token request.');
            $segments[] = self::b64($signature);

            return Http::asForm()->timeout(10)->post($this->account['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
