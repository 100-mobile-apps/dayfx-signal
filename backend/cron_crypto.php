<?php
/**
 * BTC/ETH 4-hour swing signals (see lib/CryptoSwing.php). Crypto trades 24/7, so this runs
 * every hour, weekends included, separately from the forex cron.
 *   5 * * * *   php /path/to/dayfx/cron_crypto.php
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/Tracker.php';
require __DIR__ . '/lib/CryptoSwing.php';
require_once __DIR__ . '/lib/Notifier.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$lock = fopen(sys_get_temp_dir() . '/dayfx_crypto.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("already running\n");

$prices = kv_get('last_prices', []);
$insert = db()->prepare(
    'INSERT INTO signals (pair, direction, strategy, tier, score, entry, sl, tp1, tp2, reasons, created_at, closes_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');

foreach (CryptoSwing::PAIRS as $pair => $symbol) {
    $h4 = CryptoSwing::klines($symbol, '4h', 1000);
    $m15 = CryptoSwing::klines($symbol, '15m', 1000);
    if (!$h4 || !$m15) continue;

    $last = end($m15);
    $prev = $m15[count($m15) - 97] ?? $m15[0];
    $prices[$pair] = ['price' => $last['c'], 'change_pct' => round(($last['c'] - $prev['c']) / $prev['c'] * 100, 2), 'time' => gmdate('c', $last['t'] + 900)];

    $sig = CryptoSwing::signal($pair, $h4);
    // only act on a candle that closed in the last 90 minutes (a missed run must not send a stale entry)
    if ($sig && time() - strtotime($sig['created_at'] . ' UTC') <= 5400) {
        // one open trade per pair, never the same candle twice
        $open = db()->prepare("SELECT COUNT(*) FROM signals WHERE pair = ? AND (status IN ('ACTIVE','TP1') OR created_at = ?)");
        $open->execute([$pair, $sig['created_at']]);
        if ((int)$open->fetchColumn() === 0) {
            $closes = gmdate('Y-m-d H:i:s', strtotime($sig['created_at'] . ' UTC') + CryptoSwing::HOLD_HOURS * 3600);
            $insert->execute([$sig['pair'], $sig['direction'], $sig['strategy'], $sig['tier'], $sig['score'],
                $sig['entry'], $sig['sl'], $sig['tp1'], $sig['tp2'], json_encode($sig['reasons']), $sig['created_at'], $closes]);
            $sig['id'] = (int)db()->lastInsertId();
            log_engine('info', "NEW {$sig['direction']} {$pair} {$sig['strategy']} score {$sig['score']}");
            Notifier::newSignal($sig);
        }
    }

    foreach (Tracker::update($pair, $m15) as $changed) {
        Notifier::statusChange($changed);
    }
}

kv_set('last_prices', $prices);
kv_set('crypto_heartbeat', time());
echo "ok\n";
