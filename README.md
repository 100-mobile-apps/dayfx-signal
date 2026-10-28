# DayFX Signals — Forex Day-Trading Signals (Flutter + cPanel)

Free forex day-trading signal app. Price action + news strategies, Elite mode, live-tracked results,
push (WorkManager) + email alerts, auth, and the Swiftmynd developer promo. Backend runs on any cPanel host (PHP 8.1+, MySQL).

```
dayfx/
├── backend/            → upload to cPanel (e.g. public_html/dayfx)
│   ├── api/            REST API used by the app
│   ├── lib/            Engine, indicators, news, tracker, notifications
│   ├── cron.php        runs every 15 min: settles trades + finds new setups
│   ├── backtest.php    test the strategies on ~50 days of real data
│   ├── admin.php       simple dashboard (engine status, signals, win rate, logs)
│   ├── privacy.html    privacy policy (required by Google Play)
│   ├── delete-account.html  public account deletion requests
│   └── schema.sql
└── app/                → Flutter project (lib/, assets/, android/, ios/)
```

---

## How the signals work

**Forex day trading only.** M15 entries, H1 trend filter. Pairs: EUR/USD, GBP/USD, USD/JPY, AUD/USD, USD/CAD, EUR/JPY, XAU/USD (edit in `config.php`).
New signals only 07:00–19:00 UTC (London + New York). Every trade is force-closed at 21:00 UTC — no overnight holds.

| Strategy | Setup |
|---|---|
| Trend Pullback | H1 trend (EMA50 slope + EMA200) → pullback into M15 EMA20/50 zone → pin bar / engulfing rejection |
| Break & Retest | Close through previous-day high/low or H1 swing level → retest holds → rejection candle |
| London Breakout | Asian range (00–07 UTC) of sane size → first strong M15 close outside it, 07–10 UTC, with H1 trend |
| News Momentum | High-impact release → impulse ≥ 2× normal volatility → shallow pullback (< 61.8%) → continuation |

**News protection:** no new signals 30 min either side of a high-impact event on either currency of the pair (Forex Factory calendar feed).

**Confluence score (0–100):** H1 trend 25 · key level 20 · candle quality 15 · room to target 10 · RSI 10 · volatility 10 · session 10.
`ELITE` ≥ 85 · `HIGH` ≥ 75 · `STANDARD` ≥ 70 (nothing below 70 is published).

**Trade plan:** SL beyond structure (0.6–3 ATR), TP1 = 1R, TP2 = 2R. Close half at TP1, stop to entry.
**Limits:** one open trade per pair, 60-min cooldown, max 3 signals per pair per day.

**How results are counted (shown in the app):** TP1 hit = win. SL before TP1 = loss. Same candle hits both = loss (conservative). Mid-market prices, no spread.

> About the 95–99% target: no rule set can guarantee that, and Google Play rejects apps that promise guaranteed returns.
> The app instead shows the **real tracked win rate**. Use `backtest.php` + the live tracker to tune `elite_score`
> upward until Elite mode's real win rate is where you want it — then you can honestly market the number you've earned.

---

## 1. Backend (cPanel)

1. **Upload** the current `backend/` folder to `public_html/dayfx/`. If you deploy an archive instead, recreate it from this folder so it includes `delete-account.html` and the latest API; the pre-existing `backend.zip` is not updated automatically.
2. **Database:** cPanel › MySQL Databases → create DB + user (all privileges). phpMyAdmin → Import `schema.sql`.
3. **Config:** copy `config.sample.php` → `config.php` and fill in:
   - DB name/user/password
   - `twelvedata_key` — free at twelvedata.com (free plan = 800 credits/day; the app uses ~450/day)
   - `mail_from` — create this mailbox in cPanel › Email Accounts
   - `cron_secret` and admin login (`php -r "echo password_hash('yourpass', PASSWORD_DEFAULT);"` — or use any online bcrypt generator)
4. **Push:** nothing to set up on the server. The app gets alerts through WorkManager (it polls your API), so no Firebase account is needed.
   (The `fcm_*` lines in `config.php` are optional and can stay as they are.)
5. **Cron job:** cPanel › Cron Jobs → add:
   ```
   1,16,31,46 * * * *   /usr/local/bin/php /home/USER/public_html/dayfx/cron.php >/dev/null 2>&1
   ```
6. **Check:** open `https://yourdomain.com/dayfx/admin.php` — the Engine time should turn green within 15 minutes (on a trading day).
   Test the API: `https://yourdomain.com/dayfx/api/stats` should return `{"error":"Unauthorized"}` (that means routing works).
   If you get a 404 instead, your host doesn't do rewrites — use `https://yourdomain.com/dayfx/api/index.php?r=` as `apiBase` in the app.

