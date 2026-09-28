<?php
/* Where the payment page sends the payer back. The payment is checked with the provider before anything is recorded. */
require __DIR__ . '/inc/bootstrap.php';

$ref = (string) input('tx_ref', 40);
$op = preg_match('/^SFMP-[0-9A-F]{16}$/', $ref) ? row('SELECT * FROM online_payments WHERE reference = ?', [$ref]) : null;
$status = 'unknown';
if ($op) {
    if ($op['status'] === 'pending' && input('status', 20) !== 'cancelled') {
        try {
            $status = payment_settle($ref, input('transaction_id', 30));
        } catch (Throwable $e) {
            error_log('SFMTP payment check: ' . $e->getMessage());
            $status = 'pending';
        }
    } elseif ($op['status'] === 'pending') {
        q("UPDATE online_payments SET status = 'cancelled', failure_reason = 'Cancelled by the payer', updated_at = ? WHERE id = ? AND status = 'pending'", [now_utc(), $op['id']]);
        $status = 'cancelled';
    } else {
        $status = $op['status'];
    }
}

page_start('Payment', true);
echo '<div class="card">' . match ($status) {
    'succeeded' => '<h2>Thank you</h2><p>' . badge('paid') . ' Your payment of ' . e(money($op['amount'], $op['currency'])) . ' is received and recorded on the invoice.</p>',
    'pending' => '<h2>Payment not confirmed yet</h2><p>We have not had confirmation from the payment service. If money left your account it is recorded as soon as it is confirmed.</p>',
    'cancelled' => '<h2>Payment cancelled</h2><p>Nothing was charged.</p>',
    'failed' => '<h2>Payment failed</h2><p>' . e($op['failure_reason'] ?? '') . '</p>',
    default => '<h2>Unknown payment</h2>',
} . ($op ? '<p><a class="btn" href="' . e(url($op['return_path'])) . '">Back to the invoice</a></p>' : '') . '</div>';
page_end();
