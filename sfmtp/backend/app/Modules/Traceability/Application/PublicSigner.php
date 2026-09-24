<?php

namespace App\Modules\Traceability\Application;

/**
 * Signs public traceability payloads with Ed25519 so partners (buyers,
 * certifiers) can check a payload came from SFMTP unchanged. The public key
 * is published at GET /public/trace/keys.
 */
class PublicSigner
{
    public function sign(array $data): array
    {
        [$secret, $public] = $this->keys();

        return [
            'alg' => 'Ed25519',
            'key_id' => $this->keyId($public),
            'value' => base64_encode(sodium_crypto_sign_detached(self::canonical($data), $secret)),
        ];
    }

    public function verify(array $data, string $signature): bool
    {
        [, $public] = $this->keys();

        return sodium_crypto_sign_verify_detached(base64_decode($signature, true) ?: '', self::canonical($data), $public);
    }

    /** @return array{alg:string, key_id:string, public_key:string} */
    public function publicKey(): array
    {
        [, $public] = $this->keys();

        return ['alg' => 'Ed25519', 'key_id' => $this->keyId($public), 'public_key' => base64_encode($public)];
    }

    /** JSON with object keys sorted at every level, no escaping of slashes or unicode. */
    public static function canonical(mixed $data): string
    {
        return json_encode(self::sortKeys(json_decode(json_encode($data), true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return array_map(fn ($x) => self::sortKeys($x), $v);
    }

    /** @return array{0:string, 1:string} secret key, public key */
    private function keys(): array
    {
        $seed = config('sfmtp.trace_signing_seed');
        $seed = $seed ? base64_decode($seed, true) : false;
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            // Derived from APP_KEY: stable per installation without extra setup.
            $seed = hash('sha256', 'sfmtp-trace-signing|'.config('app.key'), true);
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);

        return [sodium_crypto_sign_secretkey($pair), sodium_crypto_sign_publickey($pair)];
    }

    private function keyId(string $public): string
    {
        return substr(hash('sha256', $public), 0, 16);
    }
}
