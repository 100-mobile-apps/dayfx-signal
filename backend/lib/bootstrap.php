<?php
declare(strict_types=1);
date_default_timezone_set('UTC');

function cfg(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('config.php missing — copy config.sample.php to config.php');
        }
        $config = require $path;
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function log_engine(string $level, string $msg): void
{
    try {
        db()->prepare('INSERT INTO engine_log (level, message) VALUES (?, ?)')->execute([$level, $msg]);
    } catch (Throwable $e) {
        error_log("[dayfx] $level: $msg");
    }
}

function kv_get(string $k, $default = null)
{
    $st = db()->prepare('SELECT v FROM kv_store WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function kv_set(string $k, $v): void
{
    db()->prepare('INSERT INTO kv_store (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute([$k, json_encode($v)]);
}

/** Pip size for a symbol like EUR/USD, USD/JPY, XAU/USD */
function pip_size(string $pair): float
{
    if (str_starts_with($pair, 'XAU')) return 0.1;
    return str_contains($pair, 'JPY') ? 0.01 : 0.0001;
}

function price_decimals(string $pair): int
{
    if (str_starts_with($pair, 'XAU')) return 2;
    return str_contains($pair, 'JPY') ? 3 : 5;
}

function http_get_json(string $url, int $timeout = 20): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'DayFX-Signals/1.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
