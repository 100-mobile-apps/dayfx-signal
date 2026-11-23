<?php
declare(strict_types=1);
require_once __DIR__ . '/Notifier.php';

/**
 * Shared account logic for the API, web app and admin panel:
 * settings, Premium gate, referrals, Paystack payments, commissions and payouts.
 * Money is always stored in minor units (kobo / cents).
 */

// ---------------------------------------------------------------- settings (editable in /admin)

const SETTING_DEFAULTS = [
    'site_name' => 'DayFX Signals',
    'announcement' => '',
    // Premium
    'premium_enabled' => false,          // OFF until Paystack keys are in config.php
    'currency' => 'NGN',
    'price_monthly' => 250000,           // minor units: ₦2,500.00
    'price_yearly' => 2000000,           // ₦20,000.00
    'lock_scope' => 'all',               // all | elite_high | elite  (which LIVE signals need Premium)
    'free_delay_minutes' => 0,           // 0 = free users never see live levels; e.g. 60 = levels unlock after 1 hour
    // Affiliates
    'affiliate_enabled' => true,
    'commission_pct' => 30.0,
    'commission_recurring' => true,      // also pay on renewals, not only the first payment
    'hold_days' => 7,                    // refund window before a commission can be withdrawn
    'min_payout' => 500000,              // ₦5,000.00
];

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $saved = [];
        try { $saved = kv_get('settings', []) ?: []; } catch (Throwable $e) {}
        $cache = array_merge(SETTING_DEFAULTS, array_intersect_key($saved, SETTING_DEFAULTS));
    }
    return $cache;
}

function setting(string $k)
{
    return settings_all()[$k] ?? null;
}

function settings_save(array $new): void
{
    $cur = settings_all();
    foreach ($new as $k => $v) {
        if (!array_key_exists($k, SETTING_DEFAULTS)) continue;
        $d = SETTING_DEFAULTS[$k];
        $cur[$k] = match (true) {
            is_bool($d) => (bool)$v,
            is_int($d) => (int)$v,
            is_float($d) => (float)$v,
            default => trim((string)$v),
        };
    }
    kv_set('settings', $cur);
}

function base_url(): string
{
    $b = (string)(cfg('base_url') ?? '');
    if ($b !== '') return rtrim($b, '/');
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // scripts live in /<root>/api, /<root>/admin, /<root>/app or /<root>
    $dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    if (preg_match('#/(api|admin|app)$#', $dir)) $dir = dirname($dir);
    return ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/');
}

function money(int $minor, ?string $cur = null): string
{
    $cur = $cur ?? setting('currency');
    $sym = ['NGN' => '₦', 'USD' => '$', 'GHS' => 'GH₵', 'KES' => 'KSh ', 'ZAR' => 'R'][$cur] ?? ($cur . ' ');
    return ($minor < 0 ? '-' : '') . $sym . number_format(abs($minor) / 100, 2);
}

// ---------------------------------------------------------------- users

function is_admin(array $u): bool
{
    return ($u['role'] ?? '') === 'admin'
        || in_array(strtolower($u['email'] ?? ''), array_map('strtolower', (array)(cfg('admin_emails') ?? [])), true);
}

function is_premium(array $u): bool
{
    return !empty($u['premium_until']) && strtotime($u['premium_until'] . ' UTC') > time();
}

function user_by_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function new_ref_code(string $name = ''): string
{
    $alpha = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $prefix = strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 4));
    for ($i = 0; $i < 20; $i++) {
        $code = $prefix;
        while (strlen($code) < ($prefix ? 8 : 7)) $code .= $alpha[random_int(0, strlen($alpha) - 1)];
        $st = db()->prepare('SELECT 1 FROM users WHERE ref_code = ?');
        $st->execute([$code]);
        if (!$st->fetch()) return $code;
    }
    return strtoupper(bin2hex(random_bytes(5)));
}

/** Existing users from before the upgrade get a code the first time it is needed. */
function ensure_ref_code(array &$u): string
{
    if (!empty($u['ref_code'])) return $u['ref_code'];
    $code = new_ref_code($u['name'] ?? '');
    db()->prepare('UPDATE users SET ref_code = ? WHERE id = ? AND ref_code IS NULL')->execute([$code, $u['id']]);
    $u = user_by_id((int)$u['id']) ?? $u;
    return $u['ref_code'];
}

function referrer_by_code(?string $code): ?array
{
    $code = strtoupper(trim((string)$code));
    if ($code === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $code)) return null;
    $st = db()->prepare("SELECT * FROM users WHERE ref_code = ? AND status = 'active'");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function ref_link(string $code): string
{
    return base_url() . '/r/' . $code;
}

// ---------------------------------------------------------------- Premium gate

function premium_on(): bool
{
    return (bool)setting('premium_enabled');
}

