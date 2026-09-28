<?php
/*
 * Outside services: email, SMS and online payments.
 *
 * Platform staff add providers under Platform admin → Integrations. A
 * provider's settings (API keys, passwords) are stored encrypted with the
 * secret key from config.php. When several providers of a kind are enabled,
 * the default is tried first, then the others by priority; a provider that
 * failed three times in a row rests for five minutes.
 *
 * Every email and SMS is written to message_outbox with its outcome. The
 * "log" drivers send nothing and only write there, which is handy for demos.
 */

const PROVIDER_DRIVERS = [
    'email' => [
        'smtp' => ['SMTP server', ['host' => 'Host', 'port' => 'Port (587 or 465)', 'encryption' => 'Encryption (tls, ssl or none)', 'username' => 'Username', 'password' => '*Password', 'from_email' => 'From address', 'from_name' => 'From name']],
        'phpmail' => ['PHP mail() (shared hosting)', ['from_email' => 'From address', 'from_name' => 'From name']],
        'log' => ['Log only (sends nothing)', []],
    ],
    'sms' => [
        'africastalking' => ["Africa's Talking", ['username' => 'Username', 'api_key' => '*API key', 'sender_id' => 'Sender ID (optional)', 'sandbox' => 'Sandbox (yes/no)']],
        'log' => ['Log only (sends nothing)', []],
    ],
    'payment' => [
        'flutterwave' => ['Flutterwave', ['secret_key' => '*Secret key', 'webhook_hash' => '*Webhook secret hash']],
        'simulator' => ['Test gateway (no real money; works only with debug on)', []],
    ],
];

function provider_config(array $p): array
{
    return json_decode((string) unseal((string) $p['config']), true) ?: [];
}

/** Enabled providers of a kind in the order they are tried. */
function providers(string $kind): array
{
    $all = rows('SELECT * FROM integration_providers WHERE kind = ? AND is_enabled = 1 ORDER BY is_default DESC, priority, name', [$kind]);
    return array_values(array_filter($all, fn ($p) => !((int) $p['consecutive_failures'] >= 3 && $p['last_failure_at'] && strtotime($p['last_failure_at'] . ' UTC') > time() - 300)));
}

function has_provider(string $kind): bool
{
    return (bool) val('SELECT 1 FROM integration_providers WHERE kind = ? AND is_enabled = 1', [$kind]);
}

function provider_result(array $p, ?string $error): void
{
    if ($error === null) {
        q('UPDATE integration_providers SET last_success_at = ?, consecutive_failures = 0, last_error = NULL WHERE id = ?', [now_utc(), $p['id']]);
    } else {
        q('UPDATE integration_providers SET last_failure_at = ?, consecutive_failures = consecutive_failures + 1, last_error = ? WHERE id = ?', [now_utc(), mb_substr($error, 0, 500), $p['id']]);
    }
}

function outbox(string $channel, string $to, ?string $subject, string $body, ?string $purpose, string $status, ?string $provider, ?string $error): void
{
    $farm = function_exists('current_farm') && current_user() ? current_farm() : null;
    insert('message_outbox', ['id' => uuid(), 'farm_id' => $farm['id'] ?? null, 'channel' => $channel, 'recipient' => mb_substr($to, 0, 255), 'subject' => $subject ? mb_substr($subject, 0, 200) : null,
        'body' => $body, 'purpose' => $purpose, 'status' => $status, 'provider' => $provider, 'error' => $error ? mb_substr($error, 0, 500) : null, 'created_at' => now_utc()]);
}

/** Send an email through the first provider that works. Returns true when sent (or logged). Never throws. */
function send_email(string $to, string $subject, string $text, ?string $purpose = null): bool
{
    $to = trim($to);
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $list = providers('email');
    if (!$list) {
        outbox('email', $to, $subject, $text, $purpose, 'failed', null, 'No email provider is set up.');
        return false;
    }
    $error = null;
    foreach ($list as $p) {
        try {
            $c = provider_config($p);
            match ($p['provider']) {
                'smtp' => smtp_send($c, $to, $subject, $text),
                'phpmail' => phpmail_send($c, $to, $subject, $text),
                'log' => null,
            };
            provider_result($p, null);
            outbox('email', $to, $subject, $text, $purpose, $p['provider'] === 'log' ? 'logged' : 'sent', $p['name'], null);
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            provider_result($p, $error);
        }
    }
    outbox('email', $to, $subject, $text, $purpose, 'failed', end($list)['name'], $error);
    return false;
}

