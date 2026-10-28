import 'dart:async';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models.dart';
import '../services/api.dart';
import '../theme.dart';
import '../widgets/common.dart';

class NewsScreen extends StatefulWidget {
  const NewsScreen({super.key});
  @override
  State<NewsScreen> createState() => _NewsScreenState();
}

class _NewsScreenState extends State<NewsScreen> {
  List<NewsEvent> _events = [];
  bool _loading = true;
  String? _error;
  bool _highOnly = false;
  Timer? _tick;

  @override
  void initState() {
    super.initState();
    _load();
    _tick = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final e = await Api.instance.news();
      if (mounted) setState(() {
        _events = e;
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    final list = _events.where((e) => !_highOnly || e.impact == 'High').toList();
    final nextHigh = _events.where((e) => e.impact == 'High' && e.time.isAfter(now)).firstOrNull;
    final groups = <String, List<NewsEvent>>{};
    for (final e in list) {
      groups.putIfAbsent(DateFormat('EEEE d MMMM').format(e.time), () => []).add(e);
    }

    return SafeArea(
      child: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.green,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            const Text('News calendar', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            const Text('High & medium impact events · your local time',
                style: TextStyle(color: AppColors.muted, fontSize: 13)),
            const SizedBox(height: 14),
            if (nextHigh != null) _NextHighCard(nextHigh, now),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.orange.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.orange.withValues(alpha: 0.25)),
              ),
              child: const Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.shield_moon_outlined, color: AppColors.orange, size: 18),
                SizedBox(width: 10),
                Expanded(
                  child: Text(
                    'News protection: new signals pause 30 min before and after red-folder events on the affected '
                    'currencies. After the release, News Momentum looks for the clean follow-through.',
                    style: TextStyle(fontSize: 13, height: 1.4),
                  ),
                ),
              ]),
            ),
            const SizedBox(height: 8),
            SwitchListTile(
              value: _highOnly,
              onChanged: (v) => setState(() => _highOnly = v),
              contentPadding: EdgeInsets.zero,
              title: const Text('High impact only'),
            ),
            if (_loading) const Padding(padding: EdgeInsets.all(40), child: Center(child: CircularProgressIndicator())),
            if (!_loading && _error != null && _events.isEmpty) ErrorRetry(message: _error!, onRetry: _load),
            if (!_loading && _error == null && list.isEmpty)
              const EmptyState(
                  icon: Icons.event_available_rounded,
                  title: 'Quiet calendar',
                  message: 'No major events scheduled in the next few days.'),
            for (final g in groups.entries) ...[
              SectionTitle(g.key),
              Card(child: Column(children: [for (final e in g.value) _EventRow(e, now, last: e == g.value.last)])),
            ],
          ],
        ),
      ),
    );
  }
}

Color _impactColor(String i) => i == 'High' ? AppColors.red : (i == 'Medium' ? AppColors.orange : AppColors.muted);

String _countdown(Duration d) {
  if (d.isNegative) return 'released';
  if (d.inHours >= 24) return 'in ${d.inDays}d ${d.inHours % 24}h';
  if (d.inHours >= 1) return 'in ${d.inHours}h ${d.inMinutes % 60}m';
  return 'in ${d.inMinutes}m';
}

class _NextHighCard extends StatelessWidget {
  final NewsEvent e;
  final DateTime now;
  const _NextHighCard(this.e, this.now);
  @override
  Widget build(BuildContext context) {
    return Card(
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: AppColors.red.withValues(alpha: 0.4)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(color: AppColors.red.withValues(alpha: 0.12), borderRadius: BorderRadius.circular(12)),
            child: const Icon(Icons.bolt_rounded, color: AppColors.red),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('NEXT HIGH-IMPACT', style: TextStyle(color: AppColors.red, fontSize: 11, fontWeight: FontWeight.w800)),
              const SizedBox(height: 2),
              Text('${e.currency} · ${e.title}', style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ),
          Text(_countdown(e.time.difference(now)),
              style: const TextStyle(fontWeight: FontWeight.w800, fontFeatures: tabular)),
        ]),
      ),
    );
  }
}

class _EventRow extends StatelessWidget {
  final NewsEvent e;
  final DateTime now;
  final bool last;
  const _EventRow(this.e, this.now, {this.last = false});

  @override
  Widget build(BuildContext context) {
    final past = e.time.isBefore(now);
    return Opacity(
      opacity: past ? 0.5 : 1,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(border: last ? null : const Border(bottom: BorderSide(color: AppColors.border))),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          SizedBox(
            width: 48,
            child: Text(DateFormat('HH:mm').format(e.time),
                style: const TextStyle(fontWeight: FontWeight.w700, fontFeatures: tabular)),
          ),
          Container(
            width: 4,
            height: 36,
            margin: const EdgeInsets.only(right: 12),
            decoration: BoxDecoration(color: _impactColor(e.impact), borderRadius: BorderRadius.circular(4)),
          ),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Text(e.currency, style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(width: 8),
                Expanded(child: Text(e.title, maxLines: 2, overflow: TextOverflow.ellipsis)),
              ]),
              if (e.forecast != null || e.previous != null) ...[
                const SizedBox(height: 4),
                Text(
                  [if (e.forecast != null) 'Fcst ${e.forecast}', if (e.previous != null) 'Prev ${e.previous}'].join('   '),
                  style: const TextStyle(color: AppColors.muted, fontSize: 12),
                ),
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}
