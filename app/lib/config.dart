/// Central app configuration — edit before building.
class AppConfig {
  static const appName = 'DayFX Signals';

  /// Your cPanel API folder (no trailing slash). If .htaccess rewrites don't work on
  /// your host, use 'https://yourdomain.com/dayfx/api/index.php?r=' instead.
  static const apiBase = 'https://dayfxsignal.bulksend.top/api';

  static const playStoreId = 'com.swiftmynd.dayfx_signals';
  /// Numeric App Store ID assigned by App Store Connect after app creation.
  static const appStoreId = String.fromEnvironment('APP_STORE_ID');
  static const privacyPolicyUrl = 'https://yourdomain.com/dayfx/privacy.html';
  static const deletionUrl = 'https://yourdomain.com/dayfx/delete-account.html';

  // Developer promo (Swiftmynd Technologies Ltd)
  static const developerName = 'Swiftmynd Technologies Ltd';
  static const whatsappNumber = '2347011401387';
  static const buyMeACoffeeUrl = 'https://buymeacoffee.com/swalafblogM';

  static String whatsappLink(String message) =>
      'https://wa.me/$whatsappNumber?text=${Uri.encodeComponent(message)}';

  static const playStoreUrl = 'https://play.google.com/store/apps/details?id=$playStoreId';
}
