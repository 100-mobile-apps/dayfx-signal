import 'package:flutter/material.dart';
import '../models.dart';
import '../screens/signal_detail_screen.dart';
import '../theme.dart';
import 'common.dart';

class SignalCard extends StatelessWidget {
  final Signal s;
  const SignalCard(this.s, {super.key});

  @override
  Widget build(BuildContext context) {
    final dirColor = s.isBuy ? AppColors.green : AppColors.red;
    final st = statusStyle(s.status, s.resultPips);
    return Card(
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: s.isElite ? AppColors.gold.withValues(alpha: 0.45) : AppColors.border),
      ),
      child: InkWell(
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SignalDetailScreen(signal: s))),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(color: dirColor.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
                child: Icon(s.isBuy ? Icons.north_east_rounded : Icons.south_east_rounded, color: dirColor),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Text(s.pair, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                    const SizedBox(width: 8),
                    Text(s.direction, style: TextStyle(color: dirColor, fontWeight: FontWeight.w800)),
                  ]),
                  const SizedBox(height: 2),
                  Text('${s.strategyName} · ${timeAgo(s.createdAt)}',
                      style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                ]),
              ),
              ScoreRing(score: s.score, color: tierColor(s.tier), size: 42),
            ]),
            const SizedBox(height: 14),
            Row(children: [
              _Lvl('Entry', s.fmt(s.entry), AppColors.text),
              _Lvl('SL', s.fmt(s.sl), AppColors.red),
              _Lvl('TP1', s.fmt(s.tp1), AppColors.green),
              _Lvl('TP2', s.fmt(s.tp2), AppColors.green),
            ]),
            const SizedBox(height: 14),
            Row(children: [
              Pill(tierLabel(s.tier), color: tierColor(s.tier), icon: s.isElite ? Icons.workspace_premium_rounded : null),
              const SizedBox(width: 6),
              Pill(st.label, color: st.color),
              const Spacer(),
              if (!s.isOpen)
                Text(signedPips(s.resultPips),
                    style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontFeatures: tabular,
                        color: (s.resultPips ?? 0) >= 0 ? AppColors.green : AppColors.red)),
            ]),
          ]),
        ),
      ),
    );
  }
}

class _Lvl extends StatelessWidget {
  final String label, value;
  final Color color;
  const _Lvl(this.label, this.value, this.color);
  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(label, style: const TextStyle(color: AppColors.faint, fontSize: 11)),
          const SizedBox(height: 2),
          FittedBox(
            fit: BoxFit.scaleDown,
            child: Text(value,
                style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 13, fontFeatures: tabular)),
          ),
        ]),
      );
}
