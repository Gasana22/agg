<?php
/* My farms: switch farm, or create a new one. */
require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if (is_post()) {
    if (($target = input_id('farm_id')) !== null) {
        if (in_array($target, array_column(my_farms(), 'id'), true)) {
            $_SESSION['farm_id'] = $target;
        }
        redirect('dashboard.php');
    }
    handle(function () use ($user) {
        if ($user['user_type'] !== 'member') {
            fail('Only farm member accounts can create farms.');
        }
        $name = input('name', 255) ?? fail('Give the farm a name.');
        $size = input_num('size_ha');
        if ($size !== null && ($size <= 0 || $size > 1000000)) {
            fail('The size must be in hectares, above zero.');
        }
        $id = create_farm($user, ['name' => $name, 'district' => input('district', 120), 'village' => input('village', 120), 'size_ha' => $size,
            'organization_name' => input('organization_name', 255)]);
        $_SESSION['farm_id'] = $id;
        flash('success', 'Farm created. The platform team will approve it; you can set it up meanwhile.');
    }, 'dashboard.php');
}

page_start('My farms');
$farms = my_farms();
echo '<div class="grid">';
foreach ($farms as $f) {
    echo '<div class="card"><h2>' . e($f['name']) . '</h2><p class="muted">' . e($f['code']) . ' · ' . e($f['district'] ?: '') . ' ' . badge($f['status'])
        . ($f['is_owner'] ? ' <span class="badge">Owner</span>' : '') . '</p>' . post_button('Open', ['farm_id' => $f['id']], 'primary') . '</div>';
}
echo '</div>';
if (!$farms) {
    echo '<p class="muted">You are not a member of any farm yet. Create one, or ask a farm owner to invite you.</p>';
}
if ($user['user_type'] === 'member') {
    form_start('Create a farm', '', !$farms);
    echo '<div class="fields">'
        . field('Farm name', '<input name="name" required maxlength="255">')
        . field('District', '<input name="district" maxlength="120">')
        . field('Village', '<input name="village" maxlength="120">')
        . field('Size (hectares)', '<input name="size_ha" inputmode="decimal">')
        . field('Business name', '<input name="organization_name" maxlength="255">', 'Your organization, if you have one. Used on invoices.')
        . '</div>';
    form_end('Create farm');
}
page_end();
