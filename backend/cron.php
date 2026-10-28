<?php
/**
 * Run every 15 minutes, 1 minute after the candle closes.
 * cPanel > Cron Jobs:   1,16,31,46 * * * *   /usr/local/bin/php /home/USER/dayfx/cron.php >/dev/null 2>&1
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/MarketData.php';
require __DIR__ . '/lib/Engine.php';
require __DIR__ . '/lib/Tracker.php';
require __DIR__ . '/lib/Notifier.php';

if (PHP_SAPI !== 'cli' && !hash_equals((string)cfg('cron_secret'), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('forbidden');
}
set_time_limit(240);

// Forex closes Fri 21:00 UTC -> Sun 21:00 UTC. Keep running briefly after close so open trades get settled.
$dow = (int)gmdate('N'); $h = (int)gmdate('G');
$weekend = ($dow === 6) || ($dow === 7 && $h < 21) || ($dow === 5 && $h >= 23);
// Day-trading window plus settlement buffer: 06:00–22:00 UTC (saves API credits overnight)
if ($weekend || $h < 6 || $h >= 22) {
    exit("idle\n");
}

$lock = fopen(sys_get_temp_dir() . '/dayfx_cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("already running\n");

News::refresh();
$prices = kv_get('last_prices', []);
$eod = (int)cfg('eod_close_utc');

foreach (cfg('pairs') as $idx => $pair) {
    if ($idx > 0) sleep(8); // Twelve Data free tier: 8 requests/minute
    $m15 = MarketData::m15($pair);
    if (!$m15 || count($m15) < 100) continue;

    $last = end($m15);
    $prev = $m15[count($m15) - 97] ?? $m15[0]; // ~24h ago
    $prices[$pair] = [
        'price' => $last['c'],
        'change_pct' => round(($last['c'] - $prev['c']) / $prev['c'] * 100, 2),
        'time' => gmdate('c', $last['t'] + 900),
    ];

    // 1) settle open trades
    foreach (Tracker::update($pair, $m15) as $changed) {
        Notifier::statusChange($changed);
    }

    // 2) look for a new setup — one open trade per pair, 60-min cooldown, max 3 per pair per day
    $open = db()->prepare("SELECT COUNT(*) FROM signals WHERE pair = ? AND status IN ('ACTIVE','TP1')");
    $open->execute([$pair]);
    if ((int)$open->fetchColumn() > 0) continue;
    $recent = db()->prepare("SELECT COUNT(*) FROM signals WHERE pair = ? AND created_at > ?");
    $recent->execute([$pair, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$recent->fetchColumn() > 0) continue;
    $today = db()->prepare("SELECT COUNT(*) FROM signals WHERE pair = ? AND created_at >= ?");
    $today->execute([$pair, gmdate('Y-m-d 00:00:00')]);
    if ((int)$today->fetchColumn() >= 3) continue;

    $sig = (new Engine($pair, $m15))->run();
    if (!$sig) continue;

    $closes = gmdate('Y-m-d', strtotime($sig['created_at'] . ' UTC')) . sprintf(' %02d:00:00', $eod);
    db()->prepare(
        'INSERT INTO signals (pair, direction, strategy, tier, score, entry, sl, tp1, tp2, reasons, created_at, closes_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $sig['pair'], $sig['direction'], $sig['strategy'], $sig['tier'], $sig['score'],
        $sig['entry'], $sig['sl'], $sig['tp1'], $sig['tp2'],
        json_encode($sig['reasons']), $sig['created_at'], $closes,
    ]);
    $sig['id'] = (int)db()->lastInsertId();
    log_engine('info', "NEW {$sig['direction']} {$pair} {$sig['strategy']} score {$sig['score']}");
    Notifier::newSignal($sig);
}

kv_set('last_prices', $prices);
kv_set('engine_heartbeat', time());
echo "ok\n";
