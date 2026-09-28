<?php
/* Hand the payer over to the payment page (a plain link: the site only lets forms post to itself). */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();
$op = row("SELECT * FROM online_payments WHERE reference = ? AND created_by = ? AND status = 'pending'", [(string) input('ref', 40), $user['id']]);
page_start('Payment', true);
if (!$op || !$op['checkout_url']) {
    echo '<div class="card"><p>This payment is not waiting any more.</p><p><a href="' . e(url('portal.php')) . '">Back to the portal</a></p></div>';
} else {
    echo '<div class="card"><h2>' . e($op['description']) . '</h2><p><b>' . e(money($op['amount'], $op['currency'])) . '</b></p>'
        . '<p><a class="btn primary" data-autofollow href="' . e($op['checkout_url']) . '">Continue to payment</a></p>'
        . '<p class="muted">Reference ' . e($op['reference']) . '. You come back here when it is done.</p></div>';
}
page_end();
