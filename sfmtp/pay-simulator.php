<?php
/* Test payment gateway: lets you try online payment without money. Works only with debug on in config.php. */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
config('debug', false) || exit(http_response_code(404));
$op = row("SELECT * FROM online_payments WHERE reference = ? AND provider = 'simulator' AND created_by = ? AND status = 'pending'", [(string) input('ref', 40), $user['id']]);
$op || exit(http_response_code(404));

if (is_post()) {
    if (input('outcome', 10) === 'pay') {
        q('UPDATE online_payments SET provider_tx_id = ?, updated_at = ? WHERE id = ?', ['SIM-' . strtoupper(bin2hex(random_bytes(5))), now_utc(), $op['id']]);
        redirect('pay-return.php', ['tx_ref' => $op['reference'], 'status' => 'successful']);
    }
    q("UPDATE online_payments SET status = 'cancelled', failure_reason = 'Cancelled in the test gateway', updated_at = ? WHERE id = ?", [now_utc(), $op['id']]);
    redirect('pay-return.php', ['tx_ref' => $op['reference'], 'status' => 'cancelled']);
}

page_start('Test payment', true);
echo '<div class="card"><h2>Test gateway</h2><p class="flash warn">No real money moves. This page exists only while debug is on.</p><p>' . e($op['description']) . '</p><p><b>' . e(money($op['amount'], $op['currency'])) . '</b></p>'
    . '<form method="post" class="row">' . csrf_field() . '<button class="primary" name="outcome" value="pay">Pay</button><button name="outcome" value="cancel">Cancel</button></form></div>';
page_end();
