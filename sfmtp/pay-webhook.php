<?php
/*
 * Flutterwave calls this when a payment changes. The call must carry the
 * secret hash set on the provider; the payment is then checked with
 * Flutterwave's API before anything is recorded, so a forged call can do nothing.
 */
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json');
$given = (string) ($_SERVER['HTTP_VERIF_HASH'] ?? '');
$known = array_filter(array_map(fn ($p) => provider_config($p)['webhook_hash'] ?? '', rows("SELECT * FROM integration_providers WHERE kind = 'payment' AND provider = 'flutterwave'")));
if ($given === '' || !array_filter($known, fn ($h) => hash_equals((string) $h, $given))) {
    http_response_code(401);
    exit('{"ok":false}');
}
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$ref = (string) ($body['data']['tx_ref'] ?? $body['txRef'] ?? '');
$tx = (string) ($body['data']['id'] ?? $body['id'] ?? '');
if (preg_match('/^SFMP-[0-9A-F]{16}$/', $ref) && ctype_digit($tx)) {
    try {
        $status = payment_settle($ref, $tx);
    } catch (Throwable $e) {
        error_log('SFMTP webhook: ' . $e->getMessage());
        http_response_code(500);
        exit('{"ok":false}');
    }
}
echo json_encode(['ok' => true, 'status' => $status ?? 'ignored']);