/** Send an SMS through the first provider that works. $quiet: do nothing when no SMS provider is set up. */
function send_sms(string $phone, string $text, ?string $purpose = null, bool $quiet = false): bool
{
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!preg_match('/^\+?\d{9,15}$/', $phone)) {
        return false;
    }
    if (str_starts_with($phone, '0')) {
        $phone = '+256' . substr($phone, 1); // local Ugandan numbers
    } elseif (!str_starts_with($phone, '+')) {
        $phone = '+' . $phone;
    }
    $list = providers('sms');
    if (!$list) {
        $quiet || outbox('sms', $phone, null, $text, $purpose, 'failed', null, 'No SMS provider is set up.');
        return false;
    }
    $error = null;
    foreach ($list as $p) {
        try {
            if ($p['provider'] === 'africastalking') {
                africastalking_send(provider_config($p), $phone, mb_substr($text, 0, 459));
            }
            provider_result($p, null);
            outbox('sms', $phone, null, $text, $purpose, $p['provider'] === 'log' ? 'logged' : 'sent', $p['name'], null);
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            provider_result($p, $error);
        }
    }
    outbox('sms', $phone, null, $text, $purpose, 'failed', end($list)['name'], $error);
    return false;
}

/* ---------- Email drivers ---------- */

function mail_message(array $c, string $to, string $subject, string $text): array
{
    $from = $c['from_email'] ?? '';
    filter_var($from, FILTER_VALIDATE_EMAIL) || throw new RuntimeException('The provider has no valid From address.');
    $name = preg_replace('/[\r\n"]+/', '', (string) ($c['from_name'] ?? config('app_name', 'SFMTP')));
    $headers = [
        'From' => '=?UTF-8?B?' . base64_encode($name) . "?= <$from>",
        'To' => "<$to>",
        'Subject' => '=?UTF-8?B?' . base64_encode($subject) . '?=',
        'Date' => date(DATE_RFC2822),
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
    ];
    return [$from, $headers, chunk_split(base64_encode($text))];
}

function phpmail_send(array $c, string $to, string $subject, string $text): void
{
    [$from, $h, $body] = mail_message($c, $to, $subject, $text);
    $extra = $h;
    unset($extra['To'], $extra['Subject']);
    mail($to, $h['Subject'], $body, $extra, '-f' . $from) || throw new RuntimeException('mail() refused the message.');
}

