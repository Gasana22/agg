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
    'finance.php', 'finance.php?tab=income', 'finance.php?tab=journal', 'finance.php?tab=pl', 'finance.php?tab=accounts', 'sales.php', 'sales.php?tab=customers', 'sales.php?tab=shipments',
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
$o->get('sales.php');
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

// Platform admin.
echo "\nPlatform admin\n";
$a = signin($base, 'admin@sfmtp.test');
$a->get('admin.php');
clean($a, 'admin farms');
$a->get('admin.php?tab=accounts');
clean($a, 'admin accounts');
$a->get('finance.php');
check(str_contains((string) $a->location, 'admin.php'), 'platform staff have no farm pages');

echo "\n$checks checks, $failures failed\n";
exit($failures ? 1 : 0);
