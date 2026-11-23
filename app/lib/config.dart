/// Central app configuration — edit before building.
class AppConfig {
  static const appName = 'DayFX Signals';

  /// Your cPanel API folder (no trailing slash). If .htaccess rewrites don't work on
  /// your host, use 'https://yourdomain.com/dayfx/api/index.php?r=' instead.
  static const apiBase = 'https://dayfxsignal.100apkgoals.com/api';

  static const playStoreId = 'com.swiftmynd.dayfx_signals';
  /// Numeric App Store ID assigned by App Store Connect after app creation.
  static const appStoreId = String.fromEnvironment('APP_STORE_ID');
  static const privacyPolicyUrl = 'https://dayfxsignal.100apkgoals.com/privacy.html';
  static const deletionUrl = 'https://dayfxsignal.100apkgoals.com/delete-account.html';

  /// Public folder of the backend (no trailing slash). Referral links are $siteBase/r/CODE
  static const siteBase = 'https://dayfxsignal.100apkgoals.com';
  static String referralLink(String code) => '$siteBase/r/$code';

  // Developer promo (Swiftmynd Technologies Ltd)
  static const developerName = 'Swiftmynd Technologies Ltd';
  static const whatsappNumber = '2347011401387';
  static const buyMeACoffeeUrl = 'https://buymeacoffee.com/swalafblogM';

  static String whatsappLink(String message) =>
      'https://wa.me/$whatsappNumber?text=${Uri.encodeComponent(message)}';

  static const playStoreUrl = 'https://play.google.com/store/apps/details?id=$playStoreId';
}
