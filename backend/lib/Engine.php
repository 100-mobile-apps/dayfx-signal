<?php
declare(strict_types=1);

require_once __DIR__ . '/Indicators.php';
require_once __DIR__ . '/News.php';

/**
 * Forex day-trading signal engine.
 *
 * Every candidate setup is scored 0–100 on confluence. Only setups scoring >= min_score are published.
 *   Trend alignment (H1)       25
 *   Key level confluence       20
 *   Candle / trigger quality   15
 *   Room to target (R:R)       10
 *   RSI / momentum health      10
 *   Volatility (ATR regime)    10
 *   Session timing             10
 * Hard filters (setup rejected outright): outside session, high-impact news within the block window,
 * counter-trend pullbacks, no room to 1R, abnormal SL size, existing open trade on the pair.
 */
final class Engine
{
    private string $pair;
    private array $m15;
    private array $h1;
    private int $now;      // close time of the last closed M15 candle
    private float $pip;
    private array $closes;
    private array $ema20;
    private array $ema50;
    private array $atr;
    private array $rsi;
    private array $h1ema50;
    private array $h1ema200;
    private int $last;
    private ?array $levelsCache = null;
    /** Backtest mode: no DB access (news strategies/filters disabled). */
    public static bool $offline = false;

    public function __construct(string $pair, array $m15)
    {
        $this->pair = $pair;
        $this->m15 = $m15;
        $this->last = count($m15) - 1;
        $this->now = $m15[$this->last]['t'] + 900;
        $this->pip = pip_size($pair);
        $this->closes = array_column($m15, 'c');
        $this->ema20 = Indicators::ema($this->closes, 20);
        $this->ema50 = Indicators::ema($this->closes, 50);
        $this->atr = Indicators::atr($m15, 14);
        $this->rsi = Indicators::rsi($this->closes, 14);

        $h1 = Indicators::toH1($m15);
        if ($h1 && end($h1)['n'] < 4) array_pop($h1); // drop unfinished hour
        $this->h1 = $h1;
        $h1c = array_column($h1, 'c');
        $this->h1ema50 = Indicators::ema($h1c, 50);
        $this->h1ema200 = Indicators::ema($h1c, 200);
    }

    /** Returns the best qualifying signal (array) or null. Logs why setups were rejected. */
    public function run(): ?array
    {
        if (count($this->m15) < 300 || count($this->h1) < 60) return null;
        $hour = (int)gmdate('G', $this->now);
        if ($hour < cfg('session_start_utc') || $hour >= cfg('session_end_utc')) return null;

        $candidates = [];
        foreach (['trendPullback', 'breakRetest', 'londonBreakout', 'newsMomentum'] as $fn) {
            try {
                $c = $this->$fn();
                if ($c) $candidates[] = $c;
            } catch (Throwable $e) {
                log_engine('error', "{$this->pair} $fn: " . $e->getMessage());
            }
        }
        if (!$candidates) return null;
        usort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = $candidates[0];

        // News filter (news_momentum is allowed to trade its own released event)
        $blocking = self::$offline ? [] : News::highImpactNear($this->pair, $this->now, (int)cfg('news_block_minutes'), $best['news_event_id'] ?? null);
        if ($blocking) {
            log_engine('info', "{$this->pair} {$best['strategy']} blocked by news: {$blocking[0]['title']}");
            return null;
        }
        if ($best['score'] < cfg('min_score')) return null;
        return $best;
    }

    // ---------------------------------------------------------------- strategies

    /** 1. Trend pullback to M15 EMA20/50 zone with a rejection candle, in the H1 trend direction. */
    private function trendPullback(): ?array
    {
        $trend = $this->h1Trend();
        if ($trend['dir'] === null) return null;
        $pat = Indicators::pattern($this->m15);
        if (!$pat || $pat['dir'] !== $trend['dir']) return null;

        $i = $this->last; $c = $this->m15[$i]; $atr = $this->atr[$i];
        $e20 = $this->ema20[$i]; $e50 = $this->ema50[$i];
        if ($trend['dir'] === 'BUY') {
            $touched = $c['l'] <= $e20 + 0.2 * $atr && $c['c'] > $e50;
            $sl = min(array_column(array_slice($this->m15, -3), 'l')) - 0.3 * $atr;
        } else {
            $touched = $c['h'] >= $e20 - 0.2 * $atr && $c['c'] < $e50;
            $sl = max(array_column(array_slice($this->m15, -3), 'h')) + 0.3 * $atr;
        }
        if (!$touched) return null;

        $reasons = [
            "H1 trend {$this->word($trend['dir'])} ({$trend['label']})",
            "Pullback into M15 EMA20/50 value zone",
            "{$pat['name']} rejection candle",
        ];
        return $this->build('trend_pullback', $trend['dir'], $c['c'], $sl, $pat['quality'], $reasons, $trend);
    }