### Backtest before launch (recommended)
cPanel › Terminal (or SSH):
```
cd ~/public_html/dayfx
php backtest.php              # all pairs, ~50 days
php backtest.php --min=85     # Elite only
```
It prints win rate and pips per tier, strategy and pair. Raise `min_score` / `elite_score` in `config.php` if you want fewer, stronger signals. (News strategies are skipped in backtests.)

---

## 2. Flutter app

Requires Flutter 3.27+ (Dart 3.6+). The Android and iOS projects are included; **do not rerun `flutter create`**. Run these commands from `app/`:

```bash
flutter pub get
flutter analyze
flutter test
flutter run
```

`lib/config.dart` uses `https://dayfxsignal.bulksend.top/api` as the API base. Its `privacyPolicyUrl` and `deletionUrl` still contain example domains: set them to verified live HTTPS pages before publishing. If API rewrites are unavailable, use the `index.php?r=` URL documented above. Increment the build number in `pubspec.yaml` for each Play submission.

### Android release signing and builds

Android uses `com.swiftmynd.dayfx_signals`, compile SDK from Flutter (35 or later), Flutter's current minimum SDK (24 with Flutter 3.41), and Java core-library desugaring for local notifications. `android/app/build.gradle.kts` never signs a release with the debug key. A missing upload key causes release tasks to fail rather than producing an unsigned or debug-signed store artifact.

1. Enroll in **Play App Signing** in Play Console. Create and privately back up an **upload key** (or use the established upload key for this same package if the app already exists). For a new key, run `keytool -genkeypair -v -keystore C:/secure/dayfx-upload.jks -alias dayfx-upload -keyalg RSA -keysize 2048 -validity 10000` and securely store its passwords. Do not commit or upload this file to the web server.
   This workspace now has a generated upload key at `app/android/dayfx-upload.jks` and its local credentials at `app/android/key.properties`. Back up both securely; do not regenerate or replace this key after registering its certificate with Play Console.
2. Create `app/android/key.properties` locally (it and `*.jks` are ignored by Git). File paths are relative to `app/android/` unless absolute:
   ```properties
   storeFile=C:/secure/dayfx-upload.jks
   storePassword=YOUR_PRIVATE_STORE_PASSWORD
   keyAlias=dayfx-upload
   keyPassword=YOUR_PRIVATE_KEY_PASSWORD
   ```
3. From `app/`, run `flutter build appbundle --release` and `flutter build apk --release`. Upload `build/app/outputs/bundle/release/app-release.aab` to Play Console; the installable APK is `build/app/outputs/flutter-apk/app-release.apk`. Check the APK signer with `apksigner verify --print-certs build/app/outputs/flutter-apk/app-release.apk` and compare its SHA-256 certificate fingerprint to your upload key (`keytool -list -v -keystore C:/secure/dayfx-upload.jks -alias dayfx-upload`). The certificate must not be the Android debug certificate. Play signs distributed APKs with its **app signing key**, distinct from your upload key.

The launcher assets are generated from `app/assets/icon.png` and `icon_foreground.png` for Android adaptive icons and iOS opaque App Store icons. If the artwork changes, run `dart run flutter_launcher_icons` and commit the resulting native icon assets.

### iOS archive

On macOS with Xcode and CocoaPods installed, run `flutter pub get`, then open `ios/Runner.xcworkspace` in Xcode. Set the Apple Developer Team and configure automatic signing or a distribution provisioning profile for the bundle ID `com.swiftmynd.dayfxSignals` (change it in all Runner build configurations if your registered ID differs). Set App Store Connect release details and use `flutter build ipa --release` to export `build/ios/ipa/*.ipa` for App Store Connect / TestFlight. Apple distribution certificates and profiles must stay outside source control. When App Store Connect assigns a numeric Apple app ID, pass `--dart-define=APP_STORE_ID=YOUR_NUMERIC_ID` for the iOS store-listing fallback. iOS archiving and signing require a Mac and cannot be validated on Windows.

