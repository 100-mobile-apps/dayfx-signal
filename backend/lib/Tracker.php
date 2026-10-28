<?php
declare(strict_types=1);

/**
 * Tracks every published signal against real M15 candles. Conservative rules:
 *  - Same candle touches SL and TP1 while ACTIVE  -> counted as LOSS.
 *  - TP1 hit: half closed at +1R, stop moved to entry.
 *  - After TP1: TP2 -> WIN (+1.5R total); back to entry -> WIN (+0.5R total).
 *  - Still open at eod_close_utc -> closed at market (CLOSED_EOD / BREAKEVEN).
 * Returns list of [signal, newStatus] transitions for notifications.
 */
final class Tracker
{
    public static function update(string $pair, array $m15): array
    {
        $st = db()->prepare("SELECT * FROM signals WHERE pair = ? AND status IN ('ACTIVE','TP1') ORDER BY id");
        $st->execute([$pair]);
        $events = [];
        foreach ($st->fetchAll() as $s) {
            $ev = self::evaluate($s, $m15);
            if ($ev) $events[] = $ev;
        }
        return $events;
    }

    private static function evaluate(array $s, array $m15): ?array
    {
        [$newStatus, $pips, $mfe, $closedAt] = self::simulate($s, $m15, time());
        $changed = $newStatus !== $s['status'];
        db()->prepare('UPDATE signals SET status = ?, result_pips = ?, max_favorable_pips = ?, closed_at = ? WHERE id = ?')
            ->execute([
                $newStatus,
                $pips === null ? null : round($pips, 1),
                round($mfe, 1),
                $closedAt ? gmdate('Y-m-d H:i:s', $closedAt) : null,
                $s['id'],
            ]);
        if (!$changed) return null;
        $s['status'] = $newStatus;
        $s['result_pips'] = $pips === null ? null : round($pips, 1);
        return $s;
    }

    /** Pure replay of a signal over candles. Returns [status, pips|null, mfePips, closedAtTs|null]. */
    public static function simulate(array $s, array $m15, int $nowTs): array
    {
        $pip = pip_size($s['pair']);
        $buy = $s['direction'] === 'BUY';
        $entry = (float)$s['entry']; $sl = (float)$s['sl'];
        $tp1 = (float)$s['tp1']; $tp2 = (float)$s['tp2'];
        $R = abs($entry - $sl) / $pip;
        $start = strtotime($s['created_at'] . ' UTC');
        $cutoff = strtotime($s['closes_at'] . ' UTC');
        $status = 'ACTIVE';
        $mfe = 0.0;
        $final = null; $pips = null; $closedAt = null;

        foreach ($m15 as $c) {
            if ($c['t'] < $start) continue;
            if ($c['t'] >= $cutoff) {
                $move = ($buy ? $c['o'] - $entry : $entry - $c['o']) / $pip;
                $pips = $status === 'TP1' ? 0.5 * $R + 0.5 * $move : $move;
                $final = abs($pips) < 1 ? 'BREAKEVEN' : 'CLOSED_EOD';
                $closedAt = $c['t'];
                break;
            }
            $mfe = max($mfe, ($buy ? $c['h'] - $entry : $entry - $c['l']) / $pip);
            $hitSL = $buy ? $c['l'] <= $sl : $c['h'] >= $sl;
            $hitBE = $buy ? $c['l'] <= $entry : $c['h'] >= $entry;
            $hitTP1 = $buy ? $c['h'] >= $tp1 : $c['l'] <= $tp1;
            $hitTP2 = $buy ? $c['h'] >= $tp2 : $c['l'] <= $tp2;

            if ($status === 'ACTIVE') {
                if ($hitSL) { $final = 'LOSS'; $pips = -$R; $closedAt = $c['t'] + 900; break; }
                if ($hitTP2) { $final = 'WIN'; $pips = 1.5 * $R; $closedAt = $c['t'] + 900; break; }
                if ($hitTP1) { $status = 'TP1'; continue; }
            } else { // TP1 running, stop at entry
                if ($hitBE) { $final = 'WIN'; $pips = 0.5 * $R; $closedAt = $c['t'] + 900; break; }
                if ($hitTP2) { $final = 'WIN'; $pips = 1.5 * $R; $closedAt = $c['t'] + 900; break; }
            }
        }
        // market closed / data gap past the cutoff: settle at last price
        if ($final === null && $nowTs >= $cutoff + 1800 && $m15) {
            $exit = end($m15)['c'];
            $move = ($buy ? $exit - $entry : $entry - $exit) / $pip;
            $pips = $status === 'TP1' ? 0.5 * $R + 0.5 * $move : $move;
            $final = abs($pips) < 1 ? 'BREAKEVEN' : 'CLOSED_EOD';
            $closedAt = $nowTs;
        }
        return [$final ?? $status, $pips, $mfe, $closedAt];
    }
}
