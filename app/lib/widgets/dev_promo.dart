import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:in_app_review/in_app_review.dart';
import 'package:url_launcher/url_launcher.dart';
import '../config.dart';
import '../services/app_state.dart';
import '../theme.dart';

/// Developer promo (Swiftmynd Technologies Ltd): rate app, chat, buy me a coffee, hire the developer.
class DevPromo {
  static Future<void> open(String url) =>
      launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);

  static Future<void> rateApp() async {
    final r = InAppReview.instance;
    if (await r.isAvailable()) {
      await r.requestReview();
    } else if (Platform.isAndroid) {
      await r.openStoreListing();
    } else if (Platform.isIOS && AppConfig.appStoreId.isNotEmpty) {
      await r.openStoreListing(appStoreId: AppConfig.appStoreId);
    }
  }

  static Future<void> chat() => open(AppConfig.whatsappLink('Hi! I\'m using ${AppConfig.appName} and wanted to say hello.'));
  static Future<void> hire() =>
      open(AppConfig.whatsappLink('Hi ${AppConfig.developerName}! I\'d like to hire you to build an app for me.'));
  static Future<void> coffee() => open(AppConfig.buyMeACoffeeUrl);

  /// Rules: never on the first 2 launches, at most once every 4 launches, never twice in 3 days,
  /// only from the main Signals tab, and never when the user turned "Developer offers" off.
  static Future<void> maybeShow(BuildContext context, AppState state) async {
    if (!state.developerOffers) return;
    final launches = state.launchCount;
    if (launches < 3) return;
    final lastLaunch = state.getInt('promo_last_launch');
    final lastAt = state.getInt('promo_last_at');
    final now = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    if (launches - lastLaunch < 4 || now - lastAt < 3 * 86400) return;
    await state.setInt('promo_last_launch', launches);
    await state.setInt('promo_last_at', now);
    if (!context.mounted) return;
    await showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.surface,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))),
      builder: (_) => const DevPromoSheet(),
    );
  }
}

class DevPromoSheet extends StatelessWidget {
  const DevPromoSheet({super.key});

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Enjoying DayFX Signals?', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          const Text(
            'It\'s free and built by a small independent team. A quick rating helps more traders find it.',
            style: TextStyle(color: AppColors.muted, height: 1.4),
          ),
          const SizedBox(height: 18),
          DevActionTile(
            icon: Icons.star_rounded,
            color: AppColors.gold,
            title: 'Rate the app',
            subtitle: 'Takes 5 seconds',
            onTap: () {
              Navigator.pop(context);
              DevPromo.rateApp();
            },
          ),
          DevActionTile(
            icon: Icons.chat_rounded,
            color: AppColors.green,
            title: 'Chat with the developer',
            subtitle: 'Feedback, ideas or issues on WhatsApp',
            onTap: DevPromo.chat,
          ),
          DevActionTile(
            icon: Icons.local_cafe_rounded,
            color: AppColors.orange,
            title: 'Buy me a coffee',
            subtitle: 'Support free updates',
            onTap: DevPromo.coffee,
          ),
          DevActionTile(
            icon: Icons.rocket_launch_rounded,
            color: AppColors.blue,
            title: 'Have an app idea? Hire me',
            subtitle: 'Mobile apps, trading tools & web platforms',
            onTap: DevPromo.hire,
          ),
          const SizedBox(height: 6),
          const Text('You can turn these off in Settings › Developer offers.',
              textAlign: TextAlign.center, style: TextStyle(color: AppColors.faint, fontSize: 12)),
        ]),
      ),
    );
  }
}

class DevActionTile extends StatelessWidget {
  final IconData icon;
  final Color color;
  final String title, subtitle;
  final VoidCallback onTap;
  const DevActionTile({
    super.key,
    required this.icon,
    required this.color,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return ListTile(
      onTap: onTap,
      contentPadding: const EdgeInsets.symmetric(horizontal: 4),
      leading: Container(
        width: 40,
        height: 40,
        decoration: BoxDecoration(color: color.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(12)),
        child: Icon(icon, color: color, size: 22),
      ),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
      subtitle: Text(subtitle, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
      trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.faint),
    );
  }
}
