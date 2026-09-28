<?php
/*
 * End-to-end smoke test against a running copy of SFMTP with the demo database.
 *
 *   php -S 127.0.0.1:8090 -t .          (in another terminal)
 *   php tests/smoke.php http://127.0.0.1:8090
 *
 * It signs in as the demo users (setting up two-step sign-in where needed),
 * opens every page, runs the main flows and checks role boundaries. It changes
 * data: run it on a throw-away copy of the database.
 */
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8090', '/');
$failures = 0;
$checks = 0;

final class Browser
{
    private string $jar;
    public string $last = '';
    public int $status = 0;
    public ?string $location = null;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'jar');
    }

    public function get(string $path): string
    {
        return $this->request('GET', $path);
    }

    /** Submit a form: the CSRF token is taken from the last page. */
    public function post(string $path, array $data, bool $follow = true): string
    {
        preg_match('/name="_csrf" value="([^"]+)"/', $this->last, $m);
        return $this->request('POST', $path, ['_csrf' => $m[1] ?? ''] + $data, $follow);
    }

    /** POST a JSON body (the field app's sync) with the page's CSRF token in a header. */
    public function json(string $path, array $body, string $csrf): ?array
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: $csrf"]]);
        $this->last = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return json_decode($this->last, true);
    }

    private function request(string $method, string $path, array $data = [], bool $follow = true): string
    {
        $GLOBALS['lastBrowser'] = $this;
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => false]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->location = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $this->last = $body;
    }
}

function check(bool $ok, string $what): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) {
        $failures++;
        echo "  FAIL  $what\n";
        global $lastBrowser;
        if ($lastBrowser && preg_match('/<div class="flash (error|warn)">(.*?)<\/div>/s', $lastBrowser->last, $fm)) {
            echo '        page says: ' . html_entity_decode(strip_tags($fm[2])) . "\n";
        } elseif ($lastBrowser) {
            echo '        at ' . $lastBrowser->location . ' (HTTP ' . $lastBrowser->status . ")\n";
        }
    } else {
        echo "  ok    $what\n";
    }
}

function clean(Browser $b, string $page): void
{
    check($b->status === 200 && !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Something went wrong)/', $b->last), "$page opens cleanly (HTTP {$b->status})");
}

/** Sign in, completing or setting up two-step sign-in with a computed code. */
function signin(string $base, string $email): Browser
{
    $b = new Browser($base);
    $b->get('login.php');
    $b->post('login.php', ['email' => $email, 'password' => 'Password123!']);
    if (str_contains((string) $b->location, 'mfa-setup.php')) {
        preg_match('/<b>([A-Z2-7 ]{20,})<\/b>/', $b->last, $m);
        $secret = str_replace(' ', '', $m[1] ?? '');
        $b->post('mfa-setup.php', ['code' => totp_code($secret, intdiv(time(), 30))]);
        check(str_contains($b->last, 'Save your recovery codes'), "$email sets up two-step sign-in");
        $b->get('index.php');
    }
    check(str_contains($b->last, 'Sign out'), "$email signs in");
    return $b;
}

echo "SFMTP smoke test against $base\n";

// Guests.
$g = new Browser($base);
$g->get('dashboard.php');
check(str_contains((string) $g->location, 'login.php'), 'a guest is sent to sign in');
$g->get('login.php');
$g->post('login.php', ['email' => 'owner@aggfarms.test', 'password' => 'wrong']);
check(str_contains($g->last, 'Wrong email or password'), 'a wrong password is refused');
$g->post('login.php', ['email' => 'owner@aggfarms.test', 'password' => 'Password123!', '_csrf' => 'forged'], true);

// Public QR page.
$g->get('q.php?c=NOPE123456');
check($g->status === 404, 'an unknown QR code is a 404');

