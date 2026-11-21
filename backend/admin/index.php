<?php
/**
 * DayFX admin panel: /admin/
 * Sign in with a DayFX account whose email is in config.php 'admin_emails' (or role = 'admin').
 */
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';

session_name('dayfx_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => ($_SERVER['HTTPS'] ?? '') === 'on']);
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$P = preg_replace('/[^a-z_]/', '', $_GET['p'] ?? 'dashboard') ?: 'dashboard';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function url(array $q = []): string { return '?' . http_build_query($q); }
function go(array $q, string $flash = ''): never
{
    if ($flash) $_SESSION['flash'] = $flash;
    header('Location: ' . url($q));
    exit;
}
function admin(): ?array
{
    if (empty($_SESSION['admin_id'])) return null;
    $u = user_by_id((int)$_SESSION['admin_id']);
    return $u && is_admin($u) && $u['status'] === 'active' ? $u : null;
}
function q1(string $sql, array $a = []) { $st = db()->prepare($sql); $st->execute($a); return $st->fetchColumn(); }
function qa(string $sql, array $a = []): array { $st = db()->prepare($sql); $st->execute($a); return $st->fetchAll(); }
function d($v, string $f = 'j M Y, H:i'): string { return $v ? gmdate($f, strtotime($v . ' UTC')) : '—'; }

// ------------------------------------------------------------------ login / logout
if ($P === 'logout') { session_destroy(); header('Location: ./'); exit; }
$me = admin();
if (!$me) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $key = 'adm_login_' . sha1($_SERVER['REMOTE_ADDR'] ?? '');
        $rl = kv_get($key, ['n' => 0, 't' => time()]);
        if (time() - $rl['t'] > 900) $rl = ['n' => 0, 't' => time()];
        if (++$rl['n'] > 10) { $err = 'Too many attempts. Wait 15 minutes.'; }
        else {
            kv_set($key, $rl);
            $st = db()->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([strtolower(trim($_POST['email'] ?? ''))]);
            $u = $st->fetch();
            if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash']) && is_admin($u) && $u['status'] === 'active') {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$u['id'];
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
                header('Location: ./');
                exit;
            }
            sleep(1);
            $err = 'Wrong email or password, or this account is not an admin.';
        }
    }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>DayFX Admin</title><link rel="stylesheet" href="admin.css?v=1"></head><body class="login">
<form method="post" class="card loginbox"><div class="brand"><img src="../app/icon.png" alt="">DayFX Admin</div>
<?= $err ? "<div class=err>{$h($err)}</div>" : '' ?>
<label><span>Email</span><input name="email" type="email" required autofocus></label>
<label><span>Password</span><input name="password" type="password" required></label>
<button class="btn block">Sign in</button>
<p class="mute small">Use your DayFX account. Its email must be listed in <code>admin_emails</code> in config.php.</p></form></body></html><?php
    exit;
}

