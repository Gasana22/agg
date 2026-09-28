<?php
/* The farm's audit trail: who did what, when, from where. Read-only. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('audit.view');
$page = max(1, (int) (input_num('page') ?? 1));
$per = 50;
$list = rows('SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.farm_id = ? ORDER BY a.created_at DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per), [$farm['id']]);
$more = count($list) > $per;
$list = array_slice($list, 0, $per);

page_start('Audit log');
echo '<div class="card">';
table($list, [
    'When' => fn ($a) => e(fdate($a['created_at'], true)),
    'Who' => fn ($a) => e($a['name'] ?? '—'),
    'What' => fn ($a) => '<b>' . e($a['action']) . '</b>' . ($a['entity_type'] ? ' <span class="muted">' . e($a['entity_type']) . '</span>' : ''),
    'Details' => fn ($a) => '<span class="muted">' . e(mb_substr((string) $a['new_values'], 0, 160)) . '</span>',
    'From' => fn ($a) => e($a['ip'] ?? ''),
], 'Nothing recorded yet.');
echo '</div><p class="row">' . ($page > 1 ? '<a class="btn" href="?page=' . ($page - 1) . '">Newer</a>' : '') . ($more ? '<a class="btn" href="?page=' . ($page + 1) . '">Older</a>' : '') . '</p>';
page_end();
