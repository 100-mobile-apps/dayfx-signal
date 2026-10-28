<?php
/**
 * Backtest the price-action strategies on ~50 days of real M15 data BEFORE going live.
 *   php backtest.php                 -> all configured pairs (uses 7 API credits)
 *   php backtest.php EUR/USD         -> one pair
 *   php backtest.php --csv=file.csv  -> offline: CSV columns datetime,open,high,low,close (UTC, oldest first)
 *   add --min=85 to see the Elite-only win rate
 * News strategies/filters are skipped in backtests (no historical calendar), so treat results as indicative.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/Engine.php';
require __DIR__ . '/lib/Tracker.php';

Engine::$offline = true;
$args = array_slice($argv, 1);
$opts = []; $pairs = [];
foreach ($args as $a) {
    if (preg_match('/^--(\w+)=(.*)$/', $a, $m)) $opts[$m[1]] = $m[2]; else $pairs[] = $a;
}
$minScore = (int)($opts['min'] ?? cfg('min_score'));

function load_twelvedata(string $pair): array
{
    $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
        'symbol' => $pair, 'interval' => '15min', 'outputsize' => 5000,
        'timezone' => 'UTC', 'order' => 'ASC', 'apikey' => cfg('twelvedata_key'),
    ]);
    $d = http_get_json($url, 60);
    if (!$d || empty($d['values'])) { fwrite(STDERR, "No data for $pair\n"); return []; }
    return array_map(fn($v) => ['t' => strtotime($v['datetime'] . ' UTC'), 'o' => (float)$v['open'],
        'h' => (float)$v['high'], 'l' => (float)$v['low'], 'c' => (float)$v['close']], $d['values']);
}
function load_csv(string $file): array
{
    $out = [];
    $fh = fopen($file, 'r');
    while (($r = fgetcsv($fh)) !== false) {
        if (!is_numeric($r[1] ?? null)) continue;
        $out[] = ['t' => strtotime($r[0] . ' UTC'), 'o' => (float)$r[1], 'h' => (float)$r[2], 'l' => (float)$r[3], 'c' => (float)$r[4]];
    }
    return $out;
}

$datasets = [];
if (isset($opts['csv'])) {
    $datasets[$opts['pair'] ?? 'EUR/USD'] = load_csv($opts['csv']);
} else {
    foreach ($pairs ?: cfg('pairs') as $i => $p) {
        if ($i > 0) sleep(8);
        $datasets[$p] = load_twelvedata($p);
    }
}

$all = [];
$eod = (int)cfg('eod_close_utc');
foreach ($datasets as $pair => $m15) {
    $n = count($m15);
    if ($n < 1200) { echo "$pair: not enough data ($n bars)\n"; continue; }
    $endTs = $m15[$n - 1]['t'] + 900;
    $busyUntil = 0; $perDay = [];
    for ($i = 1000; $i < $n - 1; $i++) {
        $barClose = $m15[$i]['t'] + 900;
        if ($barClose < $busyUntil) continue;
        $day = gmdate('Y-m-d', $barClose);
        if (($perDay[$day] ?? 0) >= 3) continue;
        $sig = (new Engine($pair, array_slice($m15, $i - 999, 1000)))->run();
        if (!$sig || $sig['score'] < $minScore) continue;
        $sig['closes_at'] = gmdate('Y-m-d', $barClose) . sprintf(' %02d:00:00', $eod);
        [$status, $pips, , $closedAt] = Tracker::simulate($sig, array_slice($m15, $i + 1), $endTs);
        if ($status === 'ACTIVE' || $status === 'TP1') break; // still open at end of data
        $sig['status'] = $status; $sig['pips'] = round((float)$pips, 1);
        $all[] = $sig;
        $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        $busyUntil = max($closedAt ?? $barClose, $barClose + 3600); // one trade at a time + 60m cooldown
    }
}

function summarize(string $label, array $rows): void
{
    $w = $l = $be = 0; $p = 0.0;
    foreach ($rows as $r) {
        $p += $r['pips'];
        if ($r['status'] === 'BREAKEVEN') $be++; elseif ($r['pips'] > 0) $w++; else $l++;
    }
    $wr = ($w + $l) ? round($w / ($w + $l) * 100, 1) . '%' : '-';
    printf("%-22s trades %4d  win %4d  loss %4d  BE %3d  win-rate %7s  pips %+8.1f\n", $label, count($rows), $w, $l, $be, $wr, $p);
}

echo "\n=== DayFX backtest (min score $minScore) ===\n";
summarize('ALL', $all);
foreach (['ELITE', 'HIGH', 'STANDARD'] as $t) summarize("tier $t", array_filter($all, fn($r) => $r['tier'] === $t));
foreach (array_unique(array_column($all, 'strategy')) as $s) summarize($s, array_filter($all, fn($r) => $r['strategy'] === $s));
foreach (array_keys($datasets) as $p) summarize($p, array_filter($all, fn($r) => $r['pair'] === $p));
echo "\nTip: raise min_score / elite_score in config.php until the Elite win-rate is where you want it,\n";
echo "then let the live tracker prove it. Backtests exclude spread/slippage (~0.5–2 pips per trade).\n";