// Owner: every page, then the money and traceability flows.
echo "\nOwner\n";
$o = signin($base, 'owner@aggfarms.test');
$farms = [];
preg_match_all('/<option value="([0-9a-f-]{36})"( selected)?>([^<]+)<\/option>/', $o->last, $m, PREG_SET_ORDER);
foreach ($m as $x) {
    $farms[html_entity_decode($x[3])] = $x[1];
}
check(count($farms) === 2, 'the owner has two farms');
$crop = $farms['AGG Crop Farm'] ?? '';
$mixed = $farms['AGG Mixed Farm'] ?? '';
$o->post('farms.php', ['farm_id' => $crop]);
foreach (['dashboard.php', 'tasks.php', 'structure.php', 'structure.php?tab=locations', 'crops.php', 'crops.php?tab=observations', 'crops.php?tab=harvests', 'crops.php?tab=crops',
    'inventory.php', 'inventory.php?tab=items', 'inventory.php?tab=movements', 'workers.php', 'workers.php?tab=attendance', 'workers.php?tab=leave',
    'finance.php', 'finance.php?tab=income', 'finance.php?tab=journal', 'finance.php?tab=pl', 'finance.php?tab=accounts', 'sales.php', 'sales.php?tab=invoices', 'sales.php?tab=customers', 'sales.php?tab=shipments', 'sales.php?tab=products',
    'purchasing.php', 'purchasing.php?tab=suppliers', 'purchasing.php?tab=invoices', 'portal-access.php',
    'trace.php', 'trace.php?tab=qr', 'trace.php?tab=integrity', 'members.php', 'members.php?tab=roles', 'audit-log.php', 'settings.php', 'profile.php', 'notifications.php', 'farms.php'] as $page) {
    $o->get($page);
    clean($o, "Crop farm: $page");
}
$o->get('trace.php?tab=integrity');
check(str_contains($o->last, 'Intact'), 'the seeded trace chain verifies');
$o->get('finance.php?tab=accounts');
check(str_contains($o->last, 'The books balance'), 'the seeded books balance');

// Plant, work, harvest, process, pack, publish, scan.
$o->get('crops.php?tab=cycles');
preg_match('/<select name="plot_id" required>.*?<option value="([0-9a-f-]{36})">/s', $o->last, $plot);
preg_match('/<select name="crop_id" required>.*?<option value="([0-9a-f-]{36})">/s', $o->last, $cropId);
$o->post('crops.php', ['action' => 'cycle', 'plot_id' => $plot[1] ?? '', 'crop_id' => $cropId[1] ?? '', 'planting_method' => 'direct', 'planted_on' => date('Y-m-d', strtotime('-90 days')), 'area_ha' => '1.5']);
$fresh = str_contains($o->last, 'planted on');
if (!$fresh && str_contains($o->last, 'already has a crop growing')) {
    // Every plot is busy: use an open cycle instead.
    $o->get('crops.php');
    preg_match('/cycle\.php\?id=([0-9a-f-]{36})/', $o->last, $cy);
    $o->get('cycle.php?id=' . ($cy[1] ?? ''));
}
preg_match('/cycle\.php\?id=([0-9a-f-]{36})/', (string) $o->location . ' ' . $o->last, $cy);
$cycleId = $cy[1] ?? '';
check($cycleId !== '', 'a crop cycle is open');
$o->get("cycle.php?id=$cycleId");
$o->post("cycle.php?id=$cycleId", ['action' => 'operation', 'type' => 'spraying', 'occurred_on' => date('Y-m-d', strtotime('-2 days')), 'product' => 'Test spray', 'quantity' => '2', 'unit' => 'L', 'withholding_days' => '14']);
check(str_contains($o->last, 'Spraying recorded'), 'field work with an input is recorded');
$o->post("cycle.php?id=$cycleId", ['action' => 'harvest', 'harvested_on' => date('Y-m-d'), 'quantity' => '500']);
check(str_contains($o->last, 'withholding period runs'), 'a harvest inside the withholding period is refused');
$o->post("cycle.php?id=$cycleId", ['action' => 'harvest', 'harvested_on' => date('Y-m-d'), 'quantity' => '500', 'override_reason' => 'Smoke test']);
check(str_contains($o->last, 'recorded as a new batch'), 'a harvest with a reason becomes a batch');
preg_match_all('/batch\.php\?id=([0-9a-f-]{36})/', $o->last, $bm);
$harvestBatch = end($bm[1]);
$o->get("batch.php?id=$harvestBatch");
$o->post("batch.php?id=$harvestBatch", ['action' => 'process', 'name' => 'Dried maize', 'used' => '500', 'quantity' => '460', 'unit' => 'kg', 'method' => 'Sun dried']);
preg_match('/batch\.php\?id=([0-9a-f-]{36})/', (string) $o->location, $pm);
$o->post('batch.php?id=' . ($pm[1] ?? ''), ['action' => 'package', 'name' => 'Maize 50 kg bags', 'packages' => '9', 'package_size' => '50 kg', 'quantity' => '450', 'unit' => 'kg', 'used' => '450']);
preg_match('/batch\.php\?id=([0-9a-f-]{36})/', (string) $o->location, $km);
$packed = $km[1] ?? '';
check($packed !== '' && str_contains($o->last, 'Packaged'), 'processed and packed');
$o->post("batch.php?id=$packed", ['action' => 'publish', 'fields' => ['product', 'farm', 'region', 'crop', 'dates', 'inputs', 'journey'], 'label' => 'Smoke labels']);
preg_match('/<span class="qr">([0-9A-Z]{10})<\/span>/', $o->last, $qm);
$code = $qm[1] ?? '';
check($code !== '', 'published with a QR code');
$g->get("q.php?c=$code");
check($g->status === 200 && str_contains($g->last, 'Maize 50 kg bags') && str_contains($g->last, 'Test spray'), 'the public page shows the approved fields');
check(!str_contains($g->last, 'Smoke test'), 'the public page does not show private notes');
$o->get('trace.php?tab=qr');
preg_match('/labels\.php\?id=([0-9a-f-]{36})/', $o->last, $lm);
$o->get('labels.php?id=' . ($lm[1] ?? ''));
check(str_contains($o->last, '<svg'), 'labels print with QR codes');
$o->get('trace.php?tab=integrity');
check(str_contains($o->last, 'Intact'), 'the trace chain still verifies after new events');