    /** 2. Break of a key level (prior-day high/low or H1 swing) followed by a retest that holds. */
    private function breakRetest(): ?array
    {
        $pat = Indicators::pattern($this->m15);
        if (!$pat) return null;
        $i = $this->last; $c = $this->m15[$i]; $atr = $this->atr[$i];
        $trend = $this->h1Trend();
        if ($trend['dir'] !== null && $trend['dir'] !== $pat['dir']) return null; // never fight H1

        foreach ($this->keyLevels() as $lvl) {
            $L = $lvl['price'];
            // the break: a close beyond L between 3 and 20 bars ago, with price having moved away
            $broke = false;
            for ($k = $i - 20; $k <= $i - 3; $k++) {
                if ($k < 1) continue;
                $prev = $this->m15[$k - 1]['c']; $cur = $this->m15[$k]['c'];
                if ($pat['dir'] === 'BUY' && $prev <= $L && $cur > $L + 0.1 * $atr) { $broke = true; break; }
                if ($pat['dir'] === 'SELL' && $prev >= $L && $cur < $L - 0.1 * $atr) { $broke = true; break; }
            }
            if (!$broke) continue;

            if ($pat['dir'] === 'BUY') {
                $retest = $c['l'] <= $L + 0.25 * $atr && $c['c'] > $L;
                $sl = min($c['l'], $L) - 0.5 * $atr;
            } else {
                $retest = $c['h'] >= $L - 0.25 * $atr && $c['c'] < $L;
                $sl = max($c['h'], $L) + 0.5 * $atr;
            }
            if (!$retest) continue;

            $reasons = [
                "Broke {$lvl['name']} at " . $this->fmt($L),
                "Retest held as " . ($pat['dir'] === 'BUY' ? 'support' : 'resistance'),
                "{$pat['name']} on the retest",
            ];
            if ($trend['dir']) $reasons[] = "Aligned with H1 trend ({$trend['label']})";
            $sig = $this->build('break_retest', $pat['dir'], $c['c'], $sl, $pat['quality'], $reasons, $trend, true);
            if ($sig) return $sig;
        }
        return null;
    }

    /** 3. London breakout of the Asian range (00:00–07:00 UTC), first clean close outside, 07:00–10:00 UTC. */
    private function londonBreakout(): ?array
    {
        $hour = (int)gmdate('G', $this->now);
        if ($hour < 7 || $hour >= 10) return null;
        $dayStart = intdiv($this->now, 86400) * 86400;
        $asian = array_values(array_filter($this->m15, fn($c) => $c['t'] >= $dayStart && $c['t'] < $dayStart + 7 * 3600));
        if (count($asian) < 20) return null;
        $hi = max(array_column($asian, 'h')); $lo = min(array_column($asian, 'l'));
        $i = $this->last; $c = $this->m15[$i]; $p = $this->m15[$i - 1]; $atr = $this->atr[$i];
        $rangeAtr = ($hi - $lo) / $atr;
        if ($rangeAtr < 1.5 || $rangeAtr > 6) return null; // too tight = fakeouts, too wide = exhausted

        // only the first breakout of the day
        $sinceOpen = array_filter($this->m15, fn($x) => $x['t'] >= $dayStart + 7 * 3600 && $x['t'] < $c['t']);
        $body = abs($c['c'] - $c['o']); $range = max($c['h'] - $c['l'], 1e-9);
        if ($body / $range < 0.5) return null;

        if ($c['c'] > $hi + 0.1 * $atr && $p['c'] <= $hi) {
            foreach ($sinceOpen as $x) if ($x['c'] > $hi) return null;
            $dir = 'BUY'; $sl = max(($hi + $lo) / 2, $c['c'] - 2 * $atr);
        } elseif ($c['c'] < $lo - 0.1 * $atr && $p['c'] >= $lo) {
            foreach ($sinceOpen as $x) if ($x['c'] < $lo) return null;
            $dir = 'SELL'; $sl = min(($hi + $lo) / 2, $c['c'] + 2 * $atr);
        } else {
            return null;
        }
        $trend = $this->h1Trend();
        if ($trend['dir'] !== null && $trend['dir'] !== $dir) return null;
        $reasons = [
            'Asian range ' . $this->fmt($lo) . ' – ' . $this->fmt($hi) . ' (' . round(($hi - $lo) / $this->pip) . ' pips)',
            'First strong M15 close ' . ($dir === 'BUY' ? 'above' : 'below') . ' the range at London open',
        ];
        if ($trend['dir']) $reasons[] = "Aligned with H1 trend ({$trend['label']})";
        return $this->build('london_breakout', $dir, $c['c'], $sl, min(1, $body / $range), $reasons, $trend, true);
    }

