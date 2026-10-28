<?php
// Copy to config.php and fill in. NEVER commit config.php.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_dayfx',
        'user' => 'cpaneluser_dayfx',
        'pass' => 'CHANGE_ME',
    ],

    // Free key from https://twelvedata.com (free plan: 800 credits/day, 8/min)
    'twelvedata_key' => 'CHANGE_ME',

    // Day-trading pairs (Twelve Data symbol format)
    'pairs' => ['EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'EUR/JPY', 'XAU/USD'],

    // Signals below this score are never published. Elite = 85+, High = 75+, Standard = MIN_SCORE+
    'min_score' => 70,
    'elite_score' => 85,
    'high_score' => 75,

    // Trading window (UTC). Engine only creates signals inside it; all trades are closed at 'eod_close_utc'.
    'session_start_utc' => 7,   // London open
    'session_end_utc'   => 19,  // last new signal
    'eod_close_utc'     => 21,  // force-close any open trade (day trading: no overnight holds)

    // Block new signals this many minutes before/after a HIGH impact event on either currency of the pair
    'news_block_minutes' => 30,

    // OPTIONAL. The app gets alerts through WorkManager polling, so you can leave these as they are.
    // Firebase Cloud Messaging (HTTP v1), only if you later add FCM. Service-account JSON from
    // Firebase console > Project settings > Service accounts, upload OUTSIDE public_html.
    'fcm_project_id' => 'your-firebase-project-id',
    'fcm_service_account_path' => '/home/cpaneluser/dayfx-firebase.json',

    // Email
    'mail_from' => 'signals@yourdomain.com',
    'mail_from_name' => 'DayFX Signals',

    // Protects cron.php when called over HTTP (cron via CLI does not need it)
    'cron_secret' => 'CHANGE_ME_LONG_RANDOM',

    // Simple admin dashboard login
    'admin_user' => 'admin',
    'admin_pass_hash' => '', // generate: php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"
];
