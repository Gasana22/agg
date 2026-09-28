<?php
/*
 * Supplier and customer portals.
 *
 * A farm opens one of its supplier or customer records to the portal by
 * inviting an email address. Accepting links the record to a party (the
 * supplier's or customer's own organisation) and the person to that party.
 * A party can be linked to records on several farms; the portal shows each.
 *
 * Portal pages only ever read through the active links of the signed-in
 * person's parties, and act inside one linked farm at a time
 * (act_in_farm), so nothing of another farm or another supplier or customer
 * is reachable. Unlinking stops access at once and keeps the history.
 */

const PORTAL_KINDS = ['supplier' => 'suppliers', 'customer' => 'customers'];
const PORTAL_INVITE_DAYS = 14;

function portal_manage_permission(string $kind): string
{
    return $kind === 'supplier' ? 'suppliers.manage' : 'customers.manage';
}

/** Farm side: invite an email address to see one supplier or customer record. Returns the one-time token. */
function portal_invite(string $kind, array $record, string $email, ?string $message): string
{
    require_can(portal_manage_permission($kind));
    $record['is_active'] || fail('This record is not active.');
    $email = mb_strtolower(trim($email));
    filter_var($email, FILTER_VALIDATE_EMAIL) || fail('Enter a valid email address.');
    val("SELECT 1 FROM party_links WHERE farm_id = ? AND kind = ? AND record_id = ? AND status = 'active'", [farm_id(), $kind, $record['id']])
        && fail("{$record['name']} already has portal access. Stop it first to invite someone else.");
    $staff = row('SELECT user_type FROM users WHERE email = ?', [$email]);
    if ($staff && $staff['user_type'] === 'platform_admin') {
        fail('Platform staff accounts cannot use the portals.');
    }
    $token = bin2hex(random_bytes(24));
    tx(function () use ($kind, $record, $email, $message, $token) {
        // One open invitation per record: a new one replaces the old link.
        q('UPDATE portal_invitations SET revoked_at = ?, revoked_by = ?, updated_at = ? WHERE farm_id = ? AND kind = ? AND record_id = ? AND accepted_at IS NULL AND revoked_at IS NULL',
            [now_utc(), $_SESSION['uid'], now_utc(), farm_id(), $kind, $record['id']]);
        $id = uuid();
        insert('portal_invitations', ['id' => $id, 'farm_id' => farm_id(), 'kind' => $kind, 'record_id' => $record['id'], 'email' => $email, 'token_hash' => hash('sha256', $token),
            'message' => $message, 'invited_by' => $_SESSION['uid'], 'expires_at' => gmdate('Y-m-d H:i:s', time() + PORTAL_INVITE_DAYS * 86400),
            'created_at' => now_utc(), 'updated_at' => now_utc()]);
        audit('portal.invitation.sent', null, ['type' => 'portal_invitation', 'id' => $id], null, ['kind' => $kind, 'record' => $record['code'], 'email' => $email]);
    });
    return $token;
}

function portal_invite_url(string $token): string
{
    return rtrim((string) config('app_url', ''), '/') . '/invite.php?token=' . $token;
}

function invitation_status(array $inv): string
{
    return match (true) {
        $inv['accepted_at'] !== null => 'accepted',
        $inv['revoked_at'] !== null => 'revoked',
        strtotime($inv['expires_at'] . ' UTC') < time() => 'expired',
        default => 'pending',
    };
}

/** Farm side: stop a party's access to one record. */
function portal_unlink(array $link): void
{
    require_can(portal_manage_permission($link['kind']));
    $link['status'] === 'active' || fail('This portal access is already stopped.');
    tx(function () use ($link) {
        q("UPDATE party_links SET status = 'revoked', revoked_by = ?, revoked_at = ?, updated_at = ? WHERE id = ? AND farm_id = ?", [$_SESSION['uid'], now_utc(), now_utc(), $link['id'], farm_id()]);
        q('UPDATE `' . PORTAL_KINDS[$link['kind']] . '` SET party_id = NULL, updated_at = ? WHERE id = ? AND farm_id = ?', [now_utc(), $link['record_id'], farm_id()]);
        audit('portal.link.revoked', null, ['type' => 'party_link', 'id' => $link['id']], ['status' => 'active'], ['status' => 'revoked', 'kind' => $link['kind']]);
    });
}

/**
 * Accept an invitation for $user (already signed in, or just created).
 * Returns the farm name.
 */