// ------------------------------------------------------------------ actions (POST, CSRF-protected)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) go(['p' => $P], 'Session expired, try again.');
    $a = $_POST['a'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    switch ($a) {
        case 'settings':
            $in = $_POST;
            foreach (['price_monthly', 'price_yearly', 'min_payout'] as $k) if (isset($in[$k])) $in[$k] = (int)round((float)$in[$k] * 100);
            foreach (['premium_enabled', 'affiliate_enabled', 'commission_recurring'] as $k) $in[$k] = !empty($in[$k]);
            $in['commission_pct'] = max(0, min(90, (float)($in['commission_pct'] ?? 30)));
            if (!in_array($in['lock_scope'] ?? '', ['all', 'elite_high', 'elite'], true)) $in['lock_scope'] = 'all';
            settings_save($in);
            go(['p' => 'settings'], 'Settings saved');
        case 'premium':
            $days = (int)($_POST['days'] ?? 0);
            if ($days > 0) extend_premium($id, $days);
            elseif ($days < 0) db()->prepare('UPDATE users SET premium_until = DATE_SUB(premium_until, INTERVAL ? DAY) WHERE id = ? AND premium_until IS NOT NULL')->execute([-$days, $id]);
            else db()->prepare('UPDATE users SET premium_until = NULL WHERE id = ?')->execute([$id]);
            go(['p' => 'user', 'id' => $id], 'Premium updated');
        case 'ban':
            if ($id === (int)$me['id']) go(['p' => 'user', 'id' => $id], "You can't ban yourself");
            db()->prepare("UPDATE users SET status = IF(status = 'banned', 'active', 'banned') WHERE id = ?")->execute([$id]);
            db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$id]);
            go(['p' => 'user', 'id' => $id], 'Status changed');
        case 'role':
            if ($id === (int)$me['id']) go(['p' => 'user', 'id' => $id], "You can't change your own role");
            db()->prepare("UPDATE users SET role = IF(role = 'admin', 'user', 'admin') WHERE id = ?")->execute([$id]);
            go(['p' => 'user', 'id' => $id], 'Role changed');
        case 'referrer':
            $ref = referrer_by_code($_POST['code'] ?? '');
            if (($_POST['code'] ?? '') !== '' && (!$ref || (int)$ref['id'] === $id)) go(['p' => 'user', 'id' => $id], 'Referral code not found');
            db()->prepare('UPDATE users SET referred_by = ? WHERE id = ?')->execute([$ref['id'] ?? null, $id]);
            go(['p' => 'user', 'id' => $id], 'Referrer updated');
        case 'delete_user':
            if ($id === (int)$me['id']) go(['p' => 'user', 'id' => $id], "You can't delete yourself");
            db()->prepare('UPDATE users SET referred_by = NULL WHERE referred_by = ?')->execute([$id]);
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            go(['p' => 'users'], 'User deleted');
        case 'refund':
            refund_payment($id, !empty($_POST['revoke']));
            go(['p' => 'payments'], 'Marked as refunded; commission voided');
        case 'manual_payment':
            $u = user_by_id((int)$_POST['user_id']);
            $plan = plan_by_id($_POST['plan'] ?? '');
            if (!$u || !$plan) go(['p' => 'payments'], 'Pick a user and plan');
            $amt = (int)round((float)($_POST['amount'] ?? 0) * 100);
            $ref = 'MAN-' . $u['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));
            db()->prepare("INSERT INTO payments (user_id, reference, plan, days, amount, currency, status, channel, gateway_response, paid_at)
                           VALUES (?, ?, ?, ?, ?, ?, 'success', 'manual', ?, UTC_TIMESTAMP())")
                ->execute([$u['id'], $ref, $plan['id'], $plan['days'], $amt, setting('currency'), mb_substr('Recorded by ' . $me['email'] . ': ' . ($_POST['note'] ?? ''), 0, 250)]);
            $p = qa('SELECT * FROM payments WHERE reference = ?', [$ref])[0];
            extend_premium((int)$u['id'], $plan['days']);
            if ($amt > 0) record_commission($p);
            go(['p' => 'payments'], 'Manual payment recorded and Premium added');
        case 'verify_payment':
            $ref = (string)q1('SELECT reference FROM payments WHERE id = ?', [$id]);
            if ($ref && paystack_ready()) paystack_verify($ref);
            go(['p' => 'payments'], 'Checked with Paystack');
        case 'void_commission':
            db()->prepare("UPDATE commissions SET status = IF(status = 'void', 'earned', 'void') WHERE id = ?")->execute([$id]);
            go(['p' => 'affiliates', 'view' => 'commissions'], 'Commission updated');
        case 'payout':
            $st = $_POST['status'] ?? '';
            if (in_array($st, ['paid', 'rejected'], true)) {
                db()->prepare("UPDATE payouts SET status = ?, admin_note = ?, processed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'requested'")
                    ->execute([$st, mb_substr((string)($_POST['note'] ?? ''), 0, 250), $id]);
                $po = qa('SELECT p.*, u.email, u.name FROM payouts p JOIN users u ON u.id = p.affiliate_id WHERE p.id = ?', [$id])[0] ?? null;
                if ($po) Notifier::sendMail($po['email'], $st === 'paid' ? 'Your DayFX payout has been sent' : 'About your DayFX payout request',
                    Notifier::emailHtml($st === 'paid' ? 'Payout sent ✅' : 'Payout request declined', array_filter([
                        'Amount' => money((int)$po['amount'], $po['currency']), 'Note' => $po['admin_note'] ?: null])));
            }
            go(['p' => 'payouts'], 'Payout updated');
        case 'signal_new':
            $pair = strtoupper(trim($_POST['pair'] ?? ''));
            $dir = $_POST['direction'] === 'SELL' ? 'SELL' : 'BUY';
            $nums = array_map(fn($k) => (float)($_POST[$k] ?? 0), ['entry' => 'entry', 'sl' => 'sl', 'tp1' => 'tp1', 'tp2' => 'tp2']);
            $okDir = $dir === 'BUY' ? ($nums['sl'] < $nums['entry'] && $nums['tp1'] > $nums['entry'] && $nums['tp2'] >= $nums['tp1'])
                                    : ($nums['sl'] > $nums['entry'] && $nums['tp1'] < $nums['entry'] && $nums['tp2'] <= $nums['tp1']);
            if (!preg_match('#^[A-Z]{3}/[A-Z]{3}$#', $pair) || !$okDir) go(['p' => 'signals'], 'Check the pair and levels (SL/TP on the wrong side?)');
            $score = max(0, min(100, (int)($_POST['score'] ?? 80)));
            $tier = $score >= (int)cfg('elite_score') ? 'ELITE' : ($score >= (int)cfg('high_score') ? 'HIGH' : 'STANDARD');
            $reasons = array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['reasons'] ?? '')))));
            if (!$reasons) $reasons = ['Analyst call'];
            $now = now_utc();
            $eod = gmdate('Y-m-d') . sprintf(' %02d:00:00', (int)cfg('eod_close_utc'));
            if (strtotime($eod . ' UTC') <= time()) $eod = gmdate('Y-m-d H:i:s', strtotime($eod . ' UTC') + 86400);
            db()->prepare("INSERT INTO signals (pair, direction, strategy, tier, score, entry, sl, tp1, tp2, reasons, created_at, closes_at)
                           VALUES (?, ?, 'manual', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$pair, $dir, $tier, $score, $nums['entry'], $nums['sl'], $nums['tp1'], $nums['tp2'], json_encode($reasons), $now, $eod]);
            $sid = (int)db()->lastInsertId();
            if (!empty($_POST['notify'])) {
                $s = qa('SELECT * FROM signals WHERE id = ?', [$sid])[0];
                $s['reasons'] = $reasons;
                Notifier::newSignal($s);
            }
            go(['p' => 'signals'], "Signal #$sid published. The cron tracker will settle it like any other signal.");
        case 'signal_close':
            $pips = (float)($_POST['pips'] ?? 0);
            $status = $_POST['status'] ?? 'CLOSED_EOD';
            if (!in_array($status, ['WIN', 'LOSS', 'BREAKEVEN', 'CLOSED_EOD'], true)) $status = 'CLOSED_EOD';
            db()->prepare("UPDATE signals SET status = ?, result_pips = ?, closed_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('ACTIVE','TP1')")
                ->execute([$status, $pips, $id]);
            go(['p' => 'signals'], 'Signal closed');
        case 'signal_delete':
            db()->prepare('DELETE FROM signals WHERE id = ?')->execute([$id]);
            go(['p' => 'signals'], 'Signal deleted (it no longer counts in the track record)');
        case 'broadcast':
            $subject = trim((string)($_POST['subject'] ?? ''));
            $msg = trim((string)($_POST['message'] ?? ''));
            if ($subject === '' || $msg === '') go(['p' => 'broadcast'], 'Subject and message are required');
            $where = match ($_POST['to'] ?? 'all') {
                'premium' => 'premium_until > UTC_TIMESTAMP()',
                'free' => '(premium_until IS NULL OR premium_until <= UTC_TIMESTAMP())',
                'affiliates' => 'id IN (SELECT DISTINCT referred_by FROM users WHERE referred_by IS NOT NULL)',
                'test' => 'id = ' . (int)$me['id'],
                default => '1=1',
            };
            $emails = db()->query("SELECT email FROM users WHERE status = 'active' AND $where")->fetchAll(PDO::FETCH_COLUMN);
            $html = Notifier::emailHtml($subject, []);
            $html = str_replace('<table style="width:100%;border-collapse:collapse"></table>',
                '<div style="color:#C9D1DE;font-size:15px;line-height:1.6">' . nl2br(htmlspecialchars($msg)) . '</div>', $html);
            foreach (array_chunk($emails, 40) as $chunk) Notifier::sendMail(cfg('mail_from'), $subject, $html, implode(',', $chunk));
            log_engine('info', 'Broadcast "' . mb_substr($subject, 0, 80) . '" sent to ' . count($emails) . ' users by ' . $me['email']);
            go(['p' => 'broadcast'], 'Sent to ' . count($emails) . ' user(s)');
    }
    go(['p' => $P]);
}

// ------------------------------------------------------------------ view helpers
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$form = fn(string $a, int $id = 0, string $extra = '') => '<form method="post" class="inl">' .
    '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="a" value="' . $a . '">' .
    ($id ? '<input type="hidden" name="id" value="' . $id . '">' : '') . $extra;