// Money: expense, invoice, payment; books still balance.
$o->get('finance.php');
preg_match('/<select name="account_id" required>.*?<option value="([0-9a-f-]{36})">/s', $o->last, $acc);
$o->post('finance.php', ['action' => 'expense', 'account_id' => $acc[1] ?? '', 'amount' => '45000', 'spent_on' => date('Y-m-d'), 'description' => 'Smoke fuel', 'payee' => 'Station']);
check(str_contains($o->last, 'Expense recorded'), 'an expense is recorded');
$o->get('sales.php?tab=invoices');
preg_match('/<select name="customer_id" required>.*?<option value="([0-9a-f-]{36})">/s', $o->last, $cust);
preg_match('/name="lines\[0\]\[account_id\]">.*?<option value="([0-9a-f-]{36})"/s', $o->last, $inc);
$o->post('sales.php', ['action' => 'invoice', 'customer_id' => $cust[1] ?? '', 'invoice_date' => date('Y-m-d'), 'lines' => [['description' => 'Maize', 'quantity' => '10', 'unit' => 'bags', 'unit_price' => '95000', 'account_id' => $inc[1] ?? '']]]);
check(str_contains($o->last, 'Invoice issued'), 'an invoice is issued');
preg_match('/invoice\.php\?id=([0-9a-f-]{36})/', (string) $o->location, $im);
preg_match('/name="account_id" required><option value="([0-9a-f-]{36})"/', $o->last, $cash);
$o->post('invoice.php?id=' . ($im[1] ?? ''), ['action' => 'payment', 'amount' => '2000000', 'account_id' => $cash[1] ?? '', 'method' => 'cash']);
check(str_contains($o->last, 'at most'), 'a payment above what is owed is refused');
$o->post('invoice.php?id=' . ($im[1] ?? ''), ['action' => 'payment', 'amount' => '950000', 'account_id' => $cash[1] ?? '', 'method' => 'mobile_money']);
check(str_contains($o->last, 'Payment of') && str_contains($o->last, 'badge ok">Paid'), 'the invoice is paid in full');
$o->get('finance.php?tab=accounts');
check(str_contains($o->last, 'The books balance'), 'the books still balance');

// Stock: receive and issue at average cost.
$o->get('inventory.php?tab=items');
preg_match('/<select name="category_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})">/s', $o->last, $cat);
$o->post('inventory.php', ['action' => 'item', 'name' => 'Smoke fertiliser', 'category_id' => $cat[1] ?? '', 'unit' => 'kg']);
check(str_contains($o->last, 'Smoke fertiliser added'), 'an item is added');
$o->get('inventory.php');
preg_match('/<select name="item_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})">/s', $o->last, $item);
preg_match('/<select name="location_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})">/s', $o->last, $store);
check($item && $store, 'the receive form lists items and stores');
if ($item && $store) {
    $o->post('inventory.php', ['action' => 'receive', 'item_id' => $item[1], 'location_id' => $store[1], 'quantity' => '10', 'unit_cost' => '1000', 'credit' => '1000']);
    check(str_contains($o->last, 'Received'), 'stock is received');
    preg_match('/<select name="balance_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})">/s', $o->last, $bal);
    $o->post('inventory.php', ['action' => 'issue', 'balance_id' => $bal[1] ?? '', 'quantity' => '99999']);
    check(str_contains($o->last, 'Not enough stock'), 'issuing more than there is is refused');
}

