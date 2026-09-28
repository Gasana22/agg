<?php
/* Farm profile and rules: approval limit, two-step sign-in for everyone, stock and planting rules. */
require __DIR__ . '/inc/bootstrap.php';

$farm = require_farm('farm.settings.manage');
$fid = $farm['id'];

if (is_post()) {
    handle(function () use ($fid) {
        $name = input('name', 255) ?? fail('The farm needs a name.');
        $size = input_num('size_ha');
        q('UPDATE farms SET name = ?, district = ?, village = ?, size_ha = ?, updated_at = ? WHERE id = ?', [$name, input('district', 120), input('village', 120), $size, now_utc(), $fid]);
        $s = farm_settings();
        $threshold = input_num('expense_threshold');
        $s['approval_thresholds']['expense'] = $threshold !== null && $threshold > 0 ? $threshold : null;
        $s['require_mfa_for_all'] = (bool) input('require_mfa_for_all');
        $s['allow_negative_stock'] = (bool) input('allow_negative_stock');
        $s['allow_intercropping'] = (bool) input('allow_intercropping');
        q('UPDATE farm_settings SET settings = ?, updated_at = ? WHERE farm_id = ?', [json_encode($s), now_utc(), $fid]);
        audit('farm.settings.updated', null, ['type' => 'farm', 'id' => $fid], null, $s);
        flash('success', 'Settings saved.');
    }, 'settings.php');
}

$s = farm_settings();
$check = fn ($k) => !empty($s[$k]) ? ' checked' : '';
page_start('Farm settings');
?>
<form method="post" class="card"><?= csrf_field() ?>
<h2>Profile</h2>
<div class="fields">
<?= field('Farm name', '<input name="name" required value="' . e($farm['name']) . '">') ?>
<?= field('District', '<input name="district" value="' . e($farm['district']) . '">') ?>
<?= field('Village', '<input name="village" value="' . e($farm['village']) . '">') ?>
<?= field('Size (hectares)', '<input name="size_ha" inputmode="decimal" value="' . e($farm['size_ha'] !== null ? (float) $farm['size_ha'] : '') . '">') ?>
<?= field('Currency', '<input value="' . e($farm['currency']) . '" disabled>') ?>
<?= field('Time zone', '<input value="' . e($farm['timezone']) . '" disabled>') ?>
</div>
<h2 style="margin-top:1rem">Rules</h2>
<div class="fields">
<?= field('Expenses above this need the owner\'s approval', '<input name="expense_threshold" inputmode="decimal" value="' . e($s['approval_thresholds']['expense'] ?? '') . '">', 'Empty: no approval needed.') ?>
</div>
<div class="stack" style="margin-top:.75rem">
<label class="row"><input type="checkbox" style="width:auto" name="require_mfa_for_all" value="1"<?= $check('require_mfa_for_all') ?>> Everyone on this farm must use two-step sign-in</label>
<label class="row"><input type="checkbox" style="width:auto" name="allow_negative_stock" value="1"<?= $check('allow_negative_stock') ?>> Allow issuing stock that is not recorded yet (negative stock)</label>
<label class="row"><input type="checkbox" style="width:auto" name="allow_intercropping" value="1"<?= $check('allow_intercropping') ?>> Allow more than one crop on a plot at a time</label>
</div>
<div class="actions"><button class="primary">Save</button></div>
</form>
<?php page_end();