/** Would this live signal be hidden from a free user right now? */
function signal_locked_for_free(array $s): bool
{
    if (!premium_on()) return false;
    if (!in_array($s['status'], ['ACTIVE', 'TP1'], true)) return false; // history is always free
    $scope = setting('lock_scope');
    if ($scope === 'elite' && $s['tier'] !== 'ELITE') return false;
    if ($scope === 'elite_high' && $s['tier'] === 'STANDARD') return false;
    $delay = (int)setting('free_delay_minutes');
    if ($delay > 0 && time() - strtotime($s['created_at'] . ' UTC') >= $delay * 60) return false;
    return true;
}

function signal_locked_for(array $s, ?array $u): bool
{
    if ($u && (is_premium($u) || is_admin($u))) return false;
    return signal_locked_for_free($s);
}

function plans(): array
{
    $cur = setting('currency');
    $m = (int)setting('price_monthly');
    $y = (int)setting('price_yearly');
    $save = $m > 0 ? max(0, (int)round(100 - $y / ($m * 12) * 100)) : 0;
    return [
        ['id' => 'monthly', 'name' => 'Monthly', 'days' => 30, 'amount' => $m, 'currency' => $cur, 'label' => money($m, $cur), 'per' => 'month', 'save_pct' => 0],
        ['id' => 'yearly', 'name' => 'Yearly', 'days' => 365, 'amount' => $y, 'currency' => $cur, 'label' => money($y, $cur), 'per' => 'year', 'save_pct' => $save],
    ];
}

function plan_by_id(string $id): ?array
{
    foreach (plans() as $p) if ($p['id'] === $id) return $p;
    return null;
}

// ---------------------------------------------------------------- Paystack

function paystack_ready(): bool
{
    $k = (string)(cfg('paystack_secret_key') ?? '');
    return $k !== '' && str_starts_with($k, 'sk_');
}