// Manager on the mixed farm: plans work; cannot see finance; cannot reach the crop farm's records.
echo "\nManager\n";
$mgr = signin($base, 'manager@aggfarms.test');
$mgr->get('finance.php');
check($mgr->status === 403, 'the manager cannot open finance');
$mgr->get("cycle.php?id=$cycleId");
check($mgr->status === 404, "another farm's record is a 404");
$mgr->get("batch.php?id=$packed");
check($mgr->status === 404, "another farm's batch is a 404");
$mgr->get('tasks.php');
preg_match('/<select name="activity_type_id" required>.*?<option value="([0-9a-f-]{36})">/s', $mgr->last, $type);
preg_match_all('/name="worker_ids\[\]" value="([0-9a-f-]{36})"/', $mgr->last, $wk);
$mgr->post('tasks.php', ['activity_type_id' => $type[1] ?? '', 'title' => 'Smoke: clean the water trough', 'worker_ids' => $wk[1], 'planned_on' => date('Y-m-d')]);
check(str_contains($mgr->last, 'task(s) assigned'), 'the manager assigns work');
$mgr->get('workers.php');
clean($mgr, 'workers');

// Field worker: own tasks only, no money, does the work.
echo "\nField worker\n";
$w = signin($base, 'worker@aggfarms.test');
$w->post('farms.php', ['farm_id' => $mixed]);
foreach (['finance.php', 'inventory.php', 'members.php', 'sales.php', 'settings.php'] as $page) {
    $w->get($page);
    check($w->status === 403, "the worker cannot open $page");
}
$w->get('tasks.php');
check(str_contains($w->last, 'My tasks') && str_contains($w->last, 'Smoke: clean the water trough'), 'the worker sees the new task');
preg_match('/task\.php\?id=([0-9a-f-]{36})"><b>[^<]+<\/b><\/a> Smoke: clean/', $w->last, $tm);
$taskId = $tm[1] ?? '';
$w->get("task.php?id=$taskId");
$w->post("task.php?id=$taskId", ['event' => 'start']);
check(str_contains($w->last, 'In progress'), 'the worker starts the task');
$w->post("task.php?id=$taskId", ['event' => 'submit', 'quantity' => '1', 'unit' => 'trough', 'note' => 'Done']);
check(str_contains($w->last, 'Submitted'), 'the worker submits it');
$w->post("task.php?id=$taskId", ['event' => 'verify']);
check(str_contains($w->last, 'not allowed') || $w->status === 403 || str_contains($w->last, 'Someone else'), 'the worker cannot verify their own work');
$mgr->get("task.php?id=$taskId");
$mgr->post("task.php?id=$taskId", ['event' => 'verify', 'note' => 'Good']);
check(str_contains($mgr->last, 'Verified'), 'the manager verifies it');

// Supplier portal, with the farm's side of purchasing.
echo "\nSupplier portal\n";
$sup = new Browser($base);
$sup->get('login.php');
$sup->post('login.php', ['email' => 'supplier@aggfarms.test', 'password' => 'Password123!']);
check(str_contains((string) $sup->location, 'portal.php') && str_contains($sup->last, 'AGG Mixed Farm'), 'the supplier signs in to the portal');
foreach (['portal.php', 'supplier.php', 'supplier.php?tab=invoices', 'profile.php'] as $page) {
    $sup->get($page);
    clean($sup, "supplier: $page");
}
$sup->get('finance.php');
check(str_contains((string) $sup->location, 'portal.php'), 'the supplier has no farm pages');
$sup->get('shop.php');
check($sup->status === 403, 'the supplier has no customer portal');
$sup->get('supplier.php');
preg_match('/supplier-order\.php\?id=([0-9a-f-]{36})"><b>PO-002/', $sup->last, $pm);
$po = $pm[1] ?? '';
check($po !== '' && !str_contains($sup->last, 'PO-003'), 'the supplier sees sent orders, not drafts');
$sup->get("supplier-order.php?id=$po");
preg_match_all('/name="qty\[([0-9a-f-]{36})\]" inputmode="decimal" value="([^"]*)"/', $sup->last, $qm, PREG_SET_ORDER);
$sup->post("supplier-order.php?id=$po", ['action' => 'dispatch', 'reference' => 'DN-555', 'vehicle' => 'UBB 123X', 'qty' => array_column($qm, 2, 1)]);
check(str_contains($sup->last, 'told what is on the way'), 'the supplier announces a dispatch');