$cur = setting('currency');
$pill = fn(string $t, string $c) => "<span class=\"pill p-$c\">" . htmlspecialchars($t) . '</span>';
$statusPill = fn(string $s) => [
    'ACTIVE' => $pill('Running', 'blue'), 'TP1' => $pill('TP1', 'green'), 'WIN' => $pill('Win', 'green'), 'LOSS' => $pill('Loss', 'red'),
    'BREAKEVEN' => $pill('BE', 'gray'), 'CLOSED_EOD' => $pill('EOD', 'gray'),
    'pending' => $pill('Pending', 'gold'), 'success' => $pill('Paid', 'green'), 'failed' => $pill('Failed', 'red'), 'refunded' => $pill('Refunded', 'gray'),
    'requested' => $pill('Requested', 'blue'), 'paid' => $pill('Paid', 'green'), 'rejected' => $pill('Rejected', 'red'),
    'earned' => $pill('Earned', 'green'), 'void' => $pill('Void', 'gray'),
][$s] ?? $pill($s, 'gray');
function pager(int $total, int $per, int $page, array $q): string
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return '';
    $o = '<div class="pager">';
    if ($page > 1) $o .= '<a class="btn ghost sm" href="' . url($q + ['pg' => $page - 1]) . '">← Prev</a>';
    $o .= "<span class=mute>Page $page of $pages · $total total</span>";
    if ($page < $pages) $o .= '<a class="btn ghost sm" href="' . url($q + ['pg' => $page + 1]) . '">Next →</a>';
    return $o . '</div>';
}
$pg = max(1, (int)($_GET['pg'] ?? 1));
$openPayouts = (int)q1("SELECT COUNT(*) FROM payouts WHERE status = 'requested'");
$NAV = [
    'dashboard' => 'Dashboard', 'users' => 'Users', 'signals' => 'Signals', 'payments' => 'Payments',
    'affiliates' => 'Affiliates', 'payouts' => 'Payouts' . ($openPayouts ? " <b class=badge>$openPayouts</b>" : ''),
    'broadcast' => 'Email users', 'settings' => 'Settings', 'logs' => 'Engine log',
];
ob_start();

