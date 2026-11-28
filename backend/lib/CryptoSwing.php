<?php
declare(strict_types=1);

/**
 * BTC/ETH swing signals on 4-hour candles. Only the two rules that were profitable
 * both on Jan-Aug 2026 (tuning) and on unseen Sep-Nov 2026 data:
 *
 *  - swing_ifvg:    inverted fair value gap retest with an ADX(14) > 25 rising trend filter
 *                   (Sep-Nov: 13 trades, 77% reached TP1, +6.9R).
 *  - swing_rsi_dip: RSI(2) < 10 above the 200 EMA (> 90 below it for sells), stop 3 x ATR
 *                   (Sep-Nov: 23 trades, 65% reached TP1, +9.5R).
 *
 * TP1 = 1R (half closed, stop to entry), TP2 = 2R, closed at market after 30 candles (5 days).
 * Every function here mirrors the backtest (bt.py) so live signals match the tested rules.
 * Prices come from Binance's public market-data API (no key, no Twelve Data credits).
 */
final class CryptoSwing
{
    public const PAIRS = ['BTC/USD' => 'BTCUSDT', 'ETH/USD' => 'ETHUSDT'];
    public const HOLD_HOURS = 120; // 30 x 4h candles

    /** Closed candles [t,o,h,l,c] oldest first, t = open time (seconds). */
    public static function klines(string $symbol, string $interval, int $limit): ?array
    {
        $url = 'https://data-api.binance.vision/api/v3/klines?' . http_build_query(['symbol' => $symbol, 'interval' => $interval, 'limit' => $limit]);
        $rows = http_get_json($url, 20);
        if (!$rows || !isset($rows[0][0])) {
            log_engine('error', "CryptoSwing $symbol $interval: no data");
            return null;
        }
        $now = time(); $out = [];
        foreach ($rows as $k) {
            if ((int)($k[6] / 1000) >= $now) continue; // still forming
            $out[] = ['t' => intdiv((int)$k[0], 1000), 'o' => (float)$k[1], 'h' => (float)$k[2], 'l' => (float)$k[3], 'c' => (float)$k[4]];
        }
        return $out;
    }

    /** Signal on the most recently closed 4h candle, or null. */
    public static function signal(string $pair, array $c): ?array
    {
        $n = count($c);
        if ($n < 260) return null;
        $last = $n - 1;
        $found = self::ifvg($c, $last) ?? self::rsiDip($c, $last);
        if (!$found) return null;
        [$strategy, $dir, $stop, $score, $reasons] = $found;
        $entry = $c[$last]['c'];
        $risk = abs($entry - $stop);
        if ($risk <= 0) return null;
        $d = price_decimals($pair);
        $buy = $dir === 'BUY';
        return [
            'pair' => $pair, 'direction' => $dir, 'strategy' => $strategy,
            'tier' => $score >= (int)cfg('elite_score') ? 'ELITE' : ($score >= (int)cfg('high_score') ? 'HIGH' : 'STANDARD'),
            'score' => $score,
            'entry' => round($entry, $d), 'sl' => round($stop, $d),
            'tp1' => round($buy ? $entry + $risk : $entry - $risk, $d),
            'tp2' => round($buy ? $entry + 2 * $risk : $entry - 2 * $risk, $d),
            'reasons' => $reasons,
            'created_at' => gmdate('Y-m-d H:i:s', $c[$last]['t'] + 4 * 3600),
        ];
    }

    private static function ifvg(array $c, int $want_i): ?array
    {
        $a = self::atr($c); [$adx, $pdi, $mdi] = self::dmi($c); $gaps = [];
        for ($i = 2; $i <= $want_i; $i++) {
            if ($a[$i] === null) continue;
            if ($c[$i]['l'] > $c[$i - 2]['h'] && $c[$i]['l'] - $c[$i - 2]['h'] >= 0.3 * $a[$i]) $gaps[] = ['lo' => $c[$i - 2]['h'], 'hi' => $c[$i]['l'], 'kind' => 'bull', 'inv' => null, 'used' => false];
            if ($c[$i]['h'] < $c[$i - 2]['l'] && $c[$i - 2]['l'] - $c[$i]['h'] >= 0.3 * $a[$i]) $gaps[] = ['lo' => $c[$i]['h'], 'hi' => $c[$i - 2]['l'], 'kind' => 'bear', 'inv' => null, 'used' => false];
            $gaps = array_slice($gaps, -60);
            foreach ($gaps as &$g) {
                if ($g['inv'] !== null) continue;
                if ($g['kind'] === 'bear' && $c[$i]['c'] > $g['hi']) { $g['inv'] = $i; $g['dir'] = 1; }
                elseif ($g['kind'] === 'bull' && $c[$i]['c'] < $g['lo']) { $g['inv'] = $i; $g['dir'] = -1; }
            }
            unset($g);
            if ($adx[$i] === null || $adx[$i - 1] === null || $adx[$i] <= 25 || $adx[$i] <= $adx[$i - 1]) continue;
            $want = $pdi[$i] > $mdi[$i] ? 1 : -1;
            for ($k = count($gaps) - 1; $k >= 0; $k--) {
                $g = $gaps[$k];
                if ($g['inv'] === null || $g['used'] || $g['dir'] !== $want || $i - $g['inv'] > 50 || $i === $g['inv']) continue;
                if ($want === 1 && $c[$i]['l'] <= $g['hi'] && $c[$i]['c'] > $g['hi']) {
                    $stop = $g['lo'] - 0.1 * $a[$i]; $risk = $c[$i]['c'] - $stop;
                    if ($risk >= 0.5 * $a[$i] && $risk <= 2 * $a[$i]) {
                        $gaps[$k]['used'] = true;
                        if ($i === $want_i) return ['swing_ifvg', 'BUY', $stop, 86, ['4H swing trade', 'Retest of an inverted fair value gap', sprintf('Strong uptrend (ADX %.0f and rising)', $adx[$i]), 'Holds up to 5 days']];
                        break;
                    }
                }
                if ($want === -1 && $c[$i]['h'] >= $g['lo'] && $c[$i]['c'] < $g['lo']) {
                    $stop = $g['hi'] + 0.1 * $a[$i]; $risk = $stop - $c[$i]['c'];
                    if ($risk >= 0.5 * $a[$i] && $risk <= 2 * $a[$i]) {
                        $gaps[$k]['used'] = true;
                        if ($i === $want_i) return ['swing_ifvg', 'SELL', $stop, 86, ['4H swing trade', 'Retest of an inverted fair value gap', sprintf('Strong downtrend (ADX %.0f and rising)', $adx[$i]), 'Holds up to 5 days']];
                        break;
                    }
                }
            }
        }
        return null;
    }

