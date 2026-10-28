<?php
declare(strict_types=1);

/**
 * Economic calendar from the public Forex Factory weekly JSON feed.
 * Refreshed at most once per hour (the feed rate-limits aggressive polling).
 */
final class News
{
    private const FEED = 'https://nfs.faireconomy.media/ff_calendar_thisweek.json';

    public static function refresh(bool $force = false): void
    {
        $last = (int)kv_get('news_last_fetch', 0);
        if (!$force && time() - $last < 3600) return;
        kv_set('news_last_fetch', time());

        $rows = http_get_json(self::FEED, 20);
        if (!$rows) {
            log_engine('warn', 'News feed unavailable');
            return;
        }
        $st = db()->prepare(
            'INSERT INTO news_events (event_key, title, currency, impact, event_time, forecast, previous)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE impact = VALUES(impact), event_time = VALUES(event_time),
               forecast = VALUES(forecast), previous = VALUES(previous)'
        );
        foreach ($rows as $r) {
            if (empty($r['title']) || empty($r['date'])) continue;
            $ts = strtotime($r['date']);
            if (!$ts) continue;
            $key = sha1(($r['country'] ?? '') . '|' . $r['title'] . '|' . gmdate('Y-m-d', $ts));
            $st->execute([
                $key,
                mb_substr($r['title'], 0, 200),
                strtoupper(substr($r['country'] ?? '', 0, 5)),
                $r['impact'] ?? 'Low',
                gmdate('Y-m-d H:i:s', $ts),
                $r['forecast'] ?? null,
                $r['previous'] ?? null,
            ]);
        }
    }

    public static function currencies(string $pair): array
    {
        return explode('/', $pair);
    }

    /** High-impact events for the pair's currencies within ±$minutes of $ts. */
    public static function highImpactNear(string $pair, int $ts, int $minutes, ?int $excludeId = null): array
    {
        $cur = self::currencies($pair);
        $st = db()->prepare(
            "SELECT * FROM news_events WHERE impact = 'High' AND currency IN (?, ?)
             AND event_time BETWEEN ? AND ?"
        );
        $st->execute([
            $cur[0], $cur[1],
            gmdate('Y-m-d H:i:s', $ts - $minutes * 60),
            gmdate('Y-m-d H:i:s', $ts + $minutes * 60),
        ]);
        return array_values(array_filter($st->fetchAll(), fn($e) => (int)$e['id'] !== $excludeId));
    }

    /** High-impact events released 15–60 min ago, not yet evaluated for momentum on this pair. */
    public static function recentForMomentum(string $pair, int $ts): array
    {
        $cur = self::currencies($pair);
        $st = db()->prepare(
            "SELECT * FROM news_events WHERE impact = 'High' AND currency IN (?, ?)
             AND event_time BETWEEN ? AND ? ORDER BY event_time DESC"
        );
        $st->execute([
            $cur[0], $cur[1],
            gmdate('Y-m-d H:i:s', $ts - 3600),
            gmdate('Y-m-d H:i:s', $ts - 900),
        ]);
        return $st->fetchAll();
    }
}