function portal_accept(array $inv, array $user): string
{
    invitation_status($inv) === 'pending' || fail(match (invitation_status($inv)) {
        'accepted' => 'This invitation has already been used.',
        'revoked' => 'This invitation was withdrawn.',
        default => 'This invitation has expired. Ask the farm for a new one.',
    });
    mb_strtolower($user['email']) === $inv['email'] || fail('This invitation was sent to a different email address. Sign in with that address.');
    $user['user_type'] !== 'platform_admin' || fail('Platform staff accounts cannot use the portals.');
    $farm = row("SELECT * FROM farms WHERE id = ? AND status IN ('pending','active')", [$inv['farm_id']]) ?? fail('This farm is not open.');
    return tx(function () use ($inv, $user, $farm) {
        $table = PORTAL_KINDS[$inv['kind']];
        $record = row("SELECT * FROM `$table` WHERE id = ? AND farm_id = ? AND is_active = 1 FOR UPDATE", [$inv['record_id'], $farm['id']]) ?? fail('This invitation is no longer valid.');
        val("SELECT 1 FROM party_links WHERE farm_id = ? AND kind = ? AND record_id = ? AND status = 'active'", [$farm['id'], $inv['kind'], $record['id']])
            && fail("{$record['name']} already has portal access.");
        // The person's party when they have one, else a new party named after the record.
        $partyId = val('SELECT party_id FROM party_users WHERE user_id = ? ORDER BY created_at LIMIT 1', [$user['id']]);
        if (!$partyId) {
            $partyId = uuid();
            insert('parties', ['id' => $partyId, 'name' => $record['name'], 'email' => $inv['email'], 'phone' => $record['phone'], 'address' => $record['address'],
                'tax_id' => $record['tax_id'], 'status' => 'active', 'version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            insert('party_users', ['id' => uuid(), 'party_id' => $partyId, 'user_id' => $user['id'], 'created_at' => now_utc()]);
        }
        $existing = val('SELECT id FROM party_links WHERE farm_id = ? AND kind = ? AND record_id = ?', [$farm['id'], $inv['kind'], $record['id']]);
        if ($existing) {
            q("UPDATE party_links SET party_id = ?, status = 'active', linked_by = ?, linked_at = ?, revoked_by = NULL, revoked_at = NULL, updated_at = ? WHERE id = ?",
                [$partyId, $inv['invited_by'], now_utc(), now_utc(), $existing]);
        } else {
            insert('party_links', ['id' => uuid(), 'party_id' => $partyId, 'farm_id' => $farm['id'], 'kind' => $inv['kind'], 'record_id' => $record['id'], 'status' => 'active',
                'linked_by' => $inv['invited_by'], 'linked_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        }
        q("UPDATE `$table` SET party_id = ?, updated_at = ? WHERE id = ?", [$partyId, now_utc(), $record['id']]);
        q('UPDATE portal_invitations SET accepted_at = ?, accepted_user_id = ?, party_id = ?, updated_at = ? WHERE id = ?', [now_utc(), $user['id'], $partyId, now_utc(), $inv['id']]);
        audit('portal.invitation.accepted', $farm['id'], ['type' => 'portal_invitation', 'id' => $inv['id']], null, ['kind' => $inv['kind'], 'record' => $record['code']], $user['id']);
        return $farm['name'];
    });
}

/* ---------- Portal side ---------- */

/** The signed-in person's active links (optionally of one kind), with the farm and the record's name. */
function portal_links(?string $kind = null): array
{
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return [];
    }
    $links = rows("SELECT pl.*, p.name AS party_name, f.name AS farm_name, f.status AS farm_status FROM party_links pl
        JOIN party_users pu ON pu.party_id = pl.party_id AND pu.user_id = ?
        JOIN parties p ON p.id = pl.party_id AND p.status = 'active'
        JOIN farms f ON f.id = pl.farm_id AND f.status IN ('pending','active')
        WHERE pl.status = 'active'" . ($kind ? ' AND pl.kind = ?' : '') . ' ORDER BY f.name', $kind ? [$uid, $kind] : [$uid]);
    foreach ($links as &$l) {
        $l['record'] = row('SELECT * FROM `' . PORTAL_KINDS[$l['kind']] . '` WHERE id = ? AND farm_id = ?', [$l['record_id'], $l['farm_id']]);
    }
    return array_values(array_filter($links, fn ($l) => $l['record'] && $l['record']['is_active']));
}

/** Work inside the link's farm: codes, ledger and audit then belong to that farm. */
function portal_enter(array $link): void
{
    $farm = row('SELECT * FROM farms WHERE id = ?', [$link['farm_id']]);
    $farm['is_owner'] = 0;
    act_in_farm($farm);
}

/** Portal pages call this: signed in, with at least one active link of the kind. Returns the links. */
function require_portal(string $kind): array
{
    require_login();
    $GLOBALS['sfmtp_portal'] = true;
    $links = portal_links($kind);
    if (!$links) {
        http_response_code(403);
        page_start('Not available');
        echo '<div class="card">You have no ' . e($kind) . ' access to any farm. The farm sends an invitation link to open it.</div>';
        page_end();
        exit;
    }
    return $links;
}

/** The link that covers a record of the kind (an order's supplier_id / customer_id), or a 404 page. */
function portal_link_for(array $links, string $farmId, string $recordId): array
{
    foreach ($links as $l) {
        if ($l['farm_id'] === $farmId && $l['record_id'] === $recordId) {
            return $l;
        }
    }
    http_response_code(404);
    page_start('Not found');
    echo '<div class="card"><h2>Not found</h2><p>This does not exist, or it is not yours to see.</p></div>';
    page_end();
    exit;
}

/** Portal menu. */
function portal_nav(): array
{
    $items = [['portal.php', 'Overview']];
    if (portal_links('supplier')) {
        $items[] = ['supplier.php', 'Purchase orders'];
        $items[] = ['supplier.php?tab=invoices', 'Invoices & payments'];
    }
    if (portal_links('customer')) {
        $items[] = ['shop.php', 'Order products'];
        $items[] = ['customer.php', 'My orders'];
        $items[] = ['customer.php?tab=invoices', 'Invoices'];
        $items[] = ['customer.php?tab=purchases', 'What I bought'];
    }
    $items[] = ['profile.php', 'My account'];
    return $items;
}
