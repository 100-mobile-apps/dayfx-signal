<?php
/**
 * One-time installer: creates (or upgrades) the database tables and checks the setup.
 * Open https://your-subdomain/install.php after filling in config.php. DELETE THIS FILE when it says "All good".
 */
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
$rows = [];
$ok = function (bool $pass, string $label, string $hint = '') use (&$rows) { $rows[] = [$pass, $label, $hint]; return $pass; };
$ok(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP ' . PHP_VERSION, 'Needs PHP 8.1 or newer (cPanel › MultiPHP Manager)');
foreach (['pdo_mysql', 'curl', 'mbstring', 'openssl', 'json'] as $ext) $ok(extension_loaded($ext), "PHP extension $ext", 'Enable it in cPanel › Select PHP Version › Extensions');
$cfgOk = $ok(is_file(__DIR__ . '/config.php'), 'config.php exists');
$done = false;
if ($cfgOk) {
    require __DIR__ . '/lib/bootstrap.php';
    $c = cfg();
    $ok(!str_contains((string)$c['db']['pass'], 'CHANGE_ME') && $c['db']['name'] !== 'cpaneluser_dayfx', 'Database details filled in', 'Edit config.php › db');
    $ok(($c['twelvedata_key'] ?? '') !== 'CHANGE_ME' && ($c['twelvedata_key'] ?? '') !== '', 'Twelve Data key set', 'Free key at twelvedata.com, put it in twelvedata_key');
    $ok(($c['cron_secret'] ?? '') !== 'CHANGE_ME_LONG_RANDOM', 'cron_secret changed', 'Any long random text');
    $ok(!in_array('you@yourdomain.com', (array)($c['admin_emails'] ?? []), true), 'admin_emails set', 'Your email, then sign up with it');
    $ok(true, 'Paystack ' . (paystack_ready() ? 'key set' : 'key not set yet (Premium stays hidden; fine for now)'));
    try {
        $pdo = db();
        $ok(true, 'Connected to MySQL database ' . $c['db']['name']);
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $run = function (string $file) use ($pdo) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/' . $file));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $pdo->exec($stmt);
        };
        if (!in_array('users', $tables, true)) {
            $run('schema.sql');
            $ok(true, 'Created all tables');
        } else {
            $cols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('premium_until', $cols, true)) { $run('schema_upgrade_web.sql'); $ok(true, 'Upgraded existing tables (users and signals kept)'); }
            else $ok(true, 'Tables already installed');
        }
        $n = count($pdo->query('SHOW TABLES')->fetchAll());
        $done = $ok($n >= 11, "$n tables present");
    } catch (Throwable $e) {
        $ok(false, 'Database connection', $e->getMessage());
    }
}
$all = !in_array(false, array_column($rows, 0), true);
$cron = '/usr/local/bin/php ' . __DIR__ . '/cron.php >/dev/null 2>&1';
?><!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>DayFX installer</title>
<style>body{font:15px/1.5 system-ui,Arial;background:#0B0F17;color:#E8ECF4;max-width:760px;margin:30px auto;padding:0 16px}td{padding:7px 10px;border-bottom:1px solid #1F2738;vertical-align:top}.p{color:#22C55E}.f{color:#EF4444}small{color:#8A94A6}code{background:#121826;padding:2px 6px;border-radius:5px;word-break:break-all}</style>
<h2>DayFX installer</h2><table><?php foreach ($rows as [$p, $l, $hnt]): ?><tr><td class="<?= $p ? 'p' : 'f' ?>"><?= $p ? '✔' : '✘' ?></td><td><?= htmlspecialchars($l) ?><?= !$p && $hnt ? '<br><small>' . htmlspecialchars($hnt) . '</small>' : '' ?></td></tr><?php endforeach ?></table>
<?php if ($done): ?><h3>Cron job (cPanel › Cron Jobs, "Once per 15 minutes", minute <code>1,16,31,46</code>)</h3><p><code><?= htmlspecialchars($cron) ?></code></p><?php endif ?>
<p><b><?= $all ? 'All good. Delete install.php now, then sign up at /app/ with your admin email and open /admin/.' : 'Fix the red items and refresh this page.' ?></b></p>
