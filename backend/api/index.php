<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/Notifier.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function fail(string $msg, int $code = 400): never { out(['error' => $msg], $code); }
function body(): array
{
    $b = json_decode(file_get_contents('php://input') ?: '[]', true);
    return is_array($b) ? $b : [];
}
function auth_user(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m)) fail('Unauthorized', 401);
    $st = db()->prepare('SELECT u.* FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.token_hash = ?');
    $st->execute([hash('sha256', $m[1])]);
    $u = $st->fetch();
    if (!$u) fail('Unauthorized', 401);
    if ($u['status'] === 'banned') fail('This account has been suspended. Contact support.', 403);
    if (!$u['last_seen'] || strtotime($u['last_seen'] . ' UTC') < time() - 300) {
        db()->prepare('UPDATE auth_tokens SET last_used = UTC_TIMESTAMP() WHERE token_hash = ?')->execute([hash('sha256', $m[1])]);
        db()->prepare('UPDATE users SET last_seen = UTC_TIMESTAMP() WHERE id = ?')->execute([$u['id']]);
    }
    return $u;
}
function issue_token(int $uid): string
{
    $t = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO auth_tokens (user_id, token_hash) VALUES (?, ?)')->execute([$uid, hash('sha256', $t)]);
    return $t;
}
function public_user(array $u): array
{
    return [
        'id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email'],
        'email_alerts' => (bool)$u['email_alerts'], 'push_alerts' => (bool)$u['push_alerts'],
        'elite_only' => (bool)$u['elite_only'],
        'is_premium' => is_premium($u),
        'premium_until' => $u['premium_until'] ? gmdate('c', strtotime($u['premium_until'] . ' UTC')) : null,
        'is_admin' => is_admin($u),
        'ref_code' => $u['ref_code'] ?? null,
        'created_at' => gmdate('c', strtotime($u['created_at'] . ' UTC')),
    ];
}
function signal_out(array $s, ?array $viewer = null): array
{
    $locked = signal_locked_for($s, $viewer);
    return [
        'id' => (int)$s['id'], 'pair' => $s['pair'], 'direction' => $s['direction'],
        'strategy' => $s['strategy'], 'strategy_name' => Notifier::strategyName($s['strategy']),
        'tier' => $s['tier'], 'score' => (int)$s['score'],
        'locked' => $locked,
        'entry' => $locked ? null : (float)$s['entry'], 'sl' => $locked ? null : (float)$s['sl'],
        'tp1' => $locked ? null : (float)$s['tp1'], 'tp2' => $locked ? null : (float)$s['tp2'],
        'reasons' => json_decode($s['reasons'], true) ?: [],
        'status' => $s['status'],
        'result_pips' => $s['result_pips'] === null ? null : (float)$s['result_pips'],
        'max_favorable_pips' => (float)$s['max_favorable_pips'],
        'created_at' => gmdate('c', strtotime($s['created_at'] . ' UTC')),
        'closes_at' => gmdate('c', strtotime($s['closes_at'] . ' UTC')),
        'closed_at' => $s['closed_at'] ? gmdate('c', strtotime($s['closed_at'] . ' UTC')) : null,
    ];
}
function rate_limit(string $bucket, int $max, int $window): void
{
    $key = 'rl_' . $bucket . '_' . sha1($_SERVER['REMOTE_ADDR'] ?? '');
    $d = kv_get($key, ['n' => 0, 't' => time()]);
    if (time() - $d['t'] > $window) $d = ['n' => 0, 't' => time()];
    if (++$d['n'] > $max) fail('Too many attempts. Try again later.', 429);
    kv_set($key, $d);
}

