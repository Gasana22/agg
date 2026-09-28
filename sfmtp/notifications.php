<?php
/* The member's inbox on this farm. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm();
$user = current_user();

if (is_post()) {
    q('UPDATE member_notifications SET read_at = ? WHERE farm_id = ? AND user_id = ? AND read_at IS NULL', [now_utc(), $farm['id'], $user['id']]);
    redirect('notifications.php');
}

page_start('Notifications');
$list = rows('SELECT * FROM member_notifications WHERE farm_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 100', [$farm['id'], $user['id']]);
if ($list) {
    echo '<p>' . post_button('Mark all as read', ['all' => 1]) . '</p>';
}
table($list, [
    'When' => fn ($n) => e(fdate($n['created_at'], true)),
    'Notice' => fn ($n) => ($n['read_at'] ? '' : '<b>') . e($n['title']) . ($n['read_at'] ? '' : '</b>') . ($n['body'] ? '<div class="muted">' . e($n['body']) . '</div>' : ''),
    'Kind' => fn ($n) => e(label($n['kind'])),
], 'No notifications.');
page_end();
