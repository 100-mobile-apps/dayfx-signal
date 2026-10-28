<?php
declare(strict_types=1);

/** Twelve Data free-tier client. 1 request per pair per cron run (~420 credits/day). */
final class MarketData
{
    /** Returns CLOSED M15 candles, oldest-first, or null on failure. */
    public static function m15(string $pair, int $bars = 1000): ?array
    {
        $url = 'https://api.twelvedata.com/time_series?' . http_build_query([
            'symbol' => $pair,
            'interval' => '15min',
            'outputsize' => $bars,
            'timezone' => 'UTC',
            'order' => 'ASC',
            'apikey' => cfg('twelvedata_key'),
        ]);
        $data = http_get_json($url, 30);
        if (!$data || ($data['status'] ?? '') !== 'ok' || empty($data['values'])) {
            log_engine('error', "MarketData $pair: " . substr(json_encode($data), 0, 300));
            return null;
        }
        $now = time();
        $out = [];
        foreach ($data['values'] as $v) {
            $t = strtotime($v['datetime'] . ' UTC');
            if ($t + 900 > $now) continue; // still forming — never trade an open candle
            $out[] = [
                't' => $t,
                'o' => (float)$v['open'],
                'h' => (float)$v['high'],
                'l' => (float)$v['low'],
                'c' => (float)$v['close'],
            ];
        }
        usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
        return $out;
    }
}