// ------------------------------------------------------------------ pages
if ($P === 'dashboard') {
    $hb = kv_get('engine_heartbeat');
    $hbOk = $hb && time() - (int)$hb < 1800;
    $k = [
        'users' => q1('SELECT COUNT(*) FROM users'),
        'today' => q1('SELECT COUNT(*) FROM users WHERE created_at >= UTC_DATE()'),
        'week' => q1('SELECT COUNT(*) FROM users WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY'),
        'web' => q1("SELECT COUNT(*) FROM users WHERE signup_source = 'web'"),
        'active7' => q1('SELECT COUNT(*) FROM users WHERE last_seen >= UTC_TIMESTAMP() - INTERVAL 7 DAY'),
        'premium' => q1('SELECT COUNT(*) FROM users WHERE premium_until > UTC_TIMESTAMP()'),
        'rev_month' => (int)q1("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND paid_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')"),
        'rev_all' => (int)q1("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success'"),
        'comm_owed' => (int)q1("SELECT COALESCE(SUM(GREATEST(e.amt - COALESCE(p.amt, 0), 0)), 0)
            FROM (SELECT affiliate_id, SUM(amount) amt FROM commissions WHERE status = 'earned' GROUP BY affiliate_id) e
            LEFT JOIN (SELECT affiliate_id, SUM(amount) amt FROM payouts WHERE status = 'paid' GROUP BY affiliate_id) p ON p.affiliate_id = e.affiliate_id"),
        'referred' => q1('SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL'),
    ];
    $wk = qa("SELECT status, result_pips FROM signals WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY AND status NOT IN ('ACTIVE','TP1')");
    $w = $l = 0; $pips = 0.0;
    foreach ($wk as $r) { $pips += (float)$r['result_pips']; if ($r['status'] === 'BREAKEVEN') continue; ((float)$r['result_pips'] > 0) ? $w++ : $l++; }
    $wr = $w + $l ? round($w / ($w + $l) * 100, 1) . '%' : '—';
    $live = q1("SELECT COUNT(*) FROM signals WHERE status IN ('ACTIVE','TP1')");
    $signups = qa("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= UTC_DATE() - INTERVAL 13 DAY GROUP BY d");
    $sm = array_column($signups, 'n', 'd'); $max = max(1, ...array_values(array_map('intval', $sm ?: [1])));
    ?>
    <div class="head"><h1>Dashboard</h1><span class="pill p-<?= $hbOk ? 'green' : 'red' ?>">Engine <?= $hb ? 'ran ' . $h(gmdate('H:i', (int)$hb)) . ' UTC' : 'never ran' ?></span></div>
    <?php if (!paystack_ready()): ?><div class="note">Paystack isn't set up yet, so Premium checkout stays hidden. Add <code>paystack_secret_key</code> to config.php, then switch Premium on in <a href="?p=settings">Settings</a>.</div><?php elseif (!premium_on()): ?><div class="note">Paystack is ready. Premium is switched off; turn it on in <a href="?p=settings">Settings</a> when you're ready to charge.</div><?php endif ?>
    <div class="grid g4">
      <div class="card kpi"><div class="l">Users</div><div class="v"><?= number_format((int)$k['users']) ?></div><div class="s">+<?= $k['today'] ?> today · +<?= $k['week'] ?> this week</div></div>
      <div class="card kpi"><div class="l">Active (7 days)</div><div class="v"><?= number_format((int)$k['active7']) ?></div><div class="s"><?= $k['web'] ?> signed up on the web</div></div>
      <div class="card kpi"><div class="l">Premium members</div><div class="v gold"><?= number_format((int)$k['premium']) ?></div><div class="s"><?= premium_on() ? 'Premium is ON' : 'Premium is OFF' ?></div></div>
      <div class="card kpi"><div class="l">Revenue this month</div><div class="v g"><?= $h(money($k['rev_month'])) ?></div><div class="s"><?= $h(money($k['rev_all'])) ?> all time</div></div>
      <div class="card kpi"><div class="l">Win rate (7 days)</div><div class="v"><?= $wr ?></div><div class="s"><?= $w ?>W · <?= $l ?>L · <?= sprintf('%+.1f', $pips) ?> pips</div></div>
      <div class="card kpi"><div class="l">Live signals</div><div class="v"><?= $live ?></div><div class="s"><a href="?p=signals">Manage signals</a></div></div>
      <div class="card kpi"><div class="l">Referred users</div><div class="v"><?= number_format((int)$k['referred']) ?></div><div class="s"><a href="?p=affiliates">Affiliate leaderboard</a></div></div>
      <div class="card kpi"><div class="l">Commission owed</div><div class="v"><?= $h(money($k['comm_owed'])) ?></div><div class="s"><?= $openPayouts ? "<a href='?p=payouts'>$openPayouts payout request(s)</a>" : 'no open requests' ?></div></div>
    </div>
    <div class="grid g2" style="margin-top:14px">
      <div class="card"><h2>Sign-ups · last 14 days</h2><div class="bars"><?php for ($i = 13; $i >= 0; $i--): $dd = gmdate('Y-m-d', time() - $i * 86400); $n = (int)($sm[$dd] ?? 0); ?>
        <div title="<?= $dd ?>: <?= $n ?>"><i style="height:<?= max(2, $n / $max * 100) ?>%"></i><small><?= gmdate('j', strtotime($dd)) ?></small></div><?php endfor ?></div></div>
      <div class="card"><h2>Latest payments</h2><table><?php foreach (qa('SELECT p.*, u.name FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.status = "success" ORDER BY p.paid_at DESC LIMIT 6') as $p): ?>
        <tr><td><?= $h($p['name'] ?? 'Deleted') ?></td><td><?= $h(ucfirst($p['plan'])) ?></td><td class="r-al g"><?= $h(money((int)$p['amount'], $p['currency'])) ?></td><td class="mute r-al"><?= d($p['paid_at'], 'j M') ?></td></tr>
      <?php endforeach ?></table><?= q1('SELECT COUNT(*) FROM payments WHERE status = "success"') ? '' : '<p class="mute">No payments yet.</p>' ?></div>
    </div>
    <div class="card" style="margin-top:14px"><h2>Recent signals</h2><div class="tscroll"><table><tr><th>#</th><th>Pair</th><th>Strategy</th><th>Score</th><th>Status</th><th class="r-al">Pips</th><th class="r-al">Time</th></tr>
    <?php foreach (qa('SELECT * FROM signals ORDER BY id DESC LIMIT 8') as $s): ?>
      <tr><td class="mute"><?= $s['id'] ?></td><td><b><?= $h($s['pair']) ?></b> <span class="<?= $s['direction'] === 'BUY' ? 'g' : 'r' ?>"><?= $s['direction'] ?></span></td><td><?= $h(Notifier::strategyName($s['strategy'])) ?></td><td><?= $s['score'] ?> <span class="mute"><?= $s['tier'] ?></span></td><td><?= $statusPill($s['status']) ?></td><td class="r-al"><?= $s['result_pips'] === null ? '—' : sprintf('%+.1f', $s['result_pips']) ?></td><td class="r-al mute"><?= d($s['created_at'], 'j M H:i') ?></td></tr>
    <?php endforeach ?></table></div></div>
    <?php
}

elseif ($P === 'users') {
    $qs = trim((string)($_GET['q'] ?? ''));
    $f = $_GET['f'] ?? '';
    $where = ['1=1']; $args = [];
    if ($qs !== '') { $where[] = '(u.email LIKE ? OR u.name LIKE ? OR u.ref_code = ?)'; array_push($args, "%$qs%", "%$qs%", strtoupper($qs)); }
    if ($f === 'premium') $where[] = 'u.premium_until > UTC_TIMESTAMP()';
    if ($f === 'referred') $where[] = 'u.referred_by IS NOT NULL';
    if ($f === 'banned') $where[] = "u.status = 'banned'";
    if ($f === 'web') $where[] = "u.signup_source = 'web'";
    if ($f === 'admin') $where[] = "u.role = 'admin'";
    $W = implode(' AND ', $where);
    $total = (int)q1("SELECT COUNT(*) FROM users u WHERE $W", $args);
    $rows = qa("SELECT u.*, r.name ref_name, (SELECT COUNT(*) FROM users x WHERE x.referred_by = u.id) refs
                FROM users u LEFT JOIN users r ON r.id = u.referred_by WHERE $W ORDER BY u.id DESC LIMIT 50 OFFSET " . (($pg - 1) * 50), $args);
    ?>
    <div class="head"><h1>Users</h1></div>
    <form class="filters" method="get"><input type="hidden" name="p" value="users"><input name="q" placeholder="Search name, email or referral code" value="<?= $h($qs) ?>">
    <select name="f"><?php foreach (['' => 'All users', 'premium' => 'Premium', 'referred' => 'Referred', 'web' => 'Signed up on web', 'banned' => 'Banned', 'admin' => 'Admins'] as $k2 => $v): ?><option value="<?= $k2 ?>" <?= $f === $k2 ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select><button class="btn sm">Filter</button></form>
    <div class="card tscroll"><table><tr><th>User</th><th>Plan</th><th>Referred by</th><th>Referrals</th><th>Source</th><th>Joined</th><th>Last seen</th></tr>
    <?php foreach ($rows as $u): ?>
      <tr><td><a href="?p=user&id=<?= $u['id'] ?>"><b><?= $h($u['name']) ?></b></a><br><small class="mute"><?= $h($u['email']) ?></small> <?= $u['status'] === 'banned' ? $pill('Banned', 'red') : '' ?><?= $u['role'] === 'admin' ? $pill('Admin', 'purple') : '' ?></td>
      <td><?= is_premium($u) ? $pill('Premium', 'gold') . '<br><small class=mute>to ' . d($u['premium_until'], 'j M Y') . '</small>' : '<span class=mute>Free</span>' ?></td>
      <td><?= $u['ref_name'] ? $h($u['ref_name']) : '<span class=mute>—</span>' ?></td><td><?= (int)$u['refs'] ?: '<span class=mute>0</span>' ?></td>
      <td><?= $h($u['signup_source']) ?></td><td class="mute"><?= d($u['created_at'], 'j M Y') ?></td><td class="mute"><?= d($u['last_seen'], 'j M H:i') ?></td></tr>
    <?php endforeach ?></table><?= $rows ? '' : '<p class="mute">No users match.</p>' ?></div>
    <?= pager($total, 50, $pg, ['p' => 'users', 'q' => $qs, 'f' => $f]) ?>
    <?php
}

elseif ($P === 'user') {
    $id = (int)($_GET['id'] ?? 0);
    $u = user_by_id($id);
    if (!$u) { echo '<div class="err">User not found</div>'; }
    else {
        ensure_ref_code($u);
        $ref = $u['referred_by'] ? user_by_id((int)$u['referred_by']) : null;
        $bal = affiliate_balance($id); $cnt = affiliate_counts($id);
        $pd = json_decode((string)$u['payout_details'], true);
        ?>
        <div class="head"><div><a href="?p=users" class="mute">← Users</a><h1><?= $h($u['name']) ?></h1><p class="mute"><?= $h($u['email']) ?> · joined <?= d($u['created_at']) ?> via <?= $h($u['signup_source']) ?></p></div>
        <div><?= is_premium($u) ? $pill('Premium', 'gold') : $pill('Free', 'gray') ?> <?= $u['status'] === 'banned' ? $pill('Banned', 'red') : '' ?> <?= is_admin($u) ? $pill('Admin', 'purple') : '' ?></div></div>
        <div class="grid g2">
          <div class="card"><h2>Premium</h2>
            <p class="mute"><?= is_premium($u) ? 'Active until <b style="color:var(--text)">' . d($u['premium_until']) . ' UTC</b>' : ($u['premium_until'] ? 'Expired ' . d($u['premium_until']) : 'Never had Premium') ?></p>
            <div class="row wrap" style="margin-top:12px">
              <?php foreach ([7 => '+7 days', 30 => '+30 days', 365 => '+1 year', -30 => '−30 days', 0 => 'Remove'] as $dd => $lbl): ?>
                <?= $form('premium', $id, '<input type="hidden" name="days" value="' . $dd . '">') ?><button class="btn <?= $dd > 0 ? '' : 'ghost' ?> sm"><?= $lbl ?></button></form>
              <?php endforeach ?></div>
            <p class="mute small" style="margin-top:10px">Use this for gifts or payments made outside Paystack. To record money received, use <a href="?p=payments">Payments → Record manual payment</a> so the referrer gets commission.</p></div>
          <div class="card"><h2>Account</h2>
            <div class="kv"><span>Alerts</span><b>Email <?= $u['email_alerts'] ? 'on' : 'off' ?> · Push <?= $u['push_alerts'] ? 'on' : 'off' ?> · Elite only <?= $u['elite_only'] ? 'yes' : 'no' ?></b></div>
            <div class="kv"><span>Last seen</span><b><?= d($u['last_seen']) ?></b></div>
            <div class="kv"><span>Referred by</span><b><?= $ref ? '<a href="?p=user&id=' . $ref['id'] . '">' . $h($ref['name']) . '</a> (' . $h($ref['ref_code']) . ')' : '—' ?></b></div>
            <?= $form('referrer', $id) ?><div class="row" style="margin-top:8px"><input name="code" placeholder="Set referrer code (blank = none)" style="flex:1"><button class="btn ghost sm">Save</button></div></form>
            <div class="row wrap" style="margin-top:14px">
              <?= $form('ban', $id) ?><button class="btn ghost sm"><?= $u['status'] === 'banned' ? 'Unban' : 'Ban user' ?></button></form>
              <?= $form('role', $id) ?><button class="btn ghost sm"><?= $u['role'] === 'admin' ? 'Remove admin' : 'Make admin' ?></button></form>
              <?= $form('delete_user', $id) ?><button class="btn danger sm" onclick="return confirm('Delete this user and all their data?')">Delete</button></form>
            </div></div>
        </div>
        <div class="card" style="margin-top:14px"><h2>Affiliate · code <?= $h($u['ref_code']) ?></h2>
          <div class="grid g4 mini"><div><small class="mute">Clicks</small><b><?= $cnt['clicks'] ?></b></div><div><small class="mute">Sign-ups</small><b><?= $cnt['signups'] ?></b></div><div><small class="mute">Paying</small><b><?= $cnt['paying'] ?></b></div><div><small class="mute">Earned</small><b><?= $h(money($bal['earned'])) ?></b></div>
          <div><small class="mute">Available</small><b class="<?= $bal['available'] < 0 ? 'r' : 'g' ?>"><?= $h(money($bal['available'])) ?></b></div><div><small class="mute">Pending</small><b class="gold"><?= $h(money($bal['pending'])) ?></b></div><div><small class="mute">Requested</small><b><?= $h(money($bal['requested'])) ?></b></div><div><small class="mute">Paid out</small><b><?= $h(money($bal['paid'])) ?></b></div></div>
          <p class="mute small" style="margin-top:10px">Link: <?= $h(ref_link($u['ref_code'])) ?><?= $pd ? ' · Bank: ' . $h($pd['bank_name'] . ' · ' . $pd['account_number'] . ' · ' . $pd['account_name']) : '' ?></p></div>
        <div class="card" style="margin-top:14px"><h2>Payments</h2><div class="tscroll"><table><tr><th>Date</th><th>Reference</th><th>Plan</th><th>Amount</th><th>Status</th></tr>
        <?php foreach (qa('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$id]) as $p): ?>
          <tr><td class="mute"><?= d($p['paid_at'] ?: $p['created_at'], 'j M Y') ?></td><td><code><?= $h($p['reference']) ?></code></td><td><?= $h($p['plan']) ?></td><td><?= $h(money((int)$p['amount'], $p['currency'])) ?></td><td><?= $statusPill($p['status']) ?></td></tr>
        <?php endforeach ?></table></div></div>
        <div class="card" style="margin-top:14px"><h2>People they referred</h2><div class="tscroll"><table><tr><th>Name</th><th>Plan</th><th>Joined</th></tr>
        <?php foreach (qa('SELECT * FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 100', [$id]) as $x): ?>
          <tr><td><a href="?p=user&id=<?= $x['id'] ?>"><?= $h($x['name']) ?></a> <small class="mute"><?= $h($x['email']) ?></small></td><td><?= is_premium($x) ? $pill('Premium', 'gold') : '<span class=mute>Free</span>' ?></td><td class="mute"><?= d($x['created_at'], 'j M Y') ?></td></tr>
        <?php endforeach ?></table></div></div>
        <?php
    }
}

elseif ($P === 'signals') {
    $f = $_GET['f'] ?? '';
    $W = match ($f) { 'live' => "status IN ('ACTIVE','TP1')", 'closed' => "status NOT IN ('ACTIVE','TP1')", 'manual' => "strategy = 'manual'", default => '1=1' };
    $total = (int)q1("SELECT COUNT(*) FROM signals WHERE $W");
    $rows = qa("SELECT * FROM signals WHERE $W ORDER BY id DESC LIMIT 40 OFFSET " . (($pg - 1) * 40));
    ?>
    <div class="head"><h1>Signals</h1><a class="btn sm" href="#new" onclick="document.getElementById('new').open=true">+ Post a signal</a></div>
    <details class="card" id="new" style="margin-bottom:14px"><summary><b>Post a manual signal (analyst call)</b> <span class="mute">· tracked and settled automatically by the cron, like engine signals</span></summary>
      <?= $form('signal_new') ?><div class="grid g4" style="margin-top:14px">
        <label><span>Pair</span><select name="pair"><?php foreach (array_unique(array_merge(cfg('pairs') ?: [], ['EUR/USD', 'GBP/USD', 'USD/JPY', 'XAU/USD'])) as $pp): ?><option><?= $h($pp) ?></option><?php endforeach ?></select></label>
        <label><span>Direction</span><select name="direction"><option>BUY</option><option>SELL</option></select></label>
        <label><span>Confidence score (0–100)</span><input name="score" type="number" min="0" max="100" value="80"></label>
        <label><span>Entry</span><input name="entry" type="number" step="any" required></label>
        <label><span>Stop loss</span><input name="sl" type="number" step="any" required></label>
        <label><span>Take profit 1</span><input name="tp1" type="number" step="any" required></label>
        <label><span>Take profit 2</span><input name="tp2" type="number" step="any" required></label>
        <label class="chk"><input type="checkbox" name="notify" value="1" checked> Email subscribers now</label></div>
      <label><span>Reasons (one per line)</span><textarea name="reasons" rows="3" placeholder="H1 uptrend&#10;Retest of London high&#10;Bullish engulfing"></textarea></label>
      <p class="mute small">Only pairs in config.php <code>pairs</code> get tracked by the cron. The app's alerts pick it up on their next check.</p>
      <button class="btn">Publish signal</button></form></details>
    <div class="filters"><?php foreach (['' => 'All', 'live' => 'Live', 'closed' => 'Closed', 'manual' => 'Manual'] as $k2 => $v): ?><a class="btn <?= $f === $k2 ? '' : 'ghost' ?> sm" href="?p=signals&f=<?= $k2 ?>"><?= $v ?></a><?php endforeach ?></div>
    <div class="card tscroll"><table><tr><th>#</th><th>Signal</th><th>Levels</th><th>Score</th><th>Status</th><th class="r-al">Pips</th><th>Time (UTC)</th><th></th></tr>
    <?php foreach ($rows as $s): $dp = price_decimals($s['pair']); $fmt = fn($v) => number_format((float)$v, $dp, '.', ''); ?>
      <tr><td class="mute"><?= $s['id'] ?></td><td><b><?= $h($s['pair']) ?></b> <span class="<?= $s['direction'] === 'BUY' ? 'g' : 'r' ?>"><?= $s['direction'] ?></span><br><small class="mute"><?= $h(Notifier::strategyName($s['strategy'])) ?></small></td>
      <td class="small">E <?= $fmt($s['entry']) ?> · SL <span class="r"><?= $fmt($s['sl']) ?></span><br>TP <span class="g"><?= $fmt($s['tp1']) ?> / <?= $fmt($s['tp2']) ?></span></td>
      <td><?= $s['score'] ?> <small class="mute"><?= $s['tier'] ?></small></td><td><?= $statusPill($s['status']) ?></td>
      <td class="r-al"><?= $s['result_pips'] === null ? '—' : sprintf('%+.1f', $s['result_pips']) ?></td><td class="mute small"><?= d($s['created_at'], 'j M H:i') ?></td>
      <td class="r-al nowrap"><?php if (in_array($s['status'], ['ACTIVE', 'TP1'], true)): ?>
        <?= $form('signal_close', (int)$s['id']) ?><select name="status" class="sm"><option value="CLOSED_EOD">Close</option><option value="WIN">Win</option><option value="LOSS">Loss</option><option value="BREAKEVEN">BE</option></select><input name="pips" type="number" step="0.1" placeholder="pips" class="sm" style="width:70px"><button class="btn ghost sm">Set</button></form>
      <?php endif ?><?= $form('signal_delete', (int)$s['id']) ?><button class="btn danger sm" onclick="return confirm('Delete signal #<?= $s['id'] ?>? It will disappear from the track record.')">✕</button></form></td></tr>
    <?php endforeach ?></table><?= $rows ? '' : '<p class="mute">No signals yet.</p>' ?></div>
    <?= pager($total, 40, $pg, ['p' => 'signals', 'f' => $f]) ?>
    <?php
}

elseif ($P === 'payments') {
    $f = $_GET['f'] ?? 'success';
    $W = in_array($f, ['success', 'pending', 'failed', 'refunded'], true) ? 'p.status = ' . db()->quote($f) : '1=1';
    $total = (int)q1("SELECT COUNT(*) FROM payments p WHERE $W");
    $rows = qa("SELECT p.*, u.name, u.email, c.amount comm, c.status cstatus, a.name aff FROM payments p LEFT JOIN users u ON u.id = p.user_id
                LEFT JOIN commissions c ON c.payment_id = p.id LEFT JOIN users a ON a.id = c.affiliate_id WHERE $W ORDER BY p.id DESC LIMIT 50 OFFSET " . (($pg - 1) * 50));
    $sum = fn($w) => money((int)q1("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'success' AND $w"));
    ?>
    <div class="head"><h1>Payments</h1></div>
    <div class="grid g4"><div class="card kpi"><div class="l">Today</div><div class="v g"><?= $h($sum('paid_at >= UTC_DATE()')) ?></div></div>
    <div class="card kpi"><div class="l">Last 7 days</div><div class="v g"><?= $h($sum('paid_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY')) ?></div></div>
    <div class="card kpi"><div class="l">This month</div><div class="v g"><?= $h($sum("paid_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')")) ?></div></div>
    <div class="card kpi"><div class="l">All time</div><div class="v g"><?= $h($sum('1=1')) ?></div></div></div>
    <details class="card" style="margin:14px 0"><summary><b>Record a manual payment</b> <span class="mute">· bank transfer or cash; adds Premium and pays the referrer's commission</span></summary>
      <?= $form('manual_payment') ?><div class="grid g4" style="margin-top:14px">
      <label><span>User ID</span><input name="user_id" type="number" required placeholder="from the Users page"></label>
      <label><span>Plan</span><select name="plan"><?php foreach (plans() as $pl): ?><option value="<?= $pl['id'] ?>"><?= $pl['name'] ?> (<?= $pl['days'] ?> days)</option><?php endforeach ?></select></label>
      <label><span>Amount received (<?= $h($cur) ?>)</span><input name="amount" type="number" step="any" required></label>
      <label><span>Note</span><input name="note" placeholder="e.g. GTB transfer 21 Nov"></label></div><button class="btn">Record payment</button></form></details>
    <div class="filters"><?php foreach (['success' => 'Paid', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded', 'all' => 'All'] as $k2 => $v): ?><a class="btn <?= $f === $k2 ? '' : 'ghost' ?> sm" href="?p=payments&f=<?= $k2 ?>"><?= $v ?></a><?php endforeach ?></div>
    <div class="card tscroll"><table><tr><th>Date</th><th>User</th><th>Plan</th><th>Amount</th><th>Status</th><th>Commission</th><th>Reference</th><th></th></tr>
    <?php foreach ($rows as $p): ?>
      <tr><td class="mute small"><?= d($p['paid_at'] ?: $p['created_at'], 'j M Y H:i') ?></td><td><?= $p['user_id'] ? '<a href="?p=user&id=' . $p['user_id'] . '">' . $h($p['name']) . '</a>' : '<span class=mute>Deleted</span>' ?></td>
      <td><?= $h($p['plan']) ?></td><td><b><?= $h(money((int)$p['amount'], $p['currency'])) ?></b><br><small class="mute"><?= $h($p['channel'] ?? '') ?></small></td><td><?= $statusPill($p['status']) ?></td>
      <td><?= $p['comm'] !== null ? $h(money((int)$p['comm'], $p['currency'])) . ' → ' . $h($p['aff']) . ($p['cstatus'] === 'void' ? ' ' . $pill('void', 'gray') : '') : '<span class=mute>—</span>' ?></td>
      <td><code class="small"><?= $h($p['reference']) ?></code></td>
      <td class="nowrap r-al"><?php if ($p['status'] === 'success'): ?><?= $form('refund', (int)$p['id']) ?><label class="chk small"><input type="checkbox" name="revoke" value="1" checked> remove Premium</label> <button class="btn danger sm" onclick="return confirm('Mark as refunded? Refund the money in Paystack separately.')">Refunded</button></form>
      <?php elseif ($p['status'] === 'pending'): ?><?= $form('verify_payment', (int)$p['id']) ?><button class="btn ghost sm">Check Paystack</button></form><?php endif ?></td></tr>
    <?php endforeach ?></table><?= $rows ? '' : '<p class="mute">Nothing here.</p>' ?></div>
    <?= pager($total, 50, $pg, ['p' => 'payments', 'f' => $f]) ?>
    <?php
}

elseif ($P === 'affiliates') {
    $view = $_GET['view'] ?? 'leaders';
    ?>
    <div class="head"><h1>Affiliates</h1><div class="filters" style="margin:0"><a class="btn <?= $view === 'leaders' ? '' : 'ghost' ?> sm" href="?p=affiliates">Leaderboard</a><a class="btn <?= $view === 'commissions' ? '' : 'ghost' ?> sm" href="?p=affiliates&view=commissions">Commissions</a></div></div>
    <p class="mute" style="margin-bottom:14px">Affiliates earn <b style="color:var(--text)"><?= (float)setting('commission_pct') ?>%</b> of <?= setting('commission_recurring') ? 'every' : 'the first' ?> Premium payment from people they refer. Commissions clear after <?= (int)setting('hold_days') ?> days. Minimum payout <?= $h(money((int)setting('min_payout'))) ?>. <a href="?p=settings">Change</a></p>
    <?php if ($view === 'commissions'):
        $rows = qa('SELECT c.*, a.name aff, u.name buyer, p.reference FROM commissions c JOIN users a ON a.id = c.affiliate_id LEFT JOIN users u ON u.id = c.referred_user_id JOIN payments p ON p.id = c.payment_id ORDER BY c.id DESC LIMIT 100'); ?>
      <div class="card tscroll"><table><tr><th>Date</th><th>Affiliate</th><th>From</th><th>Amount</th><th>Rate</th><th>Clears</th><th>Status</th><th></th></tr>
      <?php foreach ($rows as $c): ?><tr><td class="mute small"><?= d($c['created_at'], 'j M Y') ?></td><td><a href="?p=user&id=<?= $c['affiliate_id'] ?>"><?= $h($c['aff']) ?></a></td><td><?= $h($c['buyer'] ?? 'Deleted') ?> <small class="mute"><?= $h($c['reference']) ?></small></td>
        <td><b><?= $h(money((int)$c['amount'], $c['currency'])) ?></b></td><td><?= (float)$c['rate'] ?>%</td><td class="mute small"><?= d($c['available_at'], 'j M') ?></td><td><?= $statusPill($c['status']) ?></td>
        <td class="r-al"><?= $form('void_commission', (int)$c['id']) ?><button class="btn ghost sm"><?= $c['status'] === 'void' ? 'Restore' : 'Void' ?></button></form></td></tr><?php endforeach ?>
      </table><?= $rows ? '' : '<p class="mute">No commissions yet.</p>' ?></div>
    <?php else:
        $rows = qa("SELECT a.id, a.name, a.email, a.ref_code,
              (SELECT COUNT(*) FROM referral_clicks k WHERE k.referrer_id = a.id) clicks,
              COUNT(DISTINCT r.id) signups,
              COUNT(DISTINCT CASE WHEN r.premium_until > UTC_TIMESTAMP() THEN r.id END) premium,
              (SELECT COALESCE(SUM(amount),0) FROM commissions c WHERE c.affiliate_id = a.id AND c.status = 'earned') earned,
              (SELECT COALESCE(SUM(amount),0) FROM payouts o WHERE o.affiliate_id = a.id AND o.status = 'paid') paid
            FROM users a JOIN users r ON r.referred_by = a.id GROUP BY a.id ORDER BY earned DESC, signups DESC LIMIT 100"); ?>
      <div class="card tscroll"><table><tr><th>#</th><th>Affiliate</th><th>Code</th><th class="r-al">Clicks</th><th class="r-al">Sign-ups</th><th class="r-al">Conv.</th><th class="r-al">Premium now</th><th class="r-al">Earned</th><th class="r-al">Paid out</th></tr>
      <?php foreach ($rows as $i => $r): ?><tr><td class="mute"><?= $i + 1 ?></td><td><a href="?p=user&id=<?= $r['id'] ?>"><b><?= $h($r['name']) ?></b></a><br><small class="mute"><?= $h($r['email']) ?></small></td><td><code><?= $h($r['ref_code']) ?></code></td>
        <td class="r-al"><?= $r['clicks'] ?></td><td class="r-al"><?= $r['signups'] ?></td><td class="r-al mute"><?= $r['clicks'] ? round($r['signups'] / $r['clicks'] * 100) . '%' : '—' ?></td><td class="r-al"><?= $r['premium'] ?></td>
        <td class="r-al g"><?= $h(money((int)$r['earned'])) ?></td><td class="r-al"><?= $h(money((int)$r['paid'])) ?></td></tr><?php endforeach ?>
      </table><?= $rows ? '' : '<p class="mute">No referred users yet. Every user gets a referral link under Earn in the web app.</p>' ?></div>
    <?php endif;
}

elseif ($P === 'payouts') {
    $f = $_GET['f'] ?? 'requested';
    $W = in_array($f, ['requested', 'paid', 'rejected'], true) ? 'o.status = ' . db()->quote($f) : '1=1';
    $rows = qa("SELECT o.*, u.name, u.email FROM payouts o JOIN users u ON u.id = o.affiliate_id WHERE $W ORDER BY o.id DESC LIMIT 100");
    ?>
    <div class="head"><h1>Payouts</h1></div>
    <div class="filters"><?php foreach (['requested' => 'Requested', 'paid' => 'Paid', 'rejected' => 'Rejected', 'all' => 'All'] as $k2 => $v): ?><a class="btn <?= $f === $k2 ? '' : 'ghost' ?> sm" href="?p=payouts&f=<?= $k2 ?>"><?= $v ?></a><?php endforeach ?></div>
    <p class="mute small" style="margin-bottom:10px">Send the money from your bank app first, then mark it paid here with the transfer reference. The affiliate gets an email.</p>
    <div class="card tscroll"><table><tr><th>Requested</th><th>Affiliate</th><th>Amount</th><th>Bank details</th><th>Balance now</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $o): $bd = json_decode((string)$o['details'], true) ?: []; $bal = affiliate_balance((int)$o['affiliate_id']); ?>
      <tr><td class="mute small"><?= d($o['created_at']) ?></td><td><a href="?p=user&id=<?= $o['affiliate_id'] ?>"><?= $h($o['name']) ?></a><br><small class="mute"><?= $h($o['email']) ?></small></td>
      <td><b><?= $h(money((int)$o['amount'], $o['currency'])) ?></b></td>
      <td class="small"><?= $h($bd['bank_name'] ?? '') ?><br><b><?= $h($bd['account_number'] ?? '') ?></b><br><?= $h($bd['account_name'] ?? '') ?></td>
      <td class="small"><?= $bal['available'] < 0 ? '<span class="r">' . $h(money($bal['available'])) . ' (owes)</span>' : $h(money($bal['available'])) . ' free' ?></td>
      <td><?= $statusPill($o['status']) ?><?= $o['admin_note'] ? '<br><small class=mute>' . $h($o['admin_note']) . '</small>' : '' ?></td>
      <td class="nowrap"><?php if ($o['status'] === 'requested'): ?><?= $form('payout', (int)$o['id']) ?><input name="note" placeholder="Transfer ref / reason" class="sm" style="width:150px"> <button class="btn sm" name="status" value="paid">Mark paid</button> <button class="btn danger sm" name="status" value="rejected">Reject</button></form><?php endif ?></td></tr>
    <?php endforeach ?></table><?= $rows ? '' : '<p class="mute">Nothing here.</p>' ?></div>
    <?php
}

elseif ($P === 'broadcast') {
    $counts = ['all' => q1("SELECT COUNT(*) FROM users WHERE status = 'active'"), 'premium' => q1("SELECT COUNT(*) FROM users WHERE status = 'active' AND premium_until > UTC_TIMESTAMP()")];
    ?>
    <div class="head"><h1>Email users</h1></div>
    <div class="card" style="max-width:720px"><?= $form('broadcast') ?>
      <label><span>Send to</span><select name="to"><option value="test">Just me (test)</option><option value="all">All active users (<?= $counts['all'] ?>)</option><option value="free">Free users</option><option value="premium">Premium members (<?= $counts['premium'] ?>)</option><option value="affiliates">Affiliates with referrals</option></select></label>
      <label><span>Subject</span><input name="subject" required maxlength="150"></label>
      <label><span>Message</span><textarea name="message" rows="9" required placeholder="Plain text. Line breaks are kept."></textarea></label>
      <p class="mute small">Sent through your cPanel mailbox (<?= $h(cfg('mail_from')) ?>) in batches of 40 using BCC. Most shared hosts limit emails per hour; for big lists, send in parts or use a mailing service.</p>
      <button class="btn" onclick="return confirm('Send this email now?')">Send</button></form></div>
    <?php
}

elseif ($P === 'settings') {
    $s = settings_all();
    $mv = fn($k) => number_format($s[$k] / 100, 2, '.', '');
    ?>
    <div class="head"><h1>Settings</h1></div>
    <?= $form('settings') ?>
    <div class="grid g2">
      <div class="card"><h2>Premium</h2>
        <label class="chk"><input type="checkbox" name="premium_enabled" value="1" <?= $s['premium_enabled'] ? 'checked' : '' ?>> <b>Premium is on</b> (locks live levels for free users)</label>
        <?php if (!paystack_ready()): ?><div class="note small">Add <code>paystack_secret_key</code> to config.php first. Until then checkout stays hidden even if this is on.</div><?php endif ?>
        <div class="grid g2">
          <label><span>Currency</span><select name="currency"><?php foreach (['NGN', 'USD', 'GHS', 'KES', 'ZAR'] as $c): ?><option <?= $s['currency'] === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach ?></select></label>
          <label><span>&nbsp;</span><small class="mute">Your Paystack account must support the currency.</small></label>
          <label><span>Monthly price (30 days)</span><input name="price_monthly" type="number" step="0.01" min="0" value="<?= $mv('price_monthly') ?>"></label>
          <label><span>Yearly price (365 days)</span><input name="price_yearly" type="number" step="0.01" min="0" value="<?= $mv('price_yearly') ?>"></label>
        </div>
        <label><span>Which live signals need Premium?</span><select name="lock_scope">
          <?php foreach (['all' => 'All live signals', 'elite_high' => 'Elite and High (Standard stays free)', 'elite' => 'Only Elite (85+)'] as $k2 => $v): ?><option value="<?= $k2 ?>" <?= $s['lock_scope'] === $k2 ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select></label>
        <label><span>Show levels to free users after (minutes, 0 = only when the trade closes)</span><input name="free_delay_minutes" type="number" min="0" value="<?= (int)$s['free_delay_minutes'] ?>"></label>
        <p class="mute small">History, results, news and tools always stay free. In the Android app, locked signals say "Premium" with no payment link (Google Play rules); users upgrade on the web.</p></div>
      <div class="card"><h2>Affiliates</h2>
        <label class="chk"><input type="checkbox" name="affiliate_enabled" value="1" <?= $s['affiliate_enabled'] ? 'checked' : '' ?>> <b>Affiliate programme is on</b></label>
        <div class="grid g2">
          <label><span>Commission %</span><input name="commission_pct" type="number" step="0.5" min="0" max="90" value="<?= (float)$s['commission_pct'] ?>"></label>
          <label><span>Holding period (days)</span><input name="hold_days" type="number" min="0" max="90" value="<?= (int)$s['hold_days'] ?>"></label>
          <label><span>Minimum payout (<?= $h($s['currency']) ?>)</span><input name="min_payout" type="number" step="0.01" min="0" value="<?= $mv('min_payout') ?>"></label>
        </div>
        <label class="chk"><input type="checkbox" name="commission_recurring" value="1" <?= $s['commission_recurring'] ? 'checked' : '' ?>> Pay commission on renewals too (not just the first payment)</label>
        <p class="mute small">Changing the % only affects new payments. Refunded payments void their commission automatically.</p>
        <h2 style="margin-top:22px">General</h2>
        <label><span>Site name</span><input name="site_name" value="<?= $h($s['site_name']) ?>"></label>
        <label><span>Announcement banner (web app, blank = none)</span><input name="announcement" value="<?= $h($s['announcement']) ?>" placeholder="e.g. 🎉 Premium launch: 20% off yearly this week"></label></div>
    </div>
    <button class="btn" style="margin-top:14px">Save settings</button></form>
    <div class="card" style="margin-top:14px"><h2>Setup checklist</h2>
      <div class="kv"><span>Paystack secret key</span><b><?= paystack_ready() ? '<span class=g>Set (' . (str_starts_with((string)cfg('paystack_secret_key'), 'sk_test') ? 'TEST mode' : 'live') . ')</span>' : '<span class=r>Missing</span>' ?></b></div>
      <div class="kv"><span>Paystack webhook URL</span><b><code><?= $h(base_url()) ?>/api/paystack/webhook</code></b></div>
      <div class="kv"><span>Base URL (referral links)</span><b><code><?= $h(base_url()) ?></code></b></div>
      <div class="kv"><span>Engine heartbeat</span><b><?= ($hb = kv_get('engine_heartbeat')) ? gmdate('j M H:i', (int)$hb) . ' UTC' : '<span class=r>never</span>' ?></b></div></div>
    <?php
}

elseif ($P === 'logs') {
    ?>
    <div class="head"><h1>Engine log</h1></div>
    <div class="card tscroll"><table><tr><th>Time (UTC)</th><th>Level</th><th>Message</th></tr>
    <?php foreach (qa('SELECT * FROM engine_log ORDER BY id DESC LIMIT 200') as $l): ?><tr><td class="mute small nowrap"><?= d($l['created_at'], 'j M H:i:s') ?></td><td><?= $l['level'] === 'error' ? $pill('error', 'red') : $pill($l['level'], 'gray') ?></td><td class="small"><?= $h($l['message']) ?></td></tr><?php endforeach ?>
    </table></div>
    <?php
}
else { echo '<div class="err">Page not found</div>'; }

$body = ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= $h(strip_tags(explode(' <', $NAV[$P] ?? 'Admin')[0])) ?> · DayFX Admin</title><link rel="stylesheet" href="admin.css?v=1"></head><body>
<div class="shell"><aside class="side"><div class="brand"><img src="../app/icon.png" alt="">DayFX <small>admin</small></div>
<nav><?php foreach ($NAV as $k2 => $v): ?><a href="?p=<?= $k2 ?>" class="<?= $P === $k2 || ($P === 'user' && $k2 === 'users') ? 'on' : '' ?>"><?= $v ?></a><?php endforeach ?></nav>
<div class="grow"></div><a href="../app/" class="mute small">Open web app ↗</a><div class="mute small" style="margin-top:8px"><?= $h($me['email']) ?> · <a href="?p=logout">Sign out</a></div></aside>
<main class="main"><?= $flash ? '<div class="flash">' . $h($flash) . '</div>' : '' ?><?= $body ?></main></div>
<script>document.querySelector('.side .brand').onclick=()=>document.body.classList.toggle('navopen')</script>
</body></html>
