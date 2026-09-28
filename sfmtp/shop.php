<?php
/* Customer portal: the products each farm offers you, and placing an order at list price. */
require __DIR__ . '/inc/bootstrap.php';

$links = require_portal('customer');

if (is_post()) {
    $link = portal_link_for($links, input_id('farm_id') ?? '', input_id('customer_id') ?? '');
    portal_enter($link);
    handle(function () use ($link) {
        $lines = [];
        foreach ((array) ($_POST['qty'] ?? []) as $productId => $q) {
            $lines[] = ['product_id' => $productId, 'quantity' => $q];
        }
        $date = input_date('requested_delivery_on');
        if ($date !== null && $date < farm_today()) {
            fail('The delivery date cannot be in the past.');
        }
        $id = so_place($link['record'], $lines, ['requested_delivery_on' => $date, 'delivery_address' => input('delivery_address', 300), 'customer_note' => input('customer_note', 500)], 'portal');
        flash('success', 'Order placed. The farm confirms it before sending.');
        redirect('customer-order.php', ['id' => $id]);
    }, 'shop.php');
}

$sections = [];
foreach ($links as $l) {
    portal_enter($l);
    $products = rows('SELECT id, code, name, description, category, unit, list_price, currency, min_order_quantity, availability_note FROM products
        WHERE farm_id = ? AND is_published = 1 AND is_active = 1 ORDER BY category, name', [$l['farm_id']]);
    ob_start();
    if (!$products) {
        echo '<p class="muted">This farm has nothing on offer at the moment.</p>';
    } else {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="farm_id" value="' . e($l['farm_id']) . '"><input type="hidden" name="customer_id" value="' . e($l['record_id']) . '">';
        echo '<div class="products">';
        foreach ($products as $p) {
            echo '<div class="product"><div><b>' . e($p['name']) . '</b>' . ($p['category'] ? ' <span class="muted">' . e($p['category']) . '</span>' : '') . '</div>'
                . ($p['description'] ? '<div class="muted">' . e($p['description']) . '</div>' : '')
                . '<div class="price">' . e(money($p['list_price'], $p['currency'])) . ' / ' . e($p['unit']) . '</div>'
                . '<div class="muted">' . e($p['availability_note'] ?? 'Available') . ($p['min_order_quantity'] ? ' · minimum ' . e(qty($p['min_order_quantity'], $p['unit'])) : '') . '</div>'
                . '<label class="field"><span>Quantity (' . e($p['unit']) . ')</span><input name="qty[' . e($p['id']) . ']" inputmode="decimal"></label></div>';
        }
        echo '</div>';
        echo '<div class="fields">' . field('Wanted by', '<input type="date" name="requested_delivery_on">') . field('Deliver to', '<input name="delivery_address" maxlength="300" value="' . e($l['record']['address'] ?? '') . '">')
            . field('Note to the farm', '<input name="customer_note" maxlength="500">') . '</div><div class="actions"><button class="primary">Place order</button></div></form>';
    }
    $sections[] = [$l, ob_get_clean()];
}
act_in_farm(null);

page_start('Order products');
foreach ($sections as [$l, $html]) {
    echo '<div class="card"><h2>' . e($l['farm_name']) . ' <span class="muted">for ' . e($l['record']['name']) . '</span></h2>' . $html . '</div>';
}
page_end();
