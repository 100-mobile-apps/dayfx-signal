<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
session_start();

if (isset($_POST['user'], $_POST['pass'])) {
    if ($_POST['user'] === cfg('admin_user') && cfg('admin_pass_hash') && password_verify($_POST['pass'], cfg('admin_pass_hash'))) {
        session_regenerate_id(true);
        $_SESSION['dayfx_admin'] = true;
    } else {
        sleep(2);
        $err = 'Wrong login';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: admin.php'); exit; }
$h = fn($s) => htmlspecialchars((string)$s);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DayFX Admin</title>
<style>
body{background:#0B0F17;color:#E8ECF4;font:14px system-ui,Arial;margin:0;padding:16px}
.card{background:#121826;border:1px solid #1F2738;border-radius:12px;padding:16px;margin:0 auto 16px;max-width:1000px;overflow-x:auto}
table{width:100%;border-collapse:collapse}td,th{padding:6px 8px;border-bottom:1px solid #1F2738;text-align:left;white-space:nowrap}
th{color:#8A94A6;font-weight:500}.g{color:#22C55E}.r{color:#EF4444}.m{color:#8A94A6}
input,button{padding:10px;border-radius:8px;border:1px solid #1F2738;background:#0B0F17;color:#E8ECF4;margin:4px 0;width:100%}
button{background:#22C55E;color:#0B0F17;font-weight:700;border:0}.k{display:inline-block;margin-right:24px}
.k b{font-size:22px;display:block}
</style></head><body>
<?php if (empty($_SESSION['dayfx_admin'])): ?>
<div class="card" style="max-width:340px"><h3>DayFX Admin</h3><?= isset($err) ? "<p class=r>{$h($err)}</p>" : '' ?>
<form method="post"><input name="user" placeholder="User"><input name="pass" type="password" placeholder="Password"><button>Sign in</button></form></div>
<?php exit; endif;

$hb = kv_get('engine_heartbeat');
$users = db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$users7 = db()->query("SELECT COUNT(*) FROM users WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
$agg = db()->query("SELECT
  SUM(status IN ('WIN') OR (status='CLOSED_EOD' AND result_pips>0)) w,
  SUM(status='LOSS' OR (status='CLOSED_EOD' AND result_pips<=0)) l,
  SUM(CASE WHEN status NOT IN ('ACTIVE','TP1') THEN result_pips ELSE 0 END) p,
  SUM(tier='ELITE' AND (status='WIN' OR (status='CLOSED_EOD' AND result_pips>0))) ew,
  SUM(tier='ELITE' AND (status='LOSS' OR (status='CLOSED_EOD' AND result_pips<=0))) el
  FROM signals WHERE created_at > NOW() - INTERVAL 30 DAY")->fetch();
$wr = ($agg['w'] + $agg['l']) ? round($agg['w'] / ($agg['w'] + $agg['l']) * 100, 1) . '%' : '–';
$ewr = ($agg['ew'] + $agg['el']) ? round($agg['ew'] / ($agg['ew'] + $agg['el']) * 100, 1) . '%' : '–';
?>
<div class="card">
  <span class="k">Engine<b class="<?= $hb && time() - $hb < 1800 ? 'g' : 'r' ?>"><?= $hb ? $h(gmdate('H:i', $hb)) . ' UTC' : 'never' ?></b></span>
  <span class="k">Users<b><?= $h($users) ?> <small class="m">+<?= $h($users7) ?>/7d</small></b></span>
  <span class="k">30d win rate<b><?= $h($wr) ?></b></span>
  <span class="k">30d Elite<b><?= $h($ewr) ?></b></span>
  <span class="k">30d pips<b class="<?= $agg['p'] >= 0 ? 'g' : 'r' ?>"><?= $h(round((float)$agg['p'], 1)) ?></b></span>
  <a href="?logout=1" class="m" style="float:right">Log out</a>
</div>
<div class="card"><h3>Latest signals</h3><table><tr><th>#</th><th>Time (UTC)</th><th>Pair</th><th>Dir</th><th>Strategy</th><th>Tier</th><th>Score</th><th>Status</th><th>Pips</th></tr>
<?php foreach (db()->query('SELECT * FROM signals ORDER BY id DESC LIMIT 50') as $s): ?>
<tr><td><?= $h($s['id']) ?></td><td><?= $h(substr($s['created_at'], 5, 11)) ?></td><td><?= $h($s['pair']) ?></td>
<td class="<?= $s['direction'] === 'BUY' ? 'g' : 'r' ?>"><?= $h($s['direction']) ?></td><td><?= $h($s['strategy']) ?></td>
<td><?= $h($s['tier']) ?></td><td><?= $h($s['score']) ?></td><td><?= $h($s['status']) ?></td>
<td class="<?= (float)$s['result_pips'] >= 0 ? 'g' : 'r' ?>"><?= $h($s['result_pips']) ?></td></tr>
<?php endforeach; ?></table></div>
<div class="card"><h3>Engine log</h3><table>
<?php foreach (db()->query('SELECT * FROM engine_log ORDER BY id DESC LIMIT 40') as $l): ?>
<tr><td class="m"><?= $h($l['created_at']) ?></td><td class="<?= $l['level'] === 'error' ? 'r' : 'm' ?>"><?= $h($l['level']) ?></td><td style="white-space:normal"><?= $h($l['message']) ?></td></tr>
<?php endforeach; ?></table></div>
</body></html>
