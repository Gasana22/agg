<?php
/* Portal access: invite a supplier or customer to their portal, see who has access, stop it. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$fid = $farm['id'];
$kinds = array_values(array_filter(['supplier', 'customer'], fn ($k) => can($k === 'supplier' ? 'suppliers.view' : 'customers.view')));
$kinds || require_can('customers.view');
$shownLink = null;

if (is_post()) {
    $action = input('action', 20);
    try {
        if ($action === 'invite') {
            [$kind, $recordId] = array_pad(explode(':', (string) input('who', 80), 2), 2, '');
            in_array($kind, $kinds, true) || fail('Choose who to invite.');
            $record = farm_row(PORTAL_KINDS[$kind], uuid_or_null($recordId));
            $token = portal_invite($kind, $record, (string) input('email', 150), input('message', 500));
            $shownLink = ['url' => portal_invite_url($token), 'email' => mb_strtolower((string) input('email', 150)), 'name' => $record['name']];
        } else {
            handle(function () use ($action, $fid) {
                if ($action === 'revoke_invite') {
                    $inv = row('SELECT * FROM portal_invitations WHERE id = ? AND farm_id = ?', [input_id('invitation_id'), $fid]) ?? fail('Unknown invitation.');
                    require_can(portal_manage_permission($inv['kind']));
                    invitation_status($inv) === 'pending' || fail('This invitation is no longer open.');
                    q('UPDATE portal_invitations SET revoked_at = ?, revoked_by = ?, updated_at = ? WHERE id = ?', [now_utc(), $_SESSION['uid'], now_utc(), $inv['id']]);
                    audit('portal.invitation.revoked', null, ['type' => 'portal_invitation', 'id' => $inv['id']], null, ['email' => $inv['email']]);
                    flash('success', 'Invitation withdrawn: the link no longer works.');
                } elseif ($action === 'unlink') {
                    portal_unlink(row('SELECT * FROM party_links WHERE id = ? AND farm_id = ?', [input_id('link_id'), $fid]) ?? fail('Unknown access.'));
                    flash('success', 'Portal access stopped. The history stays.');
                }
            }, 'portal-access.php');
        }
    } catch (Invalid $e) {
        flash('error', $e->getMessage());
        $_SESSION['old'] = $_POST;
        redirect('portal-access.php');
    }
}

$names = [];
foreach ($kinds as $k) {
    foreach (rows('SELECT id, code, name, is_active FROM `' . PORTAL_KINDS[$k] . '` WHERE farm_id = ? ORDER BY name', [$fid]) as $r) {
        $names[$k][$r['id']] = $r;
    }
}
$in = implode(',', array_fill(0, count($kinds), '?'));
$links = rows("SELECT pl.*, p.name AS party, (SELECT GROUP_CONCAT(u.email) FROM party_users pu JOIN users u ON u.id = pu.user_id WHERE pu.party_id = pl.party_id) AS people
    FROM party_links pl JOIN parties p ON p.id = pl.party_id WHERE pl.farm_id = ? AND pl.kind IN ($in) ORDER BY pl.status = 'active' DESC, pl.linked_at DESC", [$fid, ...$kinds]);
$invites = rows("SELECT i.*, u.name AS inviter FROM portal_invitations i LEFT JOIN users u ON u.id = i.invited_by WHERE i.farm_id = ? AND i.kind IN ($in) ORDER BY i.created_at DESC LIMIT 100", [$fid, ...$kinds]);

page_start('Portal access');
if ($shownLink) {
    echo '<div class="card"><h2>Invitation ready</h2><p>Send this link to <b>' . e($shownLink['email']) . '</b> (by email, SMS or WhatsApp). It opens ' . e($shownLink['name']) . '\'s portal and works once, for '
        . PORTAL_INVITE_DAYS . ' days. It is shown only now.</p><p><input readonly value="' . e($shownLink['url']) . '"></p></div>';
}
echo '<p class="muted">Suppliers see the purchase orders you send them, tell you what they send and submit invoices. Customers order your published products and follow their orders, invoices and deliveries. Neither sees anything else of the farm.</p>';
echo '<div class="card"><h2>Who has access</h2>';
table($links, [
    'Record' => fn ($l) => '<b>' . e($names[$l['kind']][$l['record_id']]['name'] ?? '?') . '</b> <span class="muted">' . e(label($l['kind'])) . '</span>',
    'Organisation' => fn ($l) => e($l['party']) . '<div class="muted">' . e($l['people'] ?? '') . '</div>',
    'Since' => fn ($l) => e(fdate($l['linked_at'])),
    'Status' => fn ($l) => badge($l['status']),
    '' => fn ($l) => $l['status'] === 'active' && can(portal_manage_permission($l['kind'])) ? post_button('Stop access', ['action' => 'unlink', 'link_id' => $l['id']], 'small danger', 'Stop this portal access now?') : '',
], 'No one has portal access yet.');
echo '</div><div class="card"><h2>Invitations</h2>';
table($invites, [
    'Record' => fn ($i) => e($names[$i['kind']][$i['record_id']]['name'] ?? '?') . ' <span class="muted">' . e(label($i['kind'])) . '</span>',
    'Sent to' => fn ($i) => e($i['email']), 'By' => fn ($i) => e($i['inviter'] ?? '—'), 'Sent' => fn ($i) => e(fdate($i['created_at'])), 'Expires' => fn ($i) => e(fdate($i['expires_at'])),
    'Status' => fn ($i) => badge(invitation_status($i)),
    '' => fn ($i) => invitation_status($i) === 'pending' && can(portal_manage_permission($i['kind'])) ? post_button('Withdraw', ['action' => 'revoke_invite', 'invitation_id' => $i['id']], 'small') : '',
], 'No invitations yet.');
echo '</div>';

$manage = array_values(array_filter($kinds, fn ($k) => can(portal_manage_permission($k))));
if ($manage) {
    $preKind = input_in('kind', $manage) ?? $manage[0];
    $preRecord = input_id('record');
    form_start('Invite someone to a portal', '', $preRecord !== null);
    $choices = [];
    foreach ($manage as $k) {
        foreach ($names[$k] ?? [] as $r) {
            if ($r['is_active']) {
                $choices[] = ['value' => $k . ':' . $r['id'], 'text' => label($k) . ' · ' . $r['name']];
            }
        }
    }
    echo '<input type="hidden" name="action" value="invite">'
        . '<div class="fields">' . field('Who', '<select name="who" required>' . options($choices, 'value', 'text', $preRecord ? "$preKind:$preRecord" : null) . '</select>')
        . field('Their email', '<input type="email" name="email" required maxlength="150">') . field('Message (optional)', '<input name="message" maxlength="500">') . '</div>';
    form_end('Create invitation link');
}
page_end();