$o->post('farms.php', ['farm_id' => $mixed]);
$o->get("po.php?id=$po");
clean($o, 'purchase order page');
preg_match('/<select name="dispatch_id">(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $o->last, $dm);
preg_match('/<select name="location_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $o->last, $lm);
preg_match_all('/name="lines\[([0-9a-f-]{36})\]\[quantity\]" inputmode="decimal" value="([^"]*)"/', $o->last, $rm, PREG_SET_ORDER);
$o->post("po.php?id=$po", ['action' => 'receive', 'location_id' => $lm[1] ?? '', 'dispatch_id' => $dm[1] ?? '', 'lines' => array_map(fn ($x) => ['quantity' => $x], array_column($rm, 2, 1))]);
check(str_contains($o->last, 'Goods received into stock'), 'the farm receives the dispatch into stock');

$sup->get("supplier-order.php?id=$po");
preg_match_all('/name="inv_qty\[([0-9a-f-]{36})\]" inputmode="decimal" value="([^"]*)"/', $sup->last, $im, PREG_SET_ORDER);
$sup->post("supplier-order.php?id=$po", ['action' => 'invoice', 'invoice_number' => 'KAV-9001', 'inv_qty' => array_column($im, 2, 1)]);
check(str_contains($sup->last, 'Invoice sent'), 'the supplier sends an invoice for what was received');
$sup->post("supplier-order.php?id=$po", ['action' => 'invoice', 'invoice_number' => 'KAV-9001', 'inv_qty' => array_column($im, 2, 1)]);
check(str_contains($sup->last, 'already been sent'), 'the same invoice number is refused');
$o->get("po.php?id=$po");
preg_match('/name="submission_id" value="([0-9a-f-]{36})"/', $o->last, $sm);
$o->post("po.php?id=$po", ['action' => 'record_sub', 'submission_id' => $sm[1] ?? '']);
check(str_contains($o->last, 'KAV-9001 recorded'), 'the farm records the submitted invoice');
preg_match('/<select name="invoice_id" required>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $o->last, $pim);
preg_match('/<select name="account_id" required><option value="([0-9a-f-]{36})"/', $o->last, $pam);
$o->post("po.php?id=$po", ['action' => 'pay', 'invoice_id' => $pim[1] ?? '', 'amount' => '50000', 'account_id' => $pam[1] ?? '', 'method' => 'mobile_money']);
check(str_contains($o->last, 'Payment of'), 'the farm pays the supplier');
$o->get('finance.php?tab=accounts');
check(str_contains($o->last, 'The books balance'), 'the books balance after receiving, invoicing and paying');
$sup->get('supplier.php?tab=invoices');
check(str_contains($sup->last, 'KAV-9001') && str_contains($sup->last, '50,000'), 'the supplier sees the invoice and the payment');

// Customer portal, with the farm's side of sales orders.
echo "\nCustomer portal\n";
$cus = new Browser($base);
$cus->get('login.php');
$cus->post('login.php', ['email' => 'customer@aggfarms.test', 'password' => 'Password123!']);
check(str_contains((string) $cus->location, 'portal.php'), 'the customer signs in to the portal');
foreach (['shop.php', 'customer.php', 'customer.php?tab=invoices', 'customer.php?tab=purchases'] as $page) {
    $cus->get($page);
    clean($cus, "customer: $page");
}
$cus->get("supplier-order.php?id=$po");
check($cus->status === 403, 'the customer cannot open purchase orders');
$cus->get('shop.php');
preg_match_all('/name="qty\[([0-9a-f-]{36})\]"/', $cus->last, $pq);
preg_match('/name="farm_id" value="([0-9a-f-]{36})"><input type="hidden" name="customer_id" value="([0-9a-f-]{36})"/', $cus->last, $fc);
$cus->post('shop.php', ['farm_id' => $fc[1] ?? '', 'customer_id' => $mixed, 'qty' => [$pq[1][0] ?? '' => '100']]);
check($cus->status === 404, 'ordering as a record that is not yours is a 404');
$cus->get('shop.php');
$cus->post('shop.php', ['farm_id' => $fc[1] ?? '', 'customer_id' => $fc[2] ?? '', 'qty' => [$pq[1][0] ?? '' => '100'], 'customer_note' => 'Call before delivery']);
check(str_contains($cus->last, 'Order placed'), 'the customer orders at list price');
preg_match('/id=([0-9a-f-]{36})/', (string) $cus->location, $om);
$so = $om[1] ?? '';

$o->post('farms.php', ['farm_id' => $crop]);
$o->get("order.php?id=$so");
clean($o, 'sales order page');
$o->post("order.php?id=$so", ['action' => 'approve']);
check(str_contains($o->last, 'approved'), 'the farm approves the order');
preg_match_all('/name="picks\[([0-9a-f-]{36})\]\[batch_id\]">(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $o->last, $bm, PREG_SET_ORDER);
$o->post("order.php?id=$so", ['action' => 'dispatch', 'picks' => array_map(fn ($b) => ['batch_id' => $b, 'quantity' => '100'], array_column($bm, 2, 1))]);
check(str_contains($o->last, 'Shipment dispatched'), 'the farm ships it from a batch');
$o->post("order.php?id=$so", ['action' => 'invoice']);
check(str_contains($o->last, 'Invoice issued'), 'the farm invoices the order');
$cus->get("customer-order.php?id=$so");
preg_match('/name="shipment_id" value="([0-9a-f-]{36})"/', $cus->last, $shm);
$cus->post("customer-order.php?id=$so", ['action' => 'received', 'shipment_id' => $shm[1] ?? '']);
check(str_contains($cus->last, 'goods arrived') && str_contains($cus->last, 'badge ok">Delivered'), 'the customer confirms delivery and the order is delivered');
$cus->get('customer.php?tab=invoices');
check(str_contains($cus->last, 'customer-invoice.php'), 'the customer sees the invoice');
$cus->get('customer.php?tab=purchases');
check(str_contains($cus->last, 'SFM-'), 'the customer sees the batches they bought');
$o->get('trace.php?tab=integrity');
check(str_contains($o->last, 'Intact'), 'the trace chain still verifies');

// Invitation: a new customer gets access, uses it once, and loses it when stopped.
$o->get('portal-access.php');
preg_match_all('/<option value="(customer:[0-9a-f-]{36})"/', $o->last, $wm);
$o->post('portal-access.php', ['action' => 'invite', 'who' => end($wm[1]), 'email' => 'buyer2@example.test']);
preg_match('/invite\.php\?token=([0-9a-f]{48})/', $o->last, $tm);
check(isset($tm[1]), 'the farm creates an invitation link');
$nb = new Browser($base);
$nb->get('invite.php?token=' . ($tm[1] ?? ''));
$nb->post('invite.php?token=' . ($tm[1] ?? ''), ['token' => $tm[1] ?? '', 'name' => 'Buyer Two', 'password' => 'GoodPass123', 'confirm' => 'GoodPass123']);
check(str_contains($nb->last, 'you are now connected'), 'a new person accepts it and gets an account');
$nb->get('shop.php');
check($nb->status === 200, 'the new customer can order');
$again = new Browser($base);
$again->get('invite.php?token=' . ($tm[1] ?? ''));
check(str_contains($again->last, 'already been used'), 'an invitation works only once');
$o->get('portal-access.php');
preg_match('/buyer2@example\.test.*?name="link_id" value="([0-9a-f-]{36})"/s', $o->last, $lk);
$o->post('portal-access.php', ['action' => 'unlink', 'link_id' => $lk[1] ?? '']);
$nb->get('shop.php');
check($nb->status === 403, 'stopping access closes the portal at once');

// Purchase requests, payroll, budgets and reports.
echo "\nRequests, payroll, budgets, reports\n";
$o->post('farms.php', ['farm_id' => $mixed]);
$mgr->get('purchasing.php?tab=requests');
clean($mgr, 'manager: purchase requests');
preg_match('/<select name="lines\[0\]\[item_id\]">(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $mgr->last, $rim);
$mgr->post('purchasing.php', ['action' => 'request', 'reason' => 'Smoke: running low', 'lines' => [['item_id' => $rim[1] ?? '', 'quantity' => '4', 'estimated_unit_price' => '2500']]]);
check(str_contains($mgr->last, 'Request sent for approval'), 'the manager requests items');
preg_match('/Smoke: running low.*?name="request_id" value="([0-9a-f-]{36})"/s', $mgr->last, $rq);
$mgr->post('purchasing.php?tab=requests', ['action' => 'decide', 'request_id' => $rq[1] ?? '', 'decision' => 'approve']);
check(str_contains($mgr->last, 'Someone else must approve'), 'nobody approves their own request');
$o->get('purchasing.php?tab=requests');
$o->post('purchasing.php?tab=requests', ['action' => 'decide', 'request_id' => $rq[1] ?? '', 'decision' => 'approve']);
check(str_contains($o->last, 'approved'), 'the owner approves the request');
preg_match('/name="request_id" value="' . preg_quote($rq[1] ?? 'x', '/') . '"><select name="supplier_id" required[^>]*>(?:(?!<\/select>).)*?<option value="([0-9a-f-]{36})"/s', $o->last, $rs);
$o->post('purchasing.php?tab=requests', ['action' => 'order_request', 'request_id' => $rq[1] ?? '', 'supplier_id' => $rs[1] ?? '']);
check(str_contains($o->last, 'Draft purchase order made from the request'), 'the approved request becomes a purchase order');

foreach (['payroll.php', 'budgets.php', 'reports.php'] as $page) {
    $o->get($page);
    clean($o, "owner: $page");
}
// Pay from the day after the last payroll (at most 30 days back) to today, with today's check-in in it.
$w->get('dashboard.php');
$w->post('workers.php', ['action' => 'check_in']);
$o->get('payroll.php');
preg_match('/name="period_start" required value="([^"]+)"/', $o->last, $ps);
$from = max($ps[1] ?? date('Y-m-d'), date('Y-m-d', strtotime('-30 days')));
$o->post('payroll.php', ['action' => 'prepare', 'period_start' => $from, 'period_end' => date('Y-m-d')]);
check(str_contains($o->last, 'Payroll prepared from attendance'), 'payroll is prepared from attendance');
$run = str_replace($base . '/', '', (string) $o->location);
$o->post($run, ['action' => 'approve']);
check(str_contains($o->last, 'wages are now owed'), 'the owner approves the payroll');
preg_match('/<select name="account_id" required><option value="([0-9a-f-]{36})"/', $o->last, $pa);
preg_match('/name="amount" required inputmode="decimal" value="([^"]+)"/', $o->last, $pm2);
if ((float) ($pm2[1] ?? 0) > 0) {
    $o->post($run, ['action' => 'pay', 'amount' => $pm2[1], 'account_id' => $pa[1] ?? '', 'method' => 'mobile_money']);
    check(str_contains($o->last, 'Payment of'), 'the workers are paid');
}
$o->get($run . '&view=slips');
clean($o, 'payslips');
$mgr->get(preg_replace('/&view=slips$/', '', $run));
check($mgr->status === 200 && !str_contains($mgr->last, 'UGX') && !str_contains($mgr->last, 'name="action" value="approve"'), 'the manager sees hours but no pay and cannot approve');
$o->post('budgets.php', ['action' => 'create', 'name' => 'Smoke budget', 'period_start' => date('Y-01-01'), 'period_end' => date('Y-12-31'), 'scope' => '']);
preg_match_all('/name="amount\[([0-9a-f-]{36})\]"/', $o->last, $ba);
$o->post(str_replace($base . '/', '', (string) $o->location), ['action' => 'lines', 'amount' => [$ba[1][0] ?? 'x' => '500000', $ba[1][8] ?? 'y' => '300000']]);
check(str_contains($o->last, 'Budget saved') && str_contains($o->last, 'Actual costs'), 'a budget compares plan and actual');
foreach (['cash_flow', 'sales_by_customer', 'sales_by_product', 'purchases_by_supplier', 'aged_receivables', 'aged_payables', 'stock_valuation', 'labour', 'cost_centres', 'general_ledger', 'qr_scans'] as $r) {
    $o->get("reports.php?r=$r&from=" . date('Y-01-01') . '&to=' . date('Y-m-d'));
    clean($o, "report $r");
}
$o->get('reports.php?r=general_ledger&format=csv');
check(str_starts_with($o->last, "\xEF\xBB\xBF") && str_contains($o->last, 'Debit'), 'a report downloads as CSV');
$w->get('reports.php?r=general_ledger');
check(!str_contains($w->last, 'Debit'), 'the worker cannot read the ledger');
$o->get('finance.php?tab=accounts');
check(str_contains($o->last, 'The books balance'), 'the books balance after payroll');

// Field app: actions queued on a phone are applied once, in order.
echo "\nField app\n";
$w->get('field.php');
clean($w, 'field app page');
preg_match('/name="csrf" content="([^"]+)"/', $w->last, $fcs);
$w->get('sync.php');
$state = json_decode($w->last, true)['state'] ?? [];
check(($state['can']['attendance'] ?? false) && isset($state['worker']['id']), 'the app gets the worker, tasks and permissions');
$fu = fn () => sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$open = array_values(array_filter($state['tasks'] ?? [], fn ($t) => in_array($t['status'], ['assigned', 'rejected', 'in_progress'], true)));
$muts = [['id' => $fu(), 'farm_id' => $state['farm']['id'] ?? '', 'type' => 'observation.create', 'occurred_at' => gmdate('Y-m-d H:i:s'), 'data' => []]];
if ($open) {
    $first = $open[0];
    if ($first['status'] !== 'in_progress') {
        $muts[] = ['id' => $fu(), 'farm_id' => $state['farm']['id'], 'type' => 'task.start', 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 1800), 'data' => ['task_id' => $first['id']]];
    }
    $muts[] = ['id' => $fu(), 'farm_id' => $state['farm']['id'], 'type' => 'task.submit', 'occurred_at' => gmdate('Y-m-d H:i:s', time() - 60), 'data' => ['task_id' => $first['id'], 'quantity' => '2', 'note' => 'offline']];
}
$res = $w->json('sync.php', ['device_id' => $fu(), 'mutations' => $muts], $fcs[1] ?? '');
$statuses = array_column($res['results'] ?? [], 'status');
check($statuses && $statuses[0] === 'rejected' && !in_array('retry', $statuses, true), 'an action the worker may not do is refused, not retried');
check(!$open || end($statuses) === 'applied', 'queued task steps are applied');
$again = $w->json('sync.php', ['device_id' => $fu(), 'mutations' => $muts], $fcs[1] ?? '');
check(count(array_filter($again['results'] ?? [], fn ($r) => !empty($r['repeat']))) === count($muts), 'sending the same actions again changes nothing');
$w->json('sync.php', ['mutations' => []], 'forged');
check($w->status === 419, 'sync refuses a forged request');

// Platform admin.
echo "\nPlatform admin\n";
$a = signin($base, 'admin@sfmtp.test');
$a->get('admin.php');
clean($a, 'admin farms');
$a->get('admin.php?tab=accounts');
clean($a, 'admin accounts');
$a->get('finance.php');
check(str_contains((string) $a->location, 'admin.php'), 'platform staff have no farm pages');

// Integrations: email and SMS (log drivers), online payment (test gateway, debug only).
echo "\nIntegrations\n";
foreach ([['email', 'log'], ['sms', 'log'], ['payment', 'simulator']] as [$kind, $driver]) {
    $a->get('admin.php?tab=integrations');
    $a->post('admin.php?tab=integrations', ['action' => 'provider', 'kind' => $kind, 'provider' => $driver, 'name' => "Smoke $kind"]);
    check(str_contains($a->last, "Smoke $kind saved"), "platform staff add a $kind service");
}
$a->post('admin.php?tab=integrations', ['action' => 'provider_test', 'kind' => 'sms', 'to' => '+256772000111']);
check(str_contains($a->last, 'Test logged through Smoke sms'), 'a test SMS goes through the SMS service');
$a->get('admin.php?tab=messages');
clean($a, 'messages sent');
$g2 = new Browser($base);
$g2->get('login.php');
check(str_contains($g2->last, 'forgot.php'), 'with email set up, sign-in offers password reset');
$g2->get('forgot.php');
$g2->post('forgot.php', ['email' => 'nobody@example.test']);
check(str_contains($g2->last, 'If that address has an account'), 'password reset does not reveal which accounts exist');
$o->post('farms.php', ['farm_id' => $crop]);
$o->get('settings.php');
preg_match('/name="name" required value="([^"]*)"/', $o->last, $fnm);
$o->post('settings.php', ['name' => html_entity_decode($fnm[1] ?? 'AGG Crop Farm'), 'online_payments' => '1']);
check(str_contains($o->last, 'Settings saved'), 'the farm turns on online payments');
$cus->get('customer.php?tab=invoices');
$issued = null;
foreach ([...array_unique((preg_match_all('/customer-invoice\.php\?id=([0-9a-f-]{36})/', $cus->last, $im2) ? $im2[1] : []))] as $iid) {
    $cus->get("customer-invoice.php?id=$iid");
    if (str_contains($cus->last, 'online</button>')) {
        $issued = $iid;
        break;
    }
}
check($issued !== null, 'the customer can pay an open invoice online');
if ($issued) {
    $cus->post("customer-invoice.php?id=$issued", ['action' => 'pay']);
    preg_match('/href="([^"]*pay-simulator\.php[^"]*)"/', $cus->last, $sm2);
    $cus->get(html_entity_decode($sm2[1] ?? 'pay-simulator.php'));
    $cus->post(html_entity_decode($sm2[1] ?? 'pay-simulator.php'), ['outcome' => 'pay']);
    check(str_contains($cus->last, 'Thank you'), 'the payment is confirmed and recorded');
    $cus->get("customer-invoice.php?id=$issued");
    check(str_contains($cus->last, 'badge ok">Paid'), 'the invoice shows as paid');
    $o->get('finance.php?tab=accounts');
    check(str_contains($o->last, 'The books balance'), 'the books balance after the online payment');
}
$wh = curl_init("$base/pay-webhook.php");
curl_setopt_array($wh, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"data":{"id":1,"tx_ref":"SFMP-0000000000000000"}}', CURLOPT_RETURNTRANSFER => true]);
curl_exec($wh);
check(curl_getinfo($wh, CURLINFO_RESPONSE_CODE) === 401, 'a payment notification without the secret hash is refused');

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
