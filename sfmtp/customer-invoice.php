<?php
/* Customer portal: one invoice from a farm, printable, with the payments it received. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('customer');
$inv = row("SELECT * FROM customer_invoices WHERE id = ? AND status IN ('issued','paid','void')", [input_id('id') ?? '']);
$link = portal_link_for($links, $inv['farm_id'] ?? '', $inv['customer_id'] ?? '');
portal_enter($link);
$farm = current_farm();
$org = row('SELECT name FROM organizations WHERE id = ?', [$farm['organization_id']]);
$lines = rows('SELECT description, quantity, unit, unit_price, amount FROM customer_invoice_lines WHERE invoice_id = ? AND farm_id = ? ORDER BY position', [$inv['id'], $farm['id']]);
$payments = rows("SELECT code, paid_on, method, reference, amount FROM payments WHERE farm_id = ? AND payable_type = 'customer_invoice' AND payable_id = ? AND status = 'posted' ORDER BY paid_on", [$farm['id'], $inv['id']]);
$customer = $link['record'];
$canPay = $inv['status'] === 'issued' && payment_provider() && !empty(farm_settings()['online_payments']['enabled']);

if (is_post() && input('action', 10) === 'pay') {
    try {
        payment_start($inv, $customer, current_user());
        audit('sales.invoice.payment_started', null, ['type' => 'customer_invoice', 'id' => $inv['id']]);
        redirect('pay-go.php', ['ref' => val('SELECT reference FROM online_payments WHERE subject_id = ? AND created_by = ? ORDER BY created_at DESC LIMIT 1', [$inv['id'], $_SESSION['uid']])]);
    } catch (Invalid $e) {
        flash('error', $e->getMessage());
        redirect('customer-invoice.php', ['id' => $inv['id']]);
    }
}

ob_start();
echo '<div class="card"><div class="row" style="justify-content:space-between;align-items:flex-start"><div><h2>' . e($org['name'] ?? $farm['name']) . '</h2><div class="muted">' . e($farm['name']) . ' · ' . e($farm['district'] ?? '') . '</div></div>'
    . '<div style="text-align:right"><h2>INVOICE ' . e($inv['code']) . '</h2><div>' . badge($inv['status']) . '</div><div class="muted">Date ' . e(fdate($inv['invoice_date'])) . ' · Due ' . e(fdate($inv['due_on'])) . '</div></div></div>'
    . '<h3>Bill to</h3><div><b>' . e($customer['name']) . '</b><br>' . e($customer['address'] ?? '') . '</div><br>';
table($lines, ['Description' => fn ($l) => e($l['description']), '#Quantity' => fn ($l) => e(qty($l['quantity'], $l['unit'])), '#Unit price' => fn ($l) => e(money($l['unit_price'])), '#Amount' => fn ($l) => e(money($l['amount']))]);
echo '<p style="text-align:right;font-size:1.1rem"><b>Total ' . e(money($inv['amount'])) . '</b><br>Paid ' . e(money($inv['paid_amount'])) . '<br><b>Still due ' . e(money($inv['status'] === 'issued' ? $inv['amount'] - $inv['paid_amount'] : 0)) . '</b></p>'
    . ($inv['status'] === 'void' ? '<p class="flash error">This invoice was cancelled by the farm.</p>' : '') . '</div>';
if ($canPay) {
    echo '<div class="card no-print"><form method="post">' . csrf_field() . '<input type="hidden" name="action" value="pay"><button class="primary">Pay ' . e(money($inv['amount'] - $inv['paid_amount'])) . ' online</button>'
        . ' <span class="muted">Mobile money or card, through ' . e(payment_provider()['provider'] === 'simulator' ? 'the test gateway' : 'Flutterwave') . '.</span></form></div>';
}
echo '<div class="card"><h2>Payments received</h2>';
table($payments, ['Payment' => fn ($p) => e($p['code']), 'Date' => fn ($p) => e(fdate($p['paid_on'])), 'Method' => fn ($p) => e(label($p['method'])) . ($p['reference'] ? ' · ' . e($p['reference']) : ''), '#Amount' => fn ($p) => e(money($p['amount']))], 'No payments yet.');
echo '</div>';
$html = ob_get_clean();
act_in_farm(null);
page_start('Invoice ' . $inv['code']);
echo '<p class="no-print"><a href="' . e(url('customer.php', ['tab' => 'invoices'])) . '">← Invoices</a> · <button type="button" data-print>Print</button></p>' . $html;
page_end();