    /** 4. Post high-impact news momentum: impulse >= 2x pre-news ATR, then continuation without deep retrace. */
    private function newsMomentum(): ?array
    {
        if (self::$offline) return null;
        foreach (News::recentForMomentum($this->pair, $this->now) as $ev) {
            $doneKey = "momentum_{$ev['id']}_" . str_replace('/', '', $this->pair);
            if (kv_get($doneKey)) continue;
            $evTs = strtotime($ev['event_time'] . ' UTC');
            $preIdx = null;
            foreach ($this->m15 as $k => $x) if ($x['t'] < $evTs) $preIdx = $k;
            if ($preIdx === null || $preIdx + 1 > $this->last) continue;
            $preAtr = $this->atr[$preIdx];
            $impulse = $this->m15[$preIdx + 1];
            $impRange = $impulse['h'] - $impulse['l'];
            if ($impRange < 2 * $preAtr) { kv_set($doneKey, 1); continue; } // no real reaction

            $dir = $impulse['c'] > $impulse['o'] ? 'BUY' : 'SELL';
            $c = $this->m15[$this->last];
            $after = array_slice($this->m15, $preIdx + 2);
            if (!$after) continue;
            // retrace depth since the impulse
            if ($dir === 'BUY') {
                $deepest = min(array_column($after, 'l'));
                $retrace = ($impulse['h'] - $deepest) / $impRange;
                $cont = $c['c'] > $c['o'];
                $sl = min($deepest, $impulse['l'] + 0.5 * $impRange) - 0.2 * $preAtr;
            } else {
                $deepest = max(array_column($after, 'h'));
                $retrace = ($deepest - $impulse['l']) / $impRange;
                $cont = $c['c'] < $c['o'];
                $sl = max($deepest, $impulse['h'] - 0.5 * $impRange) + 0.2 * $preAtr;
            }
            if ($retrace > 0.618 || !$cont) continue; // wait for a later bar (up to 60 min)
            kv_set($doneKey, 1);

            $trend = $this->h1Trend();
            $reasons = [
                "{$ev['currency']} {$ev['title']} (high impact)",
                'News impulse ' . round($impRange / $preAtr, 1) . '× normal volatility',
                'Shallow pullback (' . round($retrace * 100) . '%) then continuation',
            ];
            $quality = min(1, 0.5 + ($impRange / $preAtr) / 8);
            $sig = $this->build('news_momentum', $dir, $c['c'], $sl, $quality, $reasons, $trend, true);
            if ($sig) { $sig['news_event_id'] = (int)$ev['id']; return $sig; }
        }
        return null;
    }

    // ---------------------------------------------------------------- scoring & helpers

    private function build(string $strategy, string $dir, float $entry, float $sl, float $quality, array $reasons, array $trend, bool $trendOptional = false): ?array
    {
        $i = $this->last; $atr = $this->atr[$i];
        $risk = abs($entry - $sl);
        if ($risk < 0.6 * $atr || $risk > 3 * $atr) return null;             // abnormal stop
        if ($dir === 'BUY' && $sl >= $entry) return null;
        if ($dir === 'SELL' && $sl <= $entry) return null;

        $tp1 = $dir === 'BUY' ? $entry + $risk : $entry - $risk;
        $tp2 = $dir === 'BUY' ? $entry + 2 * $risk : $entry - 2 * $risk;

        $score = 0;
        // Trend 25
        if ($trend['dir'] === $dir) $score += $trend['strong'] ? 25 : 17;
        elseif ($trend['dir'] === null && $trendOptional) $score += 8;
        else return null;

        // Key level 20
        $near = $this->nearestLevel($entry, $atr);
        if ($strategy === 'break_retest' || $strategy === 'london_breakout') $score += 20;
        elseif ($near) { $score += 20; $reasons[] = "At {$near['name']}"; }
        elseif ($strategy === 'trend_pullback') $score += 8; // EMA zone is a dynamic level

        // Trigger quality 15
        $score += (int)round(15 * max(0, min(1, $quality)));

        // Room to target 10 — distance to the next opposing level
        $room = $this->roomToOpposingLevel($dir, $entry);
        if ($room !== null && $room < $risk) return null;
        if ($room === null || $room >= 2 * $risk) $score += 10;
        elseif ($room >= 1.5 * $risk) $score += 6;
        else $score += 2;

        // RSI 10
        $r = $this->rsi[$i];
        if ($dir === 'BUY') $score += ($r >= 40 && $r <= 65) ? 10 : (($r > 30 && $r < 72) ? 5 : 0);
        else $score += ($r >= 35 && $r <= 60) ? 10 : (($r > 28 && $r < 70) ? 5 : 0);

        // Volatility 10
        $atrs = array_filter(array_slice($this->atr, -100), fn($v) => $v !== null);
        $avgAtr = array_sum($atrs) / max(count($atrs), 1);
        $ratio = $atr / max($avgAtr, 1e-9);
        $score += ($ratio >= 0.8 && $ratio <= 2.0) ? 10 : 4;

        // Session 10
        $h = (int)gmdate('G', $this->now);
        if ($h >= 12 && $h < 16) { $score += 10; $reasons[] = 'London–New York overlap (peak liquidity)'; }
        elseif ($h >= 7 && $h < 12) { $score += 8; $reasons[] = 'London session'; }
        else { $score += 5; $reasons[] = 'New York session'; }

        $score = min(100, $score);
        $tier = $score >= cfg('elite_score') ? 'ELITE' : ($score >= cfg('high_score') ? 'HIGH' : 'STANDARD');
        $d = price_decimals($this->pair);
        return [
            'pair' => $this->pair,
            'direction' => $dir,
            'strategy' => $strategy,
            'tier' => $tier,
            'score' => $score,
            'entry' => round($entry, $d),
            'sl' => round($sl, $d),
            'tp1' => round($tp1, $d),
            'tp2' => round($tp2, $d),
            'reasons' => $reasons,
            'created_at' => gmdate('Y-m-d H:i:s', $this->now),
        ];
    }