### How alerts work (WorkManager)
- Android runs the background job about **every 15 minutes** (its minimum), only when there is internet, between 06:00 and 22:00 UTC on trading days.
- Each run asks the API for live + recent signals and shows a notification for **new signals** and for **TP1 / win / loss / closed** changes. It respects Elite mode and the "wins & losses" switch.
- The first run after login only records what already exists, so new users aren't spammed with old signals.
- While the app is open it checks every 2 minutes instead.
- **Timing:** alerts can be 5-20+ minutes late, since Android batches background work. Many phones (Tecno, Infinix, Xiaomi, Samsung) kill background work to save battery. The Settings screen tells users to set battery to *Unrestricted*. Put the same tip in your Play listing and WhatsApp group.
- **iOS:** background polling is not enabled; alerts only show while the app is open. Email alerts still work.
- If you ever need near-instant alerts, FCM can be added alongside this later; the backend code for it is still there.

### Architecture

`app/lib/main.dart` selects onboarding, authentication, risk acceptance, or the five-tab home shell based on `services/app_state.dart` (Provider + SharedPreferences). `services/api.dart` is the authenticated HTTP client; `models.dart` decodes API data; `screens/` and `widgets/` render signals, news, tracked results, sizing tools and settings. `services/push.dart` polls for signal/status changes and sends local alerts (Android WorkManager in the background, foreground polling on both platforms). The PHP API in `backend/api/index.php` handles accounts and market data. `backend/cron.php` calls the news/market/strategy/tracking classes in `backend/lib/` every 15 minutes and writes shared MySQL signal data; `backend/admin.php` and `backend/backtest.php` are operational tools. No broker trading or user-owned positions are stored.

### What's in the app
- Onboarding (4 screens) → sign up / log in / reset password by email code → risk disclaimer acceptance
- **Signals:** Live + History, Elite filter, session strip, weekly win-rate banner, auto-refresh, infinite scroll
- **Signal detail:** tap-to-copy levels, "why this signal" confluences, built-in lot size calculator, timeline, share
- **News:** high/medium impact calendar in local time, next red-folder countdown, news-protection explainer
- **Results:** today / week / month / all, Elite toggle, win rate, pips, 30-day bar chart, by strategy and by tier
- **Tools:** lot size calculator (live pip values), market sessions in local time, prices
- **Settings:** Elite mode, push signals, push results, email alerts, developer offers toggle, rate/share/chat/coffee/hire,
  disclaimer, privacy policy, logout, **delete account** (Play Store requirement)
- **Developer promo pop-up:** not before the 3rd launch, max once per 4 launches and once per 3 days, only on the Signals tab, can be turned off

---

## 3. Google Play listing (compliant copy)

**Title:** DayFX: Forex Day Trading Signals
**Short description:** Free forex day trading signals with price action, news alerts & tracked results.

**Full description (starter):**
Free forex day trading signals for EUR/USD, GBP/USD, USD/JPY, gold and more — built on price action and news.
• Intraday setups only: trend pullbacks, break & retest, London breakout and post-news momentum
• Elite mode: only the highest-confluence 85+ setups
• Every signal tracked live — see the real win rate, pips and history
• News calendar with red-folder countdown; signals pause around high-impact news
• Entry, stop loss and two take profits, with a built-in lot size calculator
• Push and email alerts
Signals are for education only, not financial advice. Trading involves a high risk of loss.

**Avoid** in the listing, screenshots and app text: "guaranteed", "95–99% accuracy", "risk-free profits", "get rich".
Policy references: Google Play *Financial Services* and *Misleading Claims* policies.

**Data safety form:** disclose name and email for account management, plus any data your deployed host actually logs or retains. The current mobile app does not send Firebase device tokens or collect a device ID. It uses HTTPS for API traffic; do not publish the example URL as-is. Review the deployed backend, backups, logs and third-party integrations before finalizing the form.

### Account deletion URL for Play Console

After uploading the complete `backend/` folder, open `https://YOUR_DOMAIN/dayfx/delete-account.html` from a browser **without signing in**. It must load over HTTPS, identify DayFX Signals and Swiftmynd Technologies Ltd, and open a working WhatsApp request to the published support number. A signed-in user can instead delete immediately from Settings; the backend removes the account, sessions and any account-linked device tokens. The public page is a manual deletion-request channel, not an anonymous API that deletes accounts without ownership verification. Respond to and complete verified requests through your support process.

In Play Console, enter the exact live HTTPS URL under **App content > Data safety > Account deletion > Deletion URL** (wording may vary by console version). Set `deletionUrl` and `privacyPolicyUrl` in `app/lib/config.dart` to the same deployed host. Check hosting backups and web access-log retention with your provider and publish the actual retention period/policy on the deletion and privacy pages before submitting; this repository cannot determine your host's schedule. Ensure manual requests also remove any retained account-related data under that policy. Verify the link and deletion flow on both platforms before submission.