/** A small SMTP client: STARTTLS or implicit TLS, AUTH LOGIN. */
function smtp_send(array $c, string $to, string $subject, string $text): void
{
    [$from, $h, $body] = mail_message($c, $to, $subject, $text);
    $host = (string) ($c['host'] ?? '');
    $port = (int) ($c['port'] ?? 587);
    $enc = strtolower((string) ($c['encryption'] ?? 'tls'));
    $host !== '' || throw new RuntimeException('The SMTP host is missing.');
    $s = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    $s || throw new RuntimeException("Cannot reach $host:$port ($errstr).");
    stream_set_timeout($s, 15);
    $read = function () use ($s): string {
        $all = '';
        while (($line = fgets($s, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $all;
    };
    $cmd = function (?string $line, array $ok) use ($s, $read): string {
        if ($line !== null) {
            fwrite($s, $line . "\r\n");
        }
        $reply = $read();
        in_array((int) substr($reply, 0, 3), $ok, true) || throw new RuntimeException('SMTP: ' . trim($reply ?: 'no answer'));
        return $reply;
    };
    try {
        $cmd(null, [220]);
        $me = gethostname() ?: 'localhost';
        $cmd("EHLO $me", [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) || throw new RuntimeException('SMTP: TLS failed.');
            $cmd("EHLO $me", [250]);
        }
        if (!empty($c['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $c['username']), [334]);
            $cmd(base64_encode((string) ($c['password'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $data = '';
        foreach ($h as $k => $v) {
            $data .= "$k: $v\r\n";
        }
        $cmd($data . "\r\n" . $body . "\r\n.", [250]);
        $cmd('QUIT', [221]);
    } finally {
        fclose($s);
    }
}

/* ---------- SMS driver ---------- */

function http_json(string $method, string $url, array $headers, ?string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $raw === false && throw new RuntimeException("Cannot reach the provider: $err");
    return [$code, json_decode((string) $raw, true) ?? [], (string) $raw];
}

function africastalking_send(array $c, string $phone, string $text): void
{
    $sandbox = in_array(strtolower((string) ($c['sandbox'] ?? '')), ['yes', '1', 'true'], true);
    $fields = ['username' => $sandbox ? 'sandbox' : (string) ($c['username'] ?? ''), 'to' => $phone, 'message' => $text];
    if (!empty($c['sender_id'])) {
        $fields['from'] = $c['sender_id'];
    }
    [$code, $json, $raw] = http_json('POST', 'https://api.' . ($sandbox ? 'sandbox.' : '') . 'africastalking.com/version1/messaging',
        ['apiKey: ' . ($c['api_key'] ?? ''), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], http_build_query($fields));
    $status = $json['SMSMessageData']['Recipients'][0]['status'] ?? null;
    ($code < 300 && $status === 'Success') || throw new RuntimeException("Africa's Talking: " . ($status ?? mb_substr($raw, 0, 200)));
}

/* ---------- Online payments ---------- */

/** The payment provider to use, or null. The test gateway only counts with debug on. */
function payment_provider(): ?array
{
    foreach (providers('payment') as $p) {
        if ($p['provider'] !== 'simulator' || config('debug', false)) {
            return $p;
        }
    }
    return null;
}

/** Start paying a customer invoice online; returns the checkout address to send the payer to. */
function payment_start(array $invoice, array $customer, array $payer): string
{
    $p = payment_provider() ?? fail('Online payment is not available.');
    $settings = farm_settings();
    empty($settings['online_payments']['enabled']) && fail('This farm does not take online payments yet.');
    $invoice['status'] === 'issued' || fail('This invoice has nothing to pay.');
    $amount = round((float) $invoice['amount'] - (float) $invoice['paid_amount'], 2);
    $amount > 0 || fail('This invoice has nothing to pay.');
    $farm = current_farm();
    $ref = 'SFMP-' . strtoupper(bin2hex(random_bytes(8)));
    $id = uuid();
    insert('online_payments', ['id' => $id, 'reference' => $ref, 'provider_id' => $p['id'], 'provider' => $p['provider'], 'purpose' => 'customer_invoice', 'subject_id' => $invoice['id'],
        'subject_code' => $invoice['code'], 'farm_id' => $farm['id'], 'amount' => $amount, 'currency' => $farm['currency'], 'description' => "Invoice {$invoice['code']} from {$farm['name']}",
        'status' => 'pending', 'return_path' => 'customer-invoice.php?id=' . $invoice['id'], 'created_by' => $payer['id'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $return = rtrim((string) config('app_url'), '/') . '/pay-return.php';
    if ($p['provider'] === 'simulator') {
        $url = url('pay-simulator.php', ['ref' => $ref]);
    } else {
        $c = provider_config($p);
        $payload = ['tx_ref' => $ref, 'amount' => $amount, 'currency' => $farm['currency'], 'redirect_url' => $return,
            'customer' => ['email' => $payer['email'], 'name' => $customer['name'], 'phonenumber' => $customer['phone'] ?? $payer['phone'] ?? ''],
            'customizations' => ['title' => $farm['name'], 'description' => "Invoice {$invoice['code']}"], 'meta' => ['invoice' => $invoice['code']]];
        if (!empty($settings['online_payments']['subaccount_id'])) {
            $payload['subaccounts'] = [['id' => $settings['online_payments']['subaccount_id']]];
        }
        try {
            [$code, $json] = http_json('POST', 'https://api.flutterwave.com/v3/payments', ['Authorization: Bearer ' . ($c['secret_key'] ?? ''), 'Content-Type: application/json'], json_encode($payload));
            ($code < 300 && ($json['status'] ?? '') === 'success' && !empty($json['data']['link'])) || throw new RuntimeException($json['message'] ?? "HTTP $code");
            $url = $json['data']['link'];
            provider_result($p, null);
        } catch (Throwable $e) {
            provider_result($p, $e->getMessage());
            q("UPDATE online_payments SET status = 'failed', failure_reason = ?, updated_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 300), now_utc(), $id]);
            fail('The payment service could not start the payment. Try again later.');
        }
    }
    q('UPDATE online_payments SET checkout_url = ?, updated_at = ? WHERE id = ?', [$url, now_utc(), $id]);
    return $url;
}

/**
 * Check a payment with the provider and, when it succeeded for the full
 * amount, record it on the invoice (Dr 1010 / Cr 1200). Safe to call more
 * than once: a payment is recorded once. Returns the payment's status
 * (pending, succeeded, failed, cancelled, or unknown).
 */
function payment_settle(string $reference, ?string $providerTxId): string
{
    return tx(function () use ($reference, $providerTxId) {
        $op = row('SELECT * FROM online_payments WHERE reference = ? FOR UPDATE', [$reference]);
        if (!$op) {
            return 'unknown';
        }
        if ($op['status'] !== 'pending') {
            return $op['status'];
        }
        $p = row('SELECT * FROM integration_providers WHERE id = ?', [$op['provider_id']]);
        [$ok, $txId, $paid, $why] = [false, $providerTxId, 0.0, 'Not paid.'];
        if ($op['provider'] === 'simulator') {
            $ok = config('debug', false) && $op['provider_tx_id'] !== null;
            $txId = $op['provider_tx_id'];
            $paid = (float) $op['amount'];
        } elseif ($op['provider'] === 'flutterwave' && $providerTxId && ctype_digit($providerTxId)) {
            $c = provider_config($p);
            [$code, $json] = http_json('GET', "https://api.flutterwave.com/v3/transactions/$providerTxId/verify", ['Authorization: Bearer ' . ($c['secret_key'] ?? '')], null);
            $d = $json['data'] ?? [];
            $ok = $code < 300 && ($d['status'] ?? '') === 'successful' && ($d['tx_ref'] ?? '') === $op['reference']
                && strtoupper($d['currency'] ?? '') === $op['currency'] && (float) ($d['amount'] ?? 0) + 0.004 >= (float) $op['amount'];
            $paid = (float) ($d['amount'] ?? 0);
            $why = $d['processor_response'] ?? ($json['message'] ?? 'The payment was not completed.');
        }
        if (!$ok) {
            return 'pending';
        }
        $farm = row('SELECT * FROM farms WHERE id = ?', [$op['farm_id']]);
        $farm['is_owner'] = 0;
        act_in_farm($farm);
        $inv = row('SELECT * FROM customer_invoices WHERE id = ? AND farm_id = ? FOR UPDATE', [$op['subject_id'], $farm['id']]);
        $amount = min((float) $op['amount'], round((float) $inv['amount'] - (float) $inv['paid_amount'], 2));
        if ($inv['status'] === 'issued' && $amount > 0) {
            ensure_chart();
            $pid = uuid();
            $entry = ledger_post(farm_today(), 'payment', $pid, "Online payment for {$inv['code']} ($reference)", [['account_id' => account_id('1010'), 'debit' => $amount], ['account_id' => account_id('1200'), 'credit' => $amount]]);
            insert('payments', ['id' => $pid, 'farm_id' => $farm['id'], 'code' => next_code('payments', 'PMT', 4), 'direction' => 'in', 'status' => 'posted', 'payable_type' => 'customer_invoice',
                'payable_id' => $inv['id'], 'payable_code' => $inv['code'], 'party' => val('SELECT name FROM customers WHERE id = ?', [$inv['customer_id']]), 'amount' => $amount,
                'paid_on' => farm_today(), 'method' => 'mobile_money', 'account_id' => account_id('1010'), 'reference' => $reference, 'ledger_entry_id' => $entry,
                'recorded_by' => $op['created_by'], 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            $total = (float) $inv['paid_amount'] + $amount;
            q('UPDATE customer_invoices SET paid_amount = ?, status = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$total, $total + 0.004 >= (float) $inv['amount'] ? 'paid' : 'issued', now_utc(), $inv['id']]);
            audit('sales.invoice.paid_online', $farm['id'], ['type' => 'customer_invoice', 'id' => $inv['id']], null, ['amount' => $amount, 'reference' => $reference], $op['created_by']);
            notify_holders(['finance.manage'], 'online_payment', "Online payment for {$inv['code']}", money($amount) . " received ($reference).", url('invoice.php', ['id' => $inv['id']]));
        }
        q("UPDATE online_payments SET status = 'succeeded', provider_tx_id = ?, paid_amount = ?, verified_at = ?, fulfilled_at = ?, updated_at = ? WHERE id = ?",
            [$txId, $paid, now_utc(), now_utc(), now_utc(), $op['id']]);
        act_in_farm(null);
        return 'succeeded';
    });
}