function paystack_request(string $method, string $path, ?array $body = null): ?array
{
    $ch = curl_init(rtrim((string)(cfg('paystack_api') ?: 'https://api.paystack.co'), '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . cfg('paystack_secret_key'), 'Content-Type: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$res, true);
    return is_array($j) ? $j : null;
}

/** Creates a pending payment and returns Paystack's checkout URL. */
function paystack_checkout(array $u, array $plan): array
{
    $ref = 'DFX-' . $u['id'] . '-' . strtoupper(bin2hex(random_bytes(6)));
    db()->prepare('INSERT INTO payments (user_id, reference, plan, days, amount, currency) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$u['id'], $ref, $plan['id'], $plan['days'], $plan['amount'], $plan['currency']]);
    $r = paystack_request('POST', '/transaction/initialize', [
        'email' => $u['email'],
        'amount' => $plan['amount'],
        'currency' => $plan['currency'],
        'reference' => $ref,
        'callback_url' => base_url() . '/app/',
        'metadata' => ['user_id' => (int)$u['id'], 'plan' => $plan['id'], 'app' => 'dayfx'],
    ]);
    if (empty($r['status']) || empty($r['data']['authorization_url'])) {
        db()->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE reference = ?")
            ->execute([substr((string)($r['message'] ?? 'init failed'), 0, 250), $ref]);
        throw new RuntimeException('Could not start payment: ' . ($r['message'] ?? 'Paystack did not respond'));
    }
    return ['reference' => $ref, 'authorization_url' => $r['data']['authorization_url']];
}

/** Asks Paystack about a reference and applies it. Returns the payment row. */
function paystack_verify(string $ref): ?array
{
    $r = paystack_request('GET', '/transaction/verify/' . rawurlencode($ref));
    if (!empty($r['status']) && isset($r['data'])) apply_payment($ref, $r['data']);
    $st = db()->prepare('SELECT * FROM payments WHERE reference = ?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

/**
 * Idempotent: safe to call from the webhook and the browser return at the same time.
 * $tx is Paystack's transaction object (status, amount, currency, channel, gateway_response, paid_at).
 */
function apply_payment(string $ref, array $tx): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM payments WHERE reference = ? FOR UPDATE');
        $st->execute([$ref]);
        $p = $st->fetch();
        if (!$p || $p['status'] !== 'pending') { $pdo->commit(); return false; }

        if (($tx['status'] ?? '') !== 'success') {
            if (in_array($tx['status'] ?? '', ['failed', 'abandoned', 'reversed'], true)) {
                $pdo->prepare("UPDATE payments SET status = 'failed', gateway_response = ? WHERE id = ?")
                    ->execute([substr((string)($tx['gateway_response'] ?? $tx['status']), 0, 250), $p['id']]);
            }
            $pdo->commit();
            return false;
        }
        if ((int)($tx['amount'] ?? 0) < (int)$p['amount'] || strtoupper((string)($tx['currency'] ?? '')) !== $p['currency']) {
            $pdo->prepare("UPDATE payments SET status = 'failed', gateway_response = 'Amount or currency mismatch' WHERE id = ?")
                ->execute([$p['id']]);
            $pdo->commit();
            log_engine('error', "Payment $ref amount/currency mismatch");
            return false;
        }
        $pdo->prepare("UPDATE payments SET status = 'success', paid_at = ?, channel = ?, gateway_response = ? WHERE id = ?")
            ->execute([now_utc(), substr((string)($tx['channel'] ?? ''), 0, 30), substr((string)($tx['gateway_response'] ?? ''), 0, 250), $p['id']]);
        if ($p['user_id']) {
            extend_premium((int)$p['user_id'], (int)$p['days']);
            record_commission($p);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    if ($p['user_id'] && ($u = user_by_id((int)$p['user_id']))) {
        Notifier::sendMail($u['email'], 'Your DayFX Premium is active', Notifier::emailHtml('Premium activated ✅', [
            'Plan' => ucfirst($p['plan']),
            'Amount' => money((int)$p['amount'], $p['currency']),
            'Reference' => $ref,
            'Premium until' => gmdate('j M Y', strtotime($u['premium_until'] . ' UTC')),
        ]));
    }
    return true;
}

function extend_premium(int $uid, int $days): void
{
    db()->prepare('UPDATE users SET premium_until = DATE_ADD(GREATEST(COALESCE(premium_until, UTC_TIMESTAMP()), UTC_TIMESTAMP()), INTERVAL ? DAY) WHERE id = ?')
        ->execute([$days, $uid]);
}

// ---------------------------------------------------------------- affiliates

function record_commission(array $payment): void
{
    if (!setting('affiliate_enabled') || !$payment['user_id']) return;
    $buyer = user_by_id((int)$payment['user_id']);
    if (!$buyer || !$buyer['referred_by'] || (int)$buyer['referred_by'] === (int)$buyer['id']) return;
    $aff = user_by_id((int)$buyer['referred_by']);
    if (!$aff || $aff['status'] !== 'active') return;
    if (!setting('commission_recurring')) {
        $st = db()->prepare("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status IN ('success','refunded') AND id <> ?");
        $st->execute([$buyer['id'], $payment['id']]);
        if ((int)$st->fetchColumn() > 0) return; // first payment only
    }
    $rate = (float)setting('commission_pct');
    $amount = (int)floor((int)$payment['amount'] * $rate / 100);
    if ($amount <= 0) return;
    db()->prepare('INSERT IGNORE INTO commissions (affiliate_id, payment_id, referred_user_id, amount, currency, rate, available_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$aff['id'], $payment['id'], $buyer['id'], $amount, $payment['currency'], $rate,
            gmdate('Y-m-d H:i:s', time() + (int)setting('hold_days') * 86400)]);
}

/** Ledger: available = cleared commissions − paid payouts − open requests. */
function affiliate_balance(int $uid): array
{
    $q = function (string $sql) use ($uid): int {
        $st = db()->prepare($sql);
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    };
    $cleared = $q("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE affiliate_id = ? AND status = 'earned' AND available_at <= UTC_TIMESTAMP()");
    $pending = $q("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE affiliate_id = ? AND status = 'earned' AND available_at > UTC_TIMESTAMP()");
    $paid = $q("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE affiliate_id = ? AND status = 'paid'");
    $requested = $q("SELECT COALESCE(SUM(amount),0) FROM payouts WHERE affiliate_id = ? AND status = 'requested'");
    return [
        'pending' => $pending,
        'available' => $cleared - $paid - $requested,
        'requested' => $requested,
        'paid' => $paid,
        'earned' => $cleared + $pending,
    ];
}

function affiliate_counts(int $uid): array
{
    $q = function (string $sql) use ($uid): int {
        $st = db()->prepare($sql);
        $st->execute([$uid]);
        return (int)$st->fetchColumn();
    };
    return [
        'clicks' => $q('SELECT COUNT(*) FROM referral_clicks WHERE referrer_id = ?'),
        'signups' => $q('SELECT COUNT(*) FROM users WHERE referred_by = ?'),
        'paying' => $q("SELECT COUNT(DISTINCT p.user_id) FROM payments p JOIN users u ON u.id = p.user_id WHERE u.referred_by = ? AND p.status = 'success'"),
        'active_premium' => $q('SELECT COUNT(*) FROM users WHERE referred_by = ? AND premium_until > UTC_TIMESTAMP()'),
    ];
}

function record_ref_click(string $code): void
{
    $ref = referrer_by_code($code);
    if (!$ref) return;
    $ip = sha1(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    db()->prepare('INSERT IGNORE INTO referral_clicks (referrer_id, ip_hash, click_date) VALUES (?, ?, UTC_DATE())')
        ->execute([$ref['id'], $ip]);
}

/** Refunding a payment voids its commission. Optionally removes the Premium time it bought. */
function refund_payment(int $paymentId, bool $revokePremium): void
{
    $st = db()->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$paymentId]);
    $p = $st->fetch();
    if (!$p || $p['status'] !== 'success') return;
    db()->prepare("UPDATE payments SET status = 'refunded' WHERE id = ?")->execute([$paymentId]);
    db()->prepare("UPDATE commissions SET status = 'void' WHERE payment_id = ?")->execute([$paymentId]);
    if ($revokePremium && $p['user_id']) {
        db()->prepare('UPDATE users SET premium_until = DATE_SUB(premium_until, INTERVAL ? DAY) WHERE id = ? AND premium_until IS NOT NULL')
            ->execute([$p['days'], $p['user_id']]);
    }
}
