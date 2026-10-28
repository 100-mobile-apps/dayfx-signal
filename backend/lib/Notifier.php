<?php
declare(strict_types=1);

/**
 * Push via Firebase Cloud Messaging HTTP v1 (topics) + email via cPanel mail().
 * App subscribes to topics: "signals_all", "signals_elite", "results".
 */
final class Notifier
{
    public static function newSignal(array $s): void
    {
        $title = "{$s['direction']} {$s['pair']} · {$s['tier']} ({$s['score']}/100)";
        $body = "Entry {$s['entry']}  SL {$s['sl']}  TP1 {$s['tp1']}  TP2 {$s['tp2']}";
        $data = ['type' => 'signal', 'signal_id' => (string)$s['id']];
        self::push('signals_all', $title, $body, $data);
        if ($s['tier'] === 'ELITE') self::push('signals_elite', $title, $body, $data);

        $html = self::emailHtml($title, [
            'Strategy' => self::strategyName($s['strategy']),
            'Entry' => $s['entry'], 'Stop loss' => $s['sl'],
            'Take profit 1' => $s['tp1'], 'Take profit 2' => $s['tp2'],
            'Why' => implode(' · ', $s['reasons']),
        ]);
        $eliteOnly = $s['tier'] === 'ELITE' ? '' : ' AND elite_only = 0';
        self::emailUsers("New signal: $title", $html, "email_alerts = 1$eliteOnly");
    }

    public static function statusChange(array $s): void
    {
        $label = [
            'TP1' => 'TP1 hit ✅ — stop moved to entry',
            'WIN' => 'WIN ✅',
            'LOSS' => 'Stopped out ❌',
            'BREAKEVEN' => 'Closed at breakeven',
            'CLOSED_EOD' => 'Closed at end of day',
        ][$s['status']] ?? $s['status'];
        $pips = $s['result_pips'] !== null ? sprintf(' (%+.1f pips)', $s['result_pips']) : '';
        $title = "{$s['direction']} {$s['pair']}: $label";
        $body = "Signal #{$s['id']}$pips";
        self::push('results', $title, $body, ['type' => 'result', 'signal_id' => (string)$s['id']]);
        if ($s['status'] !== 'TP1') {
            self::emailUsers("Result: $title$pips", self::emailHtml($title . $pips, [
                'Entry' => $s['entry'], 'Stop loss' => $s['sl'], 'TP2' => $s['tp2'],
            ]), 'email_alerts = 1');
        }
    }

    public static function strategyName(string $k): string
    {
        return [
            'trend_pullback' => 'Trend Pullback',
            'break_retest' => 'Break & Retest',
            'london_breakout' => 'London Breakout',
            'news_momentum' => 'News Momentum',
        ][$k] ?? $k;
    }

    // ------------------------------------------------------------- FCM

    public static function push(string $topic, string $title, string $body, array $data = []): void
    {
        $token = self::accessToken();
        if (!$token) return;
        $payload = ['message' => [
            'topic' => $topic,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $data,
            'android' => ['priority' => 'high', 'notification' => ['sound' => 'default']],
        ]];
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . cfg('fcm_project_id') . '/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) log_engine('error', "FCM $topic $code: " . substr((string)$res, 0, 300));
    }

    private static function accessToken(): ?string
    {
        $cached = kv_get('fcm_token');
        if ($cached && $cached['exp'] > time() + 60) return $cached['token'];
        $path = cfg('fcm_service_account_path');
        if (!$path || !is_file($path)) return null; // FCM is optional: the app polls via WorkManager
        $sa = json_decode((string)file_get_contents($path), true);
        $now = time();
        $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $sa['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $sig = '';
        openssl_sign("$head.$claims", $sig, $sa['private_key'], 'sha256WithRSAEncryption');
        $jwt = "$head.$claims." . $b64($sig);

        $ch = curl_init($sa['token_uri']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
        ]);
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($res['access_token'])) { log_engine('error', 'FCM auth failed'); return null; }
        kv_set('fcm_token', ['token' => $res['access_token'], 'exp' => $now + (int)$res['expires_in']]);
        return $res['access_token'];
    }

    // ------------------------------------------------------------- Email

    public static function sendMail(string $to, string $subject, string $html, string $bcc = ''): bool
    {
        $from = cfg('mail_from');
        $name = cfg('mail_from_name');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            "From: $name <$from>",
            "Reply-To: $from",
        ];
        if ($bcc) $headers[] = "Bcc: $bcc";
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers), "-f$from");
    }

    private static function emailUsers(string $subject, string $html, string $where): void
    {
        $emails = db()->query("SELECT email FROM users WHERE $where")->fetchAll(PDO::FETCH_COLUMN);
        foreach (array_chunk($emails, 40) as $chunk) {
            self::sendMail(cfg('mail_from'), $subject, $html, implode(',', $chunk));
        }
    }

    public static function emailHtml(string $title, array $rows): string
    {
        $tr = '';
        foreach ($rows as $k => $v) {
            $tr .= '<tr><td style="padding:6px 12px;color:#8A94A6">' . htmlspecialchars((string)$k) .
                '</td><td style="padding:6px 12px;color:#E8ECF4;font-weight:600">' . htmlspecialchars((string)$v) . '</td></tr>';
        }
        $t = htmlspecialchars($title);
        return <<<HTML
<div style="background:#0B0F17;padding:24px;font-family:Arial,sans-serif">
  <div style="max-width:520px;margin:auto;background:#121826;border-radius:14px;padding:20px;border:1px solid #1F2738">
    <div style="color:#22C55E;font-size:12px;letter-spacing:1px">DAYFX SIGNALS</div>
    <h2 style="color:#E8ECF4;margin:8px 0 16px">$t</h2>
    <table style="width:100%;border-collapse:collapse">$tr</table>
    <p style="color:#5B6578;font-size:11px;margin-top:18px">Trading forex carries a high risk of loss. Signals are educational, not financial advice. Past results do not guarantee future results.</p>
  </div>
</div>
HTML;
    }
}
