<?php
declare(strict_types=1);
// DayFX homepage (100apps landing kit, Forex Signals Live design). Served at "/".
require __DIR__ . '/../lib/bootstrap.php';

$px = fn($v) => $v === null ? '—' : (abs((float)$v) >= 100 ? number_format((float)$v, 2, '.', '') : (abs((float)$v) >= 10 ? number_format((float)$v, 3, '.', '') : number_format((float)$v, 5, '.', '')));
$ts = fn($d) => $d ? strtotime($d . ' UTC') : time();

$data = ['stats' => ['win_rate' => null, 'pips' => 0, 'closed' => 0, 'live' => 0, 'unit' => 'pips', 'series' => []], 'live' => [], 'results' => []];
try {
    $pdo = db();
    $closed = $pdo->query("SELECT status, result_pips, closed_at FROM signals WHERE closed_at IS NOT NULL ORDER BY closed_at")->fetchAll(PDO::FETCH_ASSOC);
    $w = count(array_filter($closed, fn($r) => $r['status'] === 'WIN'));
    $l = count(array_filter($closed, fn($r) => $r['status'] === 'LOSS'));
    $data['stats']['closed'] = count($closed);
    $data['stats']['win_rate'] = ($w + $l) ? $w / ($w + $l) * 100 : null; // same as the app: wins / (wins + losses)
    $data['stats']['pips'] = array_sum(array_map(fn($r) => (float)$r['result_pips'], $closed));
    $data['stats']['live'] = (int)$pdo->query("SELECT COUNT(*) FROM signals WHERE closed_at IS NULL")->fetchColumn();
    $cum = 0; $byDay = [];
    foreach ($closed as $r) { $cum += (float)$r['result_pips']; $byDay[substr((string)$r['closed_at'], 0, 10)] = $cum; }
    $from = gmdate('Y-m-d', time() - 90 * 86400);
    $data['stats']['series'] = array_values(array_filter(array_map(null, array_keys($byDay), array_values($byDay)), fn($p) => $p[0] >= $from));
    $names = ['trend_pullback' => 'Trend pullback', 'break_retest' => 'Break & retest', 'london_breakout' => 'London breakout', 'news_momentum' => 'News momentum', 'manual' => 'Analyst call'];
    foreach ($pdo->query("SELECT pair, direction, created_at, score, strategy FROM signals WHERE closed_at IS NULL ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $data['live'][] = [$r['pair'], $r['direction'], $ts($r['created_at']), (int)$r['score'], $names[$r['strategy']] ?? null];
    $labels = ['WIN' => 'Win (TP1+)', 'LOSS' => 'Stop loss', 'BREAKEVEN' => 'Breakeven', 'CLOSED_EOD' => 'Closed end of day'];
    foreach ($pdo->query("SELECT pair, direction, entry, status, result_pips, closed_at FROM signals WHERE closed_at IS NOT NULL ORDER BY closed_at DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $data['results'][] = [$r['pair'], $r['direction'], $px($r['entry']), '—', $labels[$r['status']] ?? $r['status'], (float)$r['result_pips'], $ts($r['closed_at'])];
} catch (Throwable $e) {
    error_log('[dayfx home] ' . $e->getMessage());
}

$site = [
    'brand' => 'DayFX Signals', 'logo' => 'DX', 'url' => 'https://dayfxsignal.100apkgoals.com',
    'accent' => ['#86EFAC', '#22C55E', '#16A34A', '#04130A'],
    'meta_title' => 'DayFX: Free Forex Day Trading Signals with Entry, SL & TP',
    'meta_description' => 'Free forex and gold day trading signals from price action and news strategies, with entry, stop loss, two targets and a public, fully tracked track record.',
    'nav' => [['#features', 'Features'], ['#how', 'How it works'], ['#faq', 'FAQ']],
    'dashboard_url' => '/app/', 'dashboard_label' => 'Open web app', 'signup_url' => '/app/#/register', 'record_url' => '/app/#/results',
    'play_url' => 'https://play.google.com/store/apps/details?id=com.swiftmynd.dayfx_signals',
    'eyebrow' => 'Forex & gold day trading',
    'h1' => ['Day trading signals,', 'closed before the day ends'],
    'lead' => 'Price action and news strategies on the 15-minute chart, filtered by the 1-hour trend. Every trade has an entry, stop loss and two targets, is closed the same day, and is tracked in public.',
    'ticks' => ['No card needed', 'No overnight trades', 'Every signal free'],
    'features_title' => 'Built for the trading day',
    'features' => [
        ['plan', 'Full trade plan', 'Entry, stop loss beyond structure, TP1 at 1R and TP2 at 2R, with the reasons and a 0–100 confluence score.'],
        ['shield', 'Elite mode', 'Only the highest-confluence setups (score 85+), when you want fewer, stronger signals.'],
        ['news', 'News protection', 'No new signals within 30 minutes of high-impact news on either currency of the pair.'],
        ['clock', 'Session timing', 'New signals only between 07:00 and 19:00 UTC; everything is closed by 21:00 UTC.'],
        ['chart', 'Every result tracked', 'Win rate, pips and results by strategy and tier, with daily, weekly and monthly reports.'],
        ['calc', 'Lot size calculator', 'Size each trade from your account and risk so one loss never hurts too much.'],
    ],
    'steps' => [
        ['Create a free account', 'Sign up on the web or in the app. It is the same account everywhere.'],
        ['Choose your mode', 'Follow every signal, or switch on Elite mode for the strongest setups only.'],
        ['Trade the plan', 'Enter at the entry price, set the stop and targets. TP1 closes half and moves the stop to entry.'],
    ],
    'band' => ['Invite traders to free DayFX signals', 'Get your own referral link and code, and share free signals with your groups and followers.', 'Get your referral link', '/app/#/earn'],
    'faq' => [
        ['Are the signals really free?', 'Yes. Every signal, every level and the full track record are free on the web and in the app.'],
        ['What markets do you cover?', 'EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, EUR/JPY and gold (XAU/USD).'],
        ['How are results counted?', 'A win is TP1 reached before the stop. If one candle touches both, it counts as a loss. After TP1 the stop moves to entry, so a later stop-out is breakeven on the second half.'],
        ['Do you hold trades overnight?', 'No. New signals stop at 19:00 UTC and every open trade is closed at 21:00 UTC.'],
        ['Do you guarantee a win rate?', 'No. Nobody can. We publish every result so you can judge the record yourself.'],
        ['Is this financial advice?', 'No. Signals are rule-based information for education. Trading forex carries a high risk of loss.'],
    ],
    'risk' => 'Trading forex carries a high risk of loss. Results use mid-market prices without spread. Past results do not guarantee future results.',
    'tagline' => 'Rule-based forex day trading signals with a public, fully tracked record.',
    'footer' => [
        'Product' => [['/app/', 'Web app'], ['/app/#/results', 'Track record'], ['/app/#/news', 'News calendar']],
        'Earn' => [['/app/#/earn', 'Referral link'], ['https://wa.me/2347011401387?text=' . rawurlencode('Hi, I want to hire you to build an app'), 'Hire the developer']],
        'Legal' => [['/privacy.html', 'Privacy'], ['/delete-account.html', 'Delete account']],
    ],
];
require __DIR__ . '/landing.php';