    private static function rsiDip(array $c, int $i): ?array
    {
        $cl = array_column($c, 'c'); $e200 = self::ema($cl, 200); $r = self::rsi($cl, 2); $a = self::atr($c);
        if ($a[$i] === null || $r[$i] === null || $e200[$i] === null) return null;
        if ($cl[$i] > $e200[$i] && $r[$i] < 10) return ['swing_rsi_dip', 'BUY', $cl[$i] - 3 * $a[$i], 78, ['4H swing trade', 'Sharp dip inside an uptrend (above the 200 EMA)', sprintf('RSI(2) oversold at %.0f', $r[$i]), 'Holds up to 5 days']];
        if ($cl[$i] < $e200[$i] && $r[$i] > 90) return ['swing_rsi_dip', 'SELL', $cl[$i] + 3 * $a[$i], 78, ['4H swing trade', 'Sharp rally inside a downtrend (below the 200 EMA)', sprintf('RSI(2) overbought at %.0f', $r[$i]), 'Holds up to 5 days']];
        return null;
    }

    // ---- indicators (identical maths to the backtest) ----
    private static function ema(array $v, int $p): array
    {
        $n = count($v); $out = array_fill(0, $n, null);
        if ($n < $p) return $out;
        $k = 2 / ($p + 1); $e = array_sum(array_slice($v, 0, $p)) / $p; $out[$p - 1] = $e;
        for ($i = $p; $i < $n; $i++) { $e = ($v[$i] - $e) * $k + $e; $out[$i] = $e; }
        return $out;
    }

    private static function atr(array $c, int $p = 14): array
    {
        $n = count($c); $out = array_fill(0, $n, null); $a = null;
        for ($i = 1; $i < $n; $i++) {
            $tr = max($c[$i]['h'] - $c[$i]['l'], abs($c[$i]['h'] - $c[$i - 1]['c']), abs($c[$i]['l'] - $c[$i - 1]['c']));
            $a = $a === null ? $tr : $a + ($tr - $a) / $p;
            if ($i >= $p) $out[$i] = $a;
        }
        return $out;
    }

    private static function rsi(array $v, int $p): array
    {
        $n = count($v); $out = array_fill(0, $n, null); $g = $l = 0.0;
        for ($i = 1; $i < $n; $i++) {
            $d = $v[$i] - $v[$i - 1]; $up = max($d, 0); $dn = max(-$d, 0);
            if ($i <= $p) { $g += $up / $p; $l += $dn / $p; }
            else { $g = ($g * ($p - 1) + $up) / $p; $l = ($l * ($p - 1) + $dn) / $p; }
            if ($i >= $p) $out[$i] = $l ? 100 - 100 / (1 + $g / $l) : 100;
        }
        return $out;
    }

    private static function dmi(array $c, int $p = 14): array
    {
        $n = count($c); $adx = $pdi = $mdi = array_fill(0, $n, null);
        $trs = $pdm = $mdm = 0.0; $dxs = []; $a = null;
        for ($i = 1; $i < $n; $i++) {
            $up = $c[$i]['h'] - $c[$i - 1]['h']; $dn = $c[$i - 1]['l'] - $c[$i]['l'];
            $tr = max($c[$i]['h'] - $c[$i]['l'], abs($c[$i]['h'] - $c[$i - 1]['c']), abs($c[$i]['l'] - $c[$i - 1]['c']));
            $pm = ($up > $dn && $up > 0) ? $up : 0; $mm = ($dn > $up && $dn > 0) ? $dn : 0;
            if ($i <= $p) { $trs += $tr; $pdm += $pm; $mdm += $mm; }
            else { $trs = $trs - $trs / $p + $tr; $pdm = $pdm - $pdm / $p + $pm; $mdm = $mdm - $mdm / $p + $mm; }
            if ($i >= $p && $trs) {
                $P = 100 * $pdm / $trs; $M = 100 * $mdm / $trs; $pdi[$i] = $P; $mdi[$i] = $M;
                $dx = ($P + $M) ? 100 * abs($P - $M) / ($P + $M) : 0;
                if ($a === null) { $dxs[] = $dx; if (count($dxs) === $p) $a = array_sum($dxs) / $p; }
                else $a = ($a * ($p - 1) + $dx) / $p;
                $adx[$i] = $a;
            }
        }
        return [$adx, $pdi, $mdi];
    }
}
