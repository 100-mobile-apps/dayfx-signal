import 'package:fl_chart/fl_chart.dart';
import 'package:flutter/material.dart';
import '../models.dart';
import '../services/api.dart';
import '../theme.dart';
import '../widgets/common.dart';

class ResultsScreen extends StatefulWidget {
  const ResultsScreen({super.key});
  @override
  State<ResultsScreen> createState() => _ResultsScreenState();
}

class _ResultsScreenState extends State<ResultsScreen> {
  String _period = 'week';
  bool _elite = false;
  Stats? _stats;
  String? _error;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final s = await Api.instance.stats(_period, eliteOnly: _elite);
      if (mounted) setState(() {
        _stats = s;
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
    final s = _stats;
    return SafeArea(
      child: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.green,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            const Text('Track record', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            const Text('Every signal, tracked automatically against live prices',
                style: TextStyle(color: AppColors.muted, fontSize: 13)),
            const SizedBox(height: 14),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(value: 'today', label: Text('Today')),
                ButtonSegment(value: 'week', label: Text('Week')),
                ButtonSegment(value: 'month', label: Text('Month')),
                ButtonSegment(value: 'all', label: Text('All')),
              ],
              selected: {_period},
              showSelectedIcon: false,
              style: SegmentedButton.styleFrom(
                backgroundColor: AppColors.surface,
                selectedBackgroundColor: AppColors.surface2,
                selectedForegroundColor: AppColors.text,
                foregroundColor: AppColors.muted,
                side: const BorderSide(color: AppColors.border),
              ),
              onSelectionChanged: (v) {
                setState(() => _period = v.first);
                _load();
              },
            ),
            SwitchListTile(
              value: _elite,
              onChanged: (v) {
                setState(() => _elite = v);
                _load();
              },
              contentPadding: EdgeInsets.zero,
              secondary: const Icon(Icons.workspace_premium_rounded, color: AppColors.gold),
              title: const Text('Elite signals only'),
            ),
            if (_loading && s == null)
              const Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator())),
            if (_error != null && s == null) ErrorRetry(message: _error!, onRetry: _load),
            if (s != null) ..._content(s),
          ],
        ),
      ),
    );
  }

  List<Widget> _content(Stats s) {
    final a = s.summary;
    final wr = a.winRate;
    return [
      GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 10,
        crossAxisSpacing: 10,
        childAspectRatio: 1.7,
        children: [
          StatTile(
            label: 'Win rate',
            value: wr == null ? '–' : '${wr.toStringAsFixed(1)}%',
            color: wr == null ? null : (wr >= 50 ? AppColors.green : AppColors.red),
            sub: '${a.wins + a.losses} decided trades',
          ),
          StatTile(
            label: 'Net pips',
            value: '${a.pips >= 0 ? '+' : ''}${a.pips.toStringAsFixed(1)}',
            color: a.pips >= 0 ? AppColors.green : AppColors.red,
            sub: 'before spread',
          ),
          StatTile(label: 'Wins', value: '${a.wins}', color: AppColors.green),
          StatTile(label: 'Losses', value: '${a.losses}', color: AppColors.red, sub: '${a.breakeven} breakeven'),
        ],
      ),
      const SectionTitle('Daily pips · last 30 days'),
      Card(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 20, 12, 12),
          child: SizedBox(height: 160, child: _DailyChart(s.daily)),
        ),
      ),
      if (s.byStrategy.isNotEmpty) ...[
        const SectionTitle('By strategy'),
        Card(child: Column(children: [for (final x in s.byStrategy) _AggRow(x.name ?? x.key, x)])),
      ],
      if (s.byTier.isNotEmpty) ...[
        const SectionTitle('By tier'),
        Card(child: Column(children: [for (final x in s.byTier) _AggRow(tierLabel(x.key), x, color: tierColor(x.key))])),
      ],
      const SectionTitle('How results are counted'),
      const Card(
        child: Padding(
          padding: EdgeInsets.all(16),
          child: Text(
            '• Win: TP1 reached (half closed at +1R, stop moved to entry). Reaching TP2 adds the second half at +2R.\n'
            '• Loss: stop loss hit before TP1. If a single candle touches both, it is counted as a loss.\n'
            '• End of day: trades still open at 21:00 UTC are closed at market.\n'
            '• Prices are mid-market without spread or commission. Past results do not guarantee future results.',
            style: TextStyle(color: AppColors.muted, height: 1.6, fontSize: 13),
          ),
        ),
      ),
    ];
  }
}

class _AggRow extends StatelessWidget {
  final String title;
  final Agg a;
  final Color? color;
  const _AggRow(this.title, this.a, {this.color});
  @override
  Widget build(BuildContext context) {
    final wr = a.winRate ?? 0;
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      child: Column(children: [
        Row(children: [
          Expanded(child: Text(title, style: TextStyle(fontWeight: FontWeight.w700, color: color))),
          Text(a.winRate == null ? '–' : '${wr.toStringAsFixed(0)}%',
              style: const TextStyle(fontWeight: FontWeight.w800, fontFeatures: tabular)),
          const SizedBox(width: 12),
          SizedBox(
            width: 70,
            child: Text('${a.pips >= 0 ? '+' : ''}${a.pips.toStringAsFixed(0)}p',
                textAlign: TextAlign.right,
                style: TextStyle(
                    color: a.pips >= 0 ? AppColors.green : AppColors.red, fontFeatures: tabular, fontWeight: FontWeight.w600)),
          ),
        ]),
        const SizedBox(height: 8),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: wr / 100,
            minHeight: 6,
            backgroundColor: AppColors.red.withValues(alpha: 0.25),
            valueColor: const AlwaysStoppedAnimation(AppColors.green),
          ),
        ),
        const SizedBox(height: 4),
        Align(
          alignment: Alignment.centerLeft,
          child: Text('${a.wins}W · ${a.losses}L · ${a.breakeven} BE',
              style: const TextStyle(color: AppColors.faint, fontSize: 11)),
        ),
      ]),
    );
  }
}

class _DailyChart extends StatelessWidget {
  final List<Agg> days;
  const _DailyChart(this.days);
  @override
  Widget build(BuildContext context) {
    if (days.every((d) => d.total == 0)) {
      return const Center(child: Text('No closed trades yet', style: TextStyle(color: AppColors.muted)));
    }
    return BarChart(BarChartData(
      alignment: BarChartAlignment.spaceBetween,
      gridData: const FlGridData(show: false),
      borderData: FlBorderData(show: false),
      titlesData: const FlTitlesData(show: false),
      barTouchData: BarTouchData(
        touchTooltipData: BarTouchTooltipData(
          getTooltipColor: (_) => AppColors.surface2,
          getTooltipItem: (g, _, rod, __) => BarTooltipItem(
            '${days[g.x].key.substring(5)}\n${rod.toY >= 0 ? '+' : ''}${rod.toY.toStringAsFixed(1)}p',
            const TextStyle(color: AppColors.text, fontSize: 12),
          ),
        ),
      ),
      barGroups: [
        for (var i = 0; i < days.length; i++)
          BarChartGroupData(x: i, barRods: [
            BarChartRodData(
              toY: days[i].pips,
              width: 6,
              color: days[i].pips >= 0 ? AppColors.green : AppColors.red,
              borderRadius: BorderRadius.circular(2),
            ),
          ]),
      ],
    ));
  }
}