$route = $_GET['r'] ?? ($_SERVER['PATH_INFO'] ?? '/');
$route = '/' . trim(parse_url($route, PHP_URL_PATH) ?? '/', '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ---------------------------------------------------------- auth
    if ($route === '/auth/register' && $method === 'POST') {
        rate_limit('reg', 10, 3600);
        $b = body();
        $name = trim($b['name'] ?? ''); $email = strtolower(trim($b['email'] ?? '')); $pass = (string)($b['password'] ?? '');
        if (mb_strlen($name) < 2) fail('Please enter your name');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email');
        if (strlen($pass) < 8) fail('Password must be at least 8 characters');
        $st = db()->prepare('SELECT id FROM users WHERE email = ?'); $st->execute([$email]);
        if ($st->fetch()) fail('An account with this email already exists', 409);
        $referrer = referrer_by_code($b['ref'] ?? null);
        $source = ($b['source'] ?? '') === 'web' ? 'web' : 'app';
        db()->prepare('INSERT INTO users (name, email, password_hash, ref_code, referred_by, signup_source) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT), new_ref_code($name),
                $referrer && strtolower($referrer['email']) !== $email ? $referrer['id'] : null, $source]);
        $id = (int)db()->lastInsertId();
        $u = db()->query("SELECT * FROM users WHERE id = $id")->fetch();
        out(['token' => issue_token($id), 'user' => public_user($u)], 201);
    }
    if ($route === '/auth/login' && $method === 'POST') {
        rate_limit('login', 20, 900);
        $b = body();
        $st = db()->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([strtolower(trim($b['email'] ?? ''))]);
        $u = $st->fetch();
        if (!$u || !password_verify((string)($b['password'] ?? ''), $u['password_hash'])) fail('Incorrect email or password', 401);
        if ($u['status'] === 'banned') fail('This account has been suspended. Contact support.', 403);
        ensure_ref_code($u);
        out(['token' => issue_token((int)$u['id']), 'user' => public_user($u)]);
    }
    if ($route === '/auth/forgot' && $method === 'POST') {
        rate_limit('forgot', 5, 3600);
        $email = strtolower(trim(body()['email'] ?? ''));
        $st = db()->prepare('SELECT * FROM users WHERE email = ?'); $st->execute([$email]);
        if ($u = $st->fetch()) {
            $code = (string)random_int(100000, 999999);
            db()->prepare('UPDATE users SET reset_code = ?, reset_expires = ? WHERE id = ?')
                ->execute([password_hash($code, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s', time() + 1800), $u['id']]);
            Notifier::sendMail($email, 'Your DayFX password reset code',
                Notifier::emailHtml('Password reset code', ['Code' => $code, 'Expires' => 'in 30 minutes']));
        }
        out(['ok' => true]); // same response either way: no account enumeration
    }
    if ($route === '/auth/reset' && $method === 'POST') {
        rate_limit('reset', 10, 3600);
        $b = body();
        $st = db()->prepare('SELECT * FROM users WHERE email = ?'); $st->execute([strtolower(trim($b['email'] ?? ''))]);
        $u = $st->fetch();
        if (!$u || !$u['reset_code'] || strtotime($u['reset_expires'] . ' UTC') < time()
            || !password_verify((string)($b['code'] ?? ''), $u['reset_code'])) fail('Invalid or expired code');
        if (strlen((string)($b['password'] ?? '')) < 8) fail('Password must be at least 8 characters');
        db()->prepare('UPDATE users SET password_hash = ?, reset_code = NULL, reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($b['password'], PASSWORD_DEFAULT), $u['id']]);
        db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?')->execute([$u['id']]);
        out(['token' => issue_token((int)$u['id']), 'user' => public_user($u)]);
    }

    // ---------------------------------------------------------- public
    if ($route === '/config' && $method === 'GET') {
        out([
            'site_name' => setting('site_name'),
            'announcement' => setting('announcement'),
            'premium_enabled' => premium_on() && paystack_ready(),
            'lock_scope' => setting('lock_scope'),
            'free_delay_minutes' => (int)setting('free_delay_minutes'),
            'plans' => plans(),
            'affiliate_enabled' => (bool)setting('affiliate_enabled'),
            'commission_pct' => (float)setting('commission_pct'),
        ]);
    }
    if ($route === '/ref/click' && $method === 'POST') {
        rate_limit('click', 30, 3600);
        record_ref_click((string)(body()['code'] ?? ''));
        out(['ok' => true]);
    }
    if ($route === '/ref/check' && $method === 'GET') {
        $r = referrer_by_code($_GET['code'] ?? '');
        out(['valid' => (bool)$r, 'name' => $r ? explode(' ', $r['name'])[0] : null]);
    }
    if ($route === '/paystack/webhook' && $method === 'POST') {
        $raw = file_get_contents('php://input') ?: '';
        $sig = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
        if (!paystack_ready() || !hash_equals(hash_hmac('sha512', $raw, (string)cfg('paystack_secret_key')), $sig)) fail('Bad signature', 401);
        $ev = json_decode($raw, true) ?: [];
        if (($ev['event'] ?? '') === 'charge.success' && !empty($ev['data']['reference'])) {
            // re-check with Paystack instead of trusting the payload alone
            paystack_verify((string)$ev['data']['reference']);
        }
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- authenticated
    $user = auth_user();

    if ($route === '/auth/logout' && $method === 'POST') {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $m);
        db()->prepare('DELETE FROM auth_tokens WHERE token_hash = ?')->execute([hash('sha256', $m[1])]);
        out(['ok' => true]);
    }
    if ($route === '/me' && $method === 'GET') { ensure_ref_code($user); out(['user' => public_user($user)]); }
    if ($route === '/me/password' && $method === 'POST') {
        rate_limit('pw', 10, 3600);
        $b = body();
        if (!password_verify((string)($b['current'] ?? ''), $user['password_hash'])) fail('Current password is wrong');
        if (strlen((string)($b['password'] ?? '')) < 8) fail('New password must be at least 8 characters');
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($b['password'], PASSWORD_DEFAULT), $user['id']]);
        out(['ok' => true]);
    }
    if ($route === '/me/name' && $method === 'POST') {
        $n = trim((string)(body()['name'] ?? ''));
        if (mb_strlen($n) < 2 || mb_strlen($n) > 80) fail('Please enter your name');
        db()->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$n, $user['id']]);
        out(['user' => public_user(user_by_id((int)$user['id']))]);
    }

    // ---------------------------------------------------------- Premium (web checkout via Paystack)
    if ($route === '/billing' && $method === 'GET') {
        $st = db()->prepare("SELECT reference, plan, amount, currency, status, created_at, paid_at FROM payments
                             WHERE user_id = ? AND status <> 'pending' ORDER BY id DESC LIMIT 30");
        $st->execute([$user['id']]);
        out([
            'enabled' => premium_on() && paystack_ready(),
            'is_premium' => is_premium($user),
            'premium_until' => $user['premium_until'] ? gmdate('c', strtotime($user['premium_until'] . ' UTC')) : null,
            'plans' => plans(),
            'lock_scope' => setting('lock_scope'),
            'payments' => array_map(fn($p) => [
                'reference' => $p['reference'], 'plan' => $p['plan'], 'status' => $p['status'],
                'amount' => (int)$p['amount'], 'currency' => $p['currency'], 'label' => money((int)$p['amount'], $p['currency']),
                'date' => gmdate('c', strtotime(($p['paid_at'] ?: $p['created_at']) . ' UTC')),
            ], $st->fetchAll()),
        ]);
    }
    if ($route === '/billing/checkout' && $method === 'POST') {
        rate_limit('checkout', 20, 3600);
        if (!premium_on() || !paystack_ready()) fail('Premium is not available yet');
        $plan = plan_by_id((string)(body()['plan'] ?? ''));
        if (!$plan || $plan['amount'] < 100) fail('Choose a plan');
        try { out(paystack_checkout($user, $plan)); } catch (RuntimeException $e) { fail($e->getMessage(), 502); }
    }
    if ($route === '/billing/verify' && $method === 'POST') {
        $ref = (string)(body()['reference'] ?? '');
        $st = db()->prepare('SELECT * FROM payments WHERE reference = ? AND user_id = ?');
        $st->execute([$ref, $user['id']]);
        if (!$st->fetch()) fail('Payment not found', 404);
        $p = paystack_verify($ref);
        $u = user_by_id((int)$user['id']);
        out(['status' => $p['status'] ?? 'pending', 'user' => public_user($u)]);
    }

    // ---------------------------------------------------------- affiliate programme
    if ($route === '/affiliate' && $method === 'GET') {
        $code = ensure_ref_code($user);
        $st = db()->prepare("SELECT c.amount, c.currency, c.rate, c.status, c.available_at, c.created_at, u.name
                             FROM commissions c LEFT JOIN users u ON u.id = c.referred_user_id
                             WHERE c.affiliate_id = ? ORDER BY c.id DESC LIMIT 50");
        $st->execute([$user['id']]);
        $commissions = array_map(fn($c) => [
            'amount' => (int)$c['amount'], 'label' => money((int)$c['amount'], $c['currency']), 'rate' => (float)$c['rate'],
            'status' => $c['status'] === 'void' ? 'void' : (strtotime($c['available_at'] . ' UTC') > time() ? 'pending' : 'available'),
            'from' => $c['name'] ? mb_substr(explode(' ', $c['name'])[0], 0, 1) . '***' : 'Deleted user',
            'date' => gmdate('c', strtotime($c['created_at'] . ' UTC')),
            'available_at' => gmdate('c', strtotime($c['available_at'] . ' UTC')),
        ], $st->fetchAll());
        $st = db()->prepare('SELECT amount, currency, status, admin_note, created_at, processed_at FROM payouts WHERE affiliate_id = ? ORDER BY id DESC LIMIT 30');
        $st->execute([$user['id']]);
        $payouts = array_map(fn($p) => [
            'amount' => (int)$p['amount'], 'label' => money((int)$p['amount'], $p['currency']), 'status' => $p['status'],
            'note' => $p['admin_note'], 'date' => gmdate('c', strtotime($p['created_at'] . ' UTC')),
        ], $st->fetchAll());
        $bal = affiliate_balance((int)$user['id']);
        out([
            'enabled' => (bool)setting('affiliate_enabled'),
            'premium_enabled' => premium_on() && paystack_ready(),
            'code' => $code,
            'link' => ref_link($code),
            'commission_pct' => (float)setting('commission_pct'),
            'recurring' => (bool)setting('commission_recurring'),
            'hold_days' => (int)setting('hold_days'),
            'min_payout' => (int)setting('min_payout'),
            'min_payout_label' => money((int)setting('min_payout')),
            'currency' => setting('currency'),
            'stats' => affiliate_counts((int)$user['id']),
            'balance' => $bal,
            'balance_labels' => array_map(fn($v) => money($v), $bal),
            'commissions' => $commissions,
            'payouts' => $payouts,
            'payout_details' => json_decode((string)$user['payout_details'], true) ?: null,
        ]);
    }
    if ($route === '/affiliate/details' && $method === 'POST') {
        $b = body();
        $d = [
            'bank_name' => trim(mb_substr((string)($b['bank_name'] ?? ''), 0, 80)),
            'account_number' => preg_replace('/\D/', '', (string)($b['account_number'] ?? '')),
            'account_name' => trim(mb_substr((string)($b['account_name'] ?? ''), 0, 100)),
        ];
        if ($d['bank_name'] === '' || strlen($d['account_number']) < 6 || strlen($d['account_number']) > 20 || $d['account_name'] === '') {
            fail('Enter your bank name, account number and account name');
        }
        db()->prepare('UPDATE users SET payout_details = ? WHERE id = ?')->execute([json_encode($d), $user['id']]);
        out(['ok' => true, 'payout_details' => $d]);
    }
    if ($route === '/affiliate/payout' && $method === 'POST') {
        rate_limit('payout', 10, 3600);
        if (!setting('affiliate_enabled')) fail('The affiliate programme is paused');
        $details = json_decode((string)$user['payout_details'], true);
        if (!$details) fail('Add your bank details first');
        $st = db()->prepare("SELECT COUNT(*) FROM payouts WHERE affiliate_id = ? AND status = 'requested'");
        $st->execute([$user['id']]);
        if ((int)$st->fetchColumn() > 0) fail('You already have a payout request being processed');
        $bal = affiliate_balance((int)$user['id']);
        $amount = isset(body()['amount']) ? (int)round((float)body()['amount'] * 100) : $bal['available'];
        if ($amount < (int)setting('min_payout')) fail('Minimum payout is ' . money((int)setting('min_payout')));
        if ($amount > $bal['available']) fail('Amount is more than your available balance');
        db()->prepare('INSERT INTO payouts (affiliate_id, amount, currency, details) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], $amount, setting('currency'), json_encode($details)]);
        $to = (array)(cfg('admin_emails') ?? []);
        if ($to) Notifier::sendMail(implode(',', $to), 'New affiliate payout request', Notifier::emailHtml('Payout request', [
            'Affiliate' => $user['name'] . ' <' . $user['email'] . '>', 'Amount' => money($amount),
            'Bank' => $details['bank_name'] . ' · ' . $details['account_number'] . ' · ' . $details['account_name'],
        ]));
        out(['ok' => true]);
    }
    if ($route === '/me' && $method === 'DELETE') { // Google Play account-deletion requirement
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM device_tokens WHERE user_id = ?')->execute([$user['id']]);
            $pdo->prepare('UPDATE users SET referred_by = NULL WHERE referred_by = ?')->execute([$user['id']]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        out(['ok' => true]);
    }
    if ($route === '/me/settings' && $method === 'POST') {
        $b = body();
        foreach (['email_alerts', 'push_alerts', 'elite_only'] as $f) {
            if (array_key_exists($f, $b)) {
                db()->prepare("UPDATE users SET $f = ? WHERE id = ?")->execute([$b[$f] ? 1 : 0, $user['id']]);
            }
        }
        $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$user['id']]);
        out(['user' => public_user($st->fetch())]);
    }
    if ($route === '/device' && $method === 'POST') {
        $b = body();
        $tok = substr((string)($b['fcm_token'] ?? ''), 0, 255);
        if ($tok === '') fail('fcm_token required');
        db()->prepare('INSERT INTO device_tokens (user_id, fcm_token, platform) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform)')
            ->execute([$user['id'], $tok, substr((string)($b['platform'] ?? 'android'), 0, 20)]);
        out(['ok' => true]);
    }

    // ---------------------------------------------------------- signals
    if ($route === '/signals' && $method === 'GET') {
        $scope = $_GET['scope'] ?? 'active';
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
        $where = $scope === 'active' ? "status IN ('ACTIVE','TP1')" : "status NOT IN ('ACTIVE','TP1')";
        $params = [];
        if (($_GET['tier'] ?? '') === 'elite') $where .= " AND tier = 'ELITE'";
        if (!empty($_GET['before_id'])) { $where .= ' AND id < ?'; $params[] = (int)$_GET['before_id']; }
        $st = db()->prepare("SELECT * FROM signals WHERE $where ORDER BY id DESC LIMIT $limit");
        $st->execute($params);
        out(['signals' => array_map(fn($s) => signal_out($s, $user), $st->fetchAll())]);
    }
    if (preg_match('#^/signals/(\d+)$#', $route, $m) && $method === 'GET') {
        $st = db()->prepare('SELECT * FROM signals WHERE id = ?'); $st->execute([(int)$m[1]]);
        $s = $st->fetch();
        if (!$s) fail('Not found', 404);
        out(['signal' => signal_out($s, $user)]);
    }

    // ---------------------------------------------------------- results / track record
    if ($route === '/stats' && $method === 'GET') {
        $period = $_GET['period'] ?? 'week';
        $from = match ($period) {
            'today' => gmdate('Y-m-d 00:00:00'),
            'week' => gmdate('Y-m-d 00:00:00', strtotime('monday this week')),
            'month' => gmdate('Y-m-01 00:00:00'),
            default => '2000-01-01 00:00:00',
        };
        $tierSql = ($_GET['tier'] ?? '') === 'elite' ? " AND tier = 'ELITE'" : '';
        $st = db()->prepare("SELECT * FROM signals WHERE created_at >= ? AND status NOT IN ('ACTIVE','TP1') $tierSql");
        $st->execute([$from]);
        $rows = $st->fetchAll();
        $agg = function (array $rows): array {
            $w = $l = $be = 0; $pips = 0.0;
            foreach ($rows as $r) {
                $p = (float)$r['result_pips'];
                $pips += $p;
                if ($r['status'] === 'BREAKEVEN') $be++;
                elseif ($p > 0) $w++;
                else $l++;
            }
            $decided = $w + $l;
            return ['wins' => $w, 'losses' => $l, 'breakeven' => $be, 'total' => count($rows),
                'win_rate' => $decided ? round($w / $decided * 100, 1) : null, 'pips' => round($pips, 1)];
        };
        $by = function (string $field) use ($rows, $agg): array {
            $g = [];
            foreach ($rows as $r) $g[$r[$field]][] = $r;
            $o = [];
            foreach ($g as $k => $v) $o[] = ['key' => $k] + $agg($v);
            return $o;
        };
        // last 30 days daily series (always)
        $st = db()->prepare("SELECT DATE(created_at) d, status, result_pips FROM signals
                             WHERE created_at >= ? AND status NOT IN ('ACTIVE','TP1') $tierSql");
        $st->execute([gmdate('Y-m-d', time() - 29 * 86400)]);
        $daily = [];
        foreach ($st->fetchAll() as $r) $daily[$r['d']][] = $r;
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = gmdate('Y-m-d', time() - $i * 86400);
            $series[] = ['date' => $d] + $agg($daily[$d] ?? []);
        }
        $strategies = array_map(fn($x) => $x + ['name' => Notifier::strategyName($x['key'])], $by('strategy'));
        out([
            'period' => $period,
            'summary' => $agg($rows),
            'by_strategy' => $strategies,
            'by_tier' => $by('tier'),
            'daily' => $series,
            'engine_heartbeat' => kv_get('engine_heartbeat'),
        ]);
    }

    // ---------------------------------------------------------- market data
    if ($route === '/news' && $method === 'GET') {
        $st = db()->prepare("SELECT title, currency, impact, event_time, forecast, previous FROM news_events
                             WHERE event_time BETWEEN ? AND ? AND impact IN ('High','Medium') ORDER BY event_time");
        $st->execute([gmdate('Y-m-d H:i:s', time() - 86400), gmdate('Y-m-d H:i:s', time() + 7 * 86400)]);
        $events = array_map(fn($e) => [
            'title' => $e['title'], 'currency' => $e['currency'], 'impact' => $e['impact'],
            'time' => gmdate('c', strtotime($e['event_time'] . ' UTC')),
            'forecast' => $e['forecast'], 'previous' => $e['previous'],
        ], $st->fetchAll());
        out(['events' => $events]);
    }
    if ($route === '/prices' && $method === 'GET') {
        out(['prices' => kv_get('last_prices', [])]);
    }

    fail('Not found', 404);
} catch (Throwable $e) {
    error_log('[dayfx api] ' . $e->getMessage());
    fail('Server error', 500);
}