    /** H1 trend: price vs EMA50, EMA50 slope, EMA50 vs EMA200. */
    private function h1Trend(): array
    {
        $n = count($this->h1) - 1;
        $c = $this->h1[$n]['c'];
        $e50 = $this->h1ema50[$n] ?? null; $e50p = $this->h1ema50[$n - 5] ?? null; $e200 = $this->h1ema200[$n] ?? null;
        if ($e50 === null || $e50p === null) return ['dir' => null, 'strong' => false, 'label' => 'n/a'];
        if ($c > $e50 && $e50 > $e50p) {
            $strong = $e200 !== null && $e50 > $e200;
            return ['dir' => 'BUY', 'strong' => $strong, 'label' => $strong ? 'strong uptrend' : 'uptrend'];
        }
        if ($c < $e50 && $e50 < $e50p) {
            $strong = $e200 !== null && $e50 < $e200;
            return ['dir' => 'SELL', 'strong' => $strong, 'label' => $strong ? 'strong downtrend' : 'downtrend'];
        }
        return ['dir' => null, 'strong' => false, 'label' => 'ranging'];
    }

    /** Prior-day high/low, today's high/low so far, and recent H1 swing levels. */
    private function keyLevels(): array
    {
        if ($this->levelsCache !== null) return $this->levelsCache;
        $levels = [];
        $dayStart = intdiv($this->now, 86400) * 86400;
        $dow = (int)gmdate('N', $dayStart);
        $prevStart = $dayStart - ($dow === 1 ? 3 : 1) * 86400; // Monday -> use Friday
        $prev = array_filter($this->m15, fn($c) => $c['t'] >= $prevStart && $c['t'] < $prevStart + 86400);
        if ($prev) {
            $levels[] = ['name' => 'previous day high', 'price' => max(array_column($prev, 'h'))];
            $levels[] = ['name' => 'previous day low', 'price' => min(array_column($prev, 'l'))];
        }
        $swings = Indicators::swings(array_slice($this->h1, -120), 3);
        foreach (array_slice($swings, -8) as $s) {
            $levels[] = ['name' => $s['type'] === 'H' ? 'H1 swing high' : 'H1 swing low', 'price' => $s['price']];
        }
        return $this->levelsCache = $levels;
    }

    private function nearestLevel(float $price, float $atr): ?array
    {
        $best = null;
        foreach ($this->keyLevels() as $l) {
            $d = abs($l['price'] - $price);
            if ($d <= 0.5 * $atr && ($best === null || $d < abs($best['price'] - $price))) $best = $l;
        }
        return $best;
    }

    private function roomToOpposingLevel(string $dir, float $entry): ?float
    {
        $room = null;
        $minGap = 0.3 * $this->atr[$this->last];
        foreach ($this->keyLevels() as $l) {
            $d = $dir === 'BUY' ? $l['price'] - $entry : $entry - $l['price'];
            if ($d > $minGap && ($room === null || $d < $room)) $room = $d;
        }
        return $room;
    }

    private function word(string $dir): string { return $dir === 'BUY' ? 'bullish' : 'bearish'; }
    private function fmt(float $p): string { return number_format($p, price_decimals($this->pair), '.', ''); }
}
