import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';
import '../config.dart';
import '../services/app_state.dart';
import '../services/push.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/dev_promo.dart';
import 'disclaimer_screen.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  Future<void> _update(BuildContext context, Future<void> Function() f) async {
    try {
      await f();
    } catch (e) {
      if (context.mounted) toast(context, e.toString());
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final u = s.user;
    return SafeArea(
      child: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
        children: [
          const Text('Settings', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
          const SizedBox(height: 16),
          if (u != null)
            Card(
              child: ListTile(
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                leading: CircleAvatar(
                  backgroundColor: AppColors.green.withValues(alpha: 0.15),
                  child: Text(u.name.isNotEmpty ? u.name[0].toUpperCase() : '?',
                      style: const TextStyle(color: AppColors.green, fontWeight: FontWeight.w800)),
                ),
                title: Text(u.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                subtitle: Text(u.email, style: const TextStyle(color: AppColors.muted)),
              ),
            ),
          const SectionTitle('Signals'),
          Card(
            child: Column(children: [
              SwitchListTile(
                value: u?.eliteOnly ?? false,
                onChanged: (v) => _update(context, () => s.updateSettings(eliteOnly: v)),
                secondary: const Icon(Icons.workspace_premium_rounded, color: AppColors.gold),
                title: const Text('Elite mode'),
                subtitle: const Text('Only alert me for 85+ confluence setups', style: TextStyle(color: AppColors.muted, fontSize: 12)),
              ),
            ]),
          ),
          const SectionTitle('Notifications'),
          Card(
            child: Column(children: [
              SwitchListTile(
                value: u?.pushAlerts ?? true,
                onChanged: (v) => _update(context, () => s.updateSettings(pushAlerts: v)),
                secondary: const Icon(Icons.notifications_active_outlined, color: AppColors.green),
                title: const Text('Push: new signals'),
                subtitle: Text(
                  PushService.instance.available
                      ? 'Checked in the background about every 15 min'
                      : 'Background alerts are Android-only for now',
                  style: const TextStyle(color: AppColors.muted, fontSize: 12),
                ),
              ),
              SwitchListTile(
                value: s.resultAlerts,
                onChanged: (u?.pushAlerts ?? true) ? (v) => _update(context, () => s.setResultAlerts(v)) : null,
                secondary: const Icon(Icons.flag_outlined, color: AppColors.blue),
                title: const Text('Push: wins, losses & TP1'),
              ),
              SwitchListTile(
                value: u?.emailAlerts ?? true,
                onChanged: (v) => _update(context, () => s.updateSettings(emailAlerts: v)),
                secondary: const Icon(Icons.mail_outline_rounded, color: AppColors.orange),
                title: const Text('Email alerts'),
                subtitle: const Text('Signals and results to your inbox', style: TextStyle(color: AppColors.muted, fontSize: 12)),
              ),
              ListTile(
                leading: const Icon(Icons.notifications_none_rounded, color: AppColors.muted),
                title: const Text('Send a test notification'),
                onTap: () async {
                  await PushService.instance.showTest();
                  if (context.mounted) toast(context, 'Test notification sent');
                },
              ),
            ]),
          ),
          const SizedBox(height: 10),
          const Text(
            'Alerts can arrive a few minutes late. If they stop, set battery usage for this app to Unrestricted '
            '(Phone Settings › Apps › DayFX Signals › Battery) and allow it to run in the background.',
            style: TextStyle(color: AppColors.faint, fontSize: 12, height: 1.4),
          ),
          const SectionTitle('Support the app'),
          Card(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Column(children: [
                DevActionTile(icon: Icons.star_rounded, color: AppColors.gold, title: 'Rate DayFX Signals',
                    subtitle: 'Help other traders find it', onTap: DevPromo.rateApp),
                DevActionTile(icon: Icons.share_rounded, color: AppColors.green, title: 'Share with a friend',
                    subtitle: 'Free signals for them too',
                    onTap: () => Share.share('Free forex day-trading signals with tracked results: ${AppConfig.playStoreUrl}')),
                DevActionTile(icon: Icons.chat_rounded, color: AppColors.green, title: 'Chat with the developer',
                    subtitle: 'WhatsApp', onTap: DevPromo.chat),
                DevActionTile(icon: Icons.local_cafe_rounded, color: AppColors.orange, title: 'Buy me a coffee',
                    subtitle: 'Keep the signals free', onTap: DevPromo.coffee),
                DevActionTile(icon: Icons.rocket_launch_rounded, color: AppColors.blue, title: 'Hire the developer',
                    subtitle: AppConfig.developerName, onTap: DevPromo.hire),
              ]),
            ),
          ),
          Card(
            margin: const EdgeInsets.only(top: 10),
            child: SwitchListTile(
              value: s.developerOffers,
              onChanged: s.setDeveloperOffers,
              title: const Text('Developer offers'),
              subtitle: const Text('Occasional pop-up to rate the app or contact the developer',
                  style: TextStyle(color: AppColors.muted, fontSize: 12)),
            ),
          ),
          const SectionTitle('About'),
          Card(
            child: Column(children: [
              ListTile(
                leading: const Icon(Icons.shield_outlined, color: AppColors.muted),
                title: const Text('Risk disclaimer'),
                trailing: const Icon(Icons.chevron_right_rounded, color: AppColors.faint),
                onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const DisclaimerScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.privacy_tip_outlined, color: AppColors.muted),
                title: const Text('Privacy policy'),
                trailing: const Icon(Icons.open_in_new_rounded, size: 18, color: AppColors.faint),
                onTap: () => launchUrl(Uri.parse(AppConfig.privacyPolicyUrl), mode: LaunchMode.externalApplication),
              ),
              ListTile(
                leading: const Icon(Icons.manage_accounts_outlined, color: AppColors.muted),
                title: const Text('Account deletion help'),
                trailing: const Icon(Icons.open_in_new_rounded, size: 18, color: AppColors.faint),
                onTap: () => launchUrl(Uri.parse(AppConfig.deletionUrl), mode: LaunchMode.externalApplication),
              ),
              ListTile(
                leading: const Icon(Icons.logout_rounded, color: AppColors.muted),
                title: const Text('Log out'),
                onTap: () => s.logout(),
              ),
              ListTile(
                leading: const Icon(Icons.delete_outline_rounded, color: AppColors.red),
                title: const Text('Delete account', style: TextStyle(color: AppColors.red)),
                onTap: () => _confirmDelete(context, s),
              ),
            ]),
          ),
          const SizedBox(height: 20),
          const Center(
            child: Text('${AppConfig.appName} · v1.0.0\nby ${AppConfig.developerName}',
                textAlign: TextAlign.center, style: TextStyle(color: AppColors.faint, fontSize: 12, height: 1.5)),
          ),
        ],
      ),
    );
  }

  Future<void> _confirmDelete(BuildContext context, AppState s) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: const Text('Delete account?'),
        content: const Text('Your account and settings will be permanently deleted. This can\'t be undone.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(c, true),
            child: const Text('Delete', style: TextStyle(color: AppColors.red)),
          ),
        ],
      ),
    );
    if (ok == true && context.mounted) await _update(context, s.deleteAccount);
  }
}
