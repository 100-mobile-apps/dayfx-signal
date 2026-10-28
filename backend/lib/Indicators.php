<?php
declare(strict_types=1);

/**
 * Candle arrays are oldest-first: ['t'=>unix,'o'=>,'h'=>,'l'=>,'c'=>]
 */
final class Indicators
{
    public static function ema(array $values, int $period): array
    {
        $out = [];
        $k = 2 / ($period + 1);
        $prev = null;
        foreach ($values as $i => $v) {
            if ($i < $period - 1) { $out[$i] = null; continue; }
            if ($prev === null) {
                $prev = array_sum(array_slice($values, $i - $period + 1, $period)) / $period;
            } else {
                $prev = $v * $k + $prev * (1 - $k);
            }
            $out[$i] = $prev;
        }
        return $out;
    }

    public static function rsi(array $closes, int $period = 14): array
    {
        $out = array_fill(0, count($closes), null);
        if (count($closes) <= $period) return $out;
        $gain = 0; $loss = 0;
        for ($i = 1; $i <= $period; $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $gain += max($d, 0); $loss += max(-$d, 0);
        }
        $avgG = $gain / $period; $avgL = $loss / $period;
        $out[$period] = $avgL == 0 ? 100 : 100 - 100 / (1 + $avgG / $avgL);
        for ($i = $period + 1; $i < count($closes); $i++) {
            $d = $closes[$i] - $closes[$i - 1];
            $avgG = ($avgG * ($period - 1) + max($d, 0)) / $period;
            $avgL = ($avgL * ($period - 1) + max(-$d, 0)) / $period;
            $out[$i] = $avgL == 0 ? 100 : 100 - 100 / (1 + $avgG / $avgL);
        }
        return $out;
    }

    public static function atr(array $candles, int $period = 14): array
    {
        $tr = [];
        foreach ($candles as $i => $c) {
            if ($i === 0) { $tr[] = $c['h'] - $c['l']; continue; }
            $pc = $candles[$i - 1]['c'];
            $tr[] = max($c['h'] - $c['l'], abs($c['h'] - $pc), abs($c['l'] - $pc));
        }
        $out = array_fill(0, count($candles), null);
        if (count($tr) < $period) return $out;
        $prev = array_sum(array_slice($tr, 0, $period)) / $period;
        $out[$period - 1] = $prev;
        for ($i = $period; $i < count($tr); $i++) {
            $prev = ($prev * ($period - 1) + $tr[$i]) / $period;
            $out[$i] = $prev;
        }
        return $out;
    }

    /** Aggregate M15 candles into H1 candles aligned to the clock hour. */
    public static function toH1(array $m15): array
    {
        $out = [];
        foreach ($m15 as $c) {
            $hour = intdiv($c['t'], 3600) * 3600;
            if (!isset($out[$hour])) {
                $out[$hour] = ['t' => $hour, 'o' => $c['o'], 'h' => $c['h'], 'l' => $c['l'], 'c' => $c['c'], 'n' => 1];
            } else {
                $out[$hour]['h'] = max($out[$hour]['h'], $c['h']);
                $out[$hour]['l'] = min($out[$hour]['l'], $c['l']);
                $out[$hour]['c'] = $c['c'];
                $out[$hour]['n']++;
            }
        }
        return array_values($out);
    }

    /** Swing highs/lows (fractal with $n bars each side). Returns [['i'=>,'price'=>,'type'=>'H'|'L']] */
    public static function swings(array $candles, int $n = 3): array
    {
        $res = [];
        $cnt = count($candles);
        for ($i = $n; $i < $cnt - $n; $i++) {
            $isH = true; $isL = true;
            for ($j = 1; $j <= $n; $j++) {
                if ($candles[$i]['h'] <= $candles[$i - $j]['h'] || $candles[$i]['h'] <= $candles[$i + $j]['h']) $isH = false;
                if ($candles[$i]['l'] >= $candles[$i - $j]['l'] || $candles[$i]['l'] >= $candles[$i + $j]['l']) $isL = false;
            }
            if ($isH) $res[] = ['i' => $i, 'price' => $candles[$i]['h'], 'type' => 'H'];
            if ($isL) $res[] = ['i' => $i, 'price' => $candles[$i]['l'], 'type' => 'L'];
        }
        return $res;
    }

    /**
     * Price-action candle pattern on the last closed candle.
     * Returns ['name'=>..., 'dir'=>'BUY'|'SELL', 'quality'=>0..1] or null.
     */
    public static function pattern(array $candles): ?array
    {
        $n = count($candles);
        if ($n < 3) return null;
        $c = $candles[$n - 1];
        $p = $candles[$n - 2];
        $range = $c['h'] - $c['l'];
        if ($range <= 0) return null;
        $body = abs($c['c'] - $c['o']);
        $upper = $c['h'] - max($c['o'], $c['c']);
        $lower = min($c['o'], $c['c']) - $c['l'];

        // Bullish engulfing
        if ($c['c'] > $c['o'] && $p['c'] < $p['o'] && $c['c'] >= $p['o'] && $c['o'] <= $p['c'] && $body > abs($p['c'] - $p['o'])) {
            return ['name' => 'Bullish engulfing', 'dir' => 'BUY', 'quality' => min(1, 0.6 + $body / $range * 0.4)];
        }
        // Bearish engulfing
        if ($c['c'] < $c['o'] && $p['c'] > $p['o'] && $c['c'] <= $p['o'] && $c['o'] >= $p['c'] && $body > abs($p['c'] - $p['o'])) {
            return ['name' => 'Bearish engulfing', 'dir' => 'SELL', 'quality' => min(1, 0.6 + $body / $range * 0.4)];
        }
        // Bullish pin bar (hammer): long lower wick, close in upper third
        if ($lower >= 2 * $body && $lower >= 0.6 * $range && ($c['c'] - $c['l']) / $range >= 0.66) {
            return ['name' => 'Bullish pin bar', 'dir' => 'BUY', 'quality' => min(1, $lower / $range)];
        }
        // Bearish pin bar (shooting star)
        if ($upper >= 2 * $body && $upper >= 0.6 * $range && ($c['h'] - $c['c']) / $range >= 0.66) {
            return ['name' => 'Bearish pin bar', 'dir' => 'SELL', 'quality' => min(1, $upper / $range)];
        }
        return null;
    }
}
