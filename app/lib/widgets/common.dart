import 'package:flutter/material.dart';
import '../theme.dart';

class Pill extends StatelessWidget {
  final String text;
  final Color color;
  final IconData? icon;
  final bool filled;
  const Pill(this.text, {super.key, required this.color, this.icon, this.filled = false});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: filled ? color : color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        if (icon != null) ...[Icon(icon, size: 12, color: filled ? AppColors.bg : color), const SizedBox(width: 4)],
        Text(text,
            style: TextStyle(
                fontSize: 11, fontWeight: FontWeight.w700, letterSpacing: 0.3, color: filled ? AppColors.bg : color)),
      ]),
    );
  }
}

Color tierColor(String tier) => switch (tier) {
      'ELITE' => AppColors.gold,
      'HIGH' => AppColors.blue,
      _ => AppColors.muted,
    };

String tierLabel(String tier) => switch (tier) {
      'ELITE' => 'ELITE',
      'HIGH' => 'HIGH PROB',
      _ => 'STANDARD',
    };

({String label, Color color}) statusStyle(String status, double? pips) => switch (status) {
      'ACTIVE' => (label: 'RUNNING', color: AppColors.blue),
      'TP1' => (label: 'TP1 HIT · RISK-FREE', color: AppColors.green),
      'WIN' => (label: 'WIN', color: AppColors.green),
      'LOSS' => (label: 'LOSS', color: AppColors.red),
      'BREAKEVEN' => (label: 'BREAKEVEN', color: AppColors.muted),
      'CLOSED_EOD' => (label: (pips ?? 0) >= 0 ? 'CLOSED +' : 'CLOSED −', color: (pips ?? 0) >= 0 ? AppColors.green : AppColors.red),
      _ => (label: status, color: AppColors.muted),
    };

class ScoreRing extends StatelessWidget {
  final int score;
  final double size;
  final Color color;
  const ScoreRing({super.key, required this.score, required this.color, this.size = 44});

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: Stack(alignment: Alignment.center, children: [
        SizedBox(
          width: size,
          height: size,
          child: CircularProgressIndicator(
            value: score / 100,
            strokeWidth: 3.5,
            backgroundColor: AppColors.border,
            valueColor: AlwaysStoppedAnimation(color),
          ),
        ),
        Text('$score', style: TextStyle(fontWeight: FontWeight.w800, fontSize: size * 0.32, fontFeatures: tabular)),
      ]),
    );
  }
}

class SectionTitle extends StatelessWidget {
  final String text;
  final Widget? trailing;
  const SectionTitle(this.text, {super.key, this.trailing});
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(4, 20, 4, 10),
        child: Row(children: [
          Expanded(
            child: Text(text.toUpperCase(),
                style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 1)),
          ),
          if (trailing != null) trailing!,
        ]),
      );
}

class StatTile extends StatelessWidget {
  final String label;
  final String value;
  final Color? color;
  final String? sub;
  const StatTile({super.key, required this.label, required this.value, this.color, this.sub});

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          const SizedBox(height: 6),
          Text(value,
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: color ?? AppColors.text, fontFeatures: tabular)),
          if (sub != null) ...[
            const SizedBox(height: 2),
            Text(sub!, style: const TextStyle(color: AppColors.faint, fontSize: 11)),
          ],
        ]),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final Widget? action;
  const EmptyState({super.key, required this.icon, required this.title, required this.message, this.action});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 48),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
          padding: const EdgeInsets.all(18),
          decoration: const BoxDecoration(color: AppColors.surface, shape: BoxShape.circle),
          child: Icon(icon, size: 32, color: AppColors.muted),
        ),
        const SizedBox(height: 16),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
        const SizedBox(height: 6),
        Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.4)),
        if (action != null) ...[const SizedBox(height: 16), action!],
      ]),
    );
  }
}

class ErrorRetry extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const ErrorRetry({super.key, required this.message, required this.onRetry});
  @override
  Widget build(BuildContext context) => EmptyState(
        icon: Icons.wifi_off_rounded,
        title: 'Couldn\'t load',
        message: message,
        action: OutlinedButton(onPressed: onRetry, child: const Text('Try again')),
      );
}

String timeAgo(DateTime t) {
  final d = DateTime.now().difference(t);
  if (d.inMinutes < 1) return 'just now';
  if (d.inMinutes < 60) return '${d.inMinutes}m ago';
  if (d.inHours < 24) return '${d.inHours}h ago';
  return '${d.inDays}d ago';
}

String signedPips(double? p) => p == null ? '–' : '${p >= 0 ? '+' : ''}${p.toStringAsFixed(1)} pips';

void toast(BuildContext context, String msg) =>
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(msg)));
