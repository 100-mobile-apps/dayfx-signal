import 'dart:async';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models.dart';
import '../services/api.dart';
import '../theme.dart';
import '../utils/market.dart';
import '../widgets/common.dart';

class ToolsScreen extends StatefulWidget {
  const ToolsScreen({super.key});
  @override
  State<ToolsScreen> createState() => _ToolsScreenState();
}

class _ToolsScreenState extends State<ToolsScreen> {
  List<PriceTick> _prices = [];
  Timer? _timer;

  // lot size calculator
  String _pair = 'EUR/USD';
  final _balance = TextEditingController(text: '1000');
  final _risk = TextEditingController(text: '1');
  final _sl = TextEditingController(text: '20');

  @override
  void initState() {
    super.initState();
    _loadPrices();
    _timer = Timer.periodic(const Duration(minutes: 1), (_) {
      _loadPrices();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _loadPrices() async {
    try {
      final p = await Api.instance.prices();
      if (mounted) setState(() => _prices = p);
    } catch (_) {
      if (mounted) setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    final nowUtc = DateTime.now().toUtc();
    final bal = double.tryParse(_balance.text) ?? 0;
    final risk = double.tryParse(_risk.text) ?? 0;
    final sl = double.tryParse(_sl.text) ?? 0;
    final pipVal = pipValueUsdPerLot(_pair, _prices);
    final riskUsd = bal * risk / 100;
    final lots = sl > 0 ? riskUsd / (sl * pipVal) : 0.0;

    return SafeArea(
      child: RefreshIndicator(
        onRefresh: _loadPrices,
        color: AppColors.green,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          physics: const AlwaysScrollableScrollPhysics(),
          children: [
            const Text('Trader tools', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
            const SectionTitle('Lot size calculator'),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  DropdownButtonFormField<String>(
                    value: _pair,
                    dropdownColor: AppColors.surface2,
                    decoration: const InputDecoration(labelText: 'Pair'),
                    items: [for (final p in calculatorPairs) DropdownMenuItem(value: p, child: Text(p))],
                    onChanged: (v) => setState(() => _pair = v ?? _pair),
                  ),
                  const SizedBox(height: 12),
                  Row(children: [
                    Expanded(child: _num(_balance, 'Balance (USD)')),
                    const SizedBox(width: 10),
                    Expanded(child: _num(_risk, 'Risk %')),
                    const SizedBox(width: 10),
                    Expanded(child: _num(_sl, 'Stop (pips)')),
                  ]),
                  const SizedBox(height: 18),
                  Row(children: [
                    Expanded(child: _Result('Lot size', lots.toStringAsFixed(2), AppColors.green)),
                    Expanded(child: _Result('Risk', '\$${riskUsd.toStringAsFixed(2)}', AppColors.red)),
                    Expanded(child: _Result('Pip value / lot', '\$${pipVal.toStringAsFixed(2)}', AppColors.text)),
                  ]),
                  const SizedBox(height: 10),
                  Text('Mini lot = 0.10 · Micro lot = 0.01',
                      style: const TextStyle(color: AppColors.faint, fontSize: 12)),
                ]),
              ),
            ),
            const SectionTitle('Market sessions · your time'),
            Card(
              child: Column(children: [
                for (final s in sessions) _SessionRow(s, nowUtc, last: s == sessions.last),
              ]),
            ),
            const SizedBox(height: 8),
            Text(
              signalWindowOpen(nowUtc)
                  ? 'Signal engine is active (London & New York hours).'
                  : 'Signal engine is idle — it scans 07:00–19:00 UTC on trading days.',
              style: const TextStyle(color: AppColors.muted, fontSize: 12),
            ),
            SectionTitle('Prices', trailing: Text('updates every 15 min', style: const TextStyle(color: AppColors.faint, fontSize: 11))),
            if (_prices.isEmpty)
              const Card(
                  child: Padding(
                      padding: EdgeInsets.all(20),
                      child: Text('Prices appear once the engine has run.', style: TextStyle(color: AppColors.muted))))
            else
              Card(
                child: Column(children: [
                  for (final p in _prices)
                    ListTile(
                      title: Text(p.pair, style: const TextStyle(fontWeight: FontWeight.w700)),
                      trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                        Text(p.price.toStringAsFixed(p.pair.startsWith('XAU') ? 2 : (p.pair.contains('JPY') ? 3 : 5)),
                            style: const TextStyle(fontWeight: FontWeight.w700, fontFeatures: tabular)),
                        const SizedBox(width: 12),
                        SizedBox(
                          width: 64,
                          child: Text('${p.changePct >= 0 ? '+' : ''}${p.changePct.toStringAsFixed(2)}%',
                              textAlign: TextAlign.right,
                              style: TextStyle(
                                  color: p.changePct >= 0 ? AppColors.green : AppColors.red, fontFeatures: tabular)),
                        ),
                      ]),
                    ),
                ]),
              ),
          ],
        ),
      ),
    );
  }

  Widget _num(TextEditingController c, String label) => TextField(
        controller: c,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        decoration: InputDecoration(labelText: label, contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14)),
        onChanged: (_) => setState(() {}),
      );
}

class _Result extends StatelessWidget {
  final String label, value;
  final Color color;
  const _Result(this.label, this.value, this.color);
  @override
  Widget build(BuildContext context) => Column(children: [
        Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: color, fontFeatures: tabular)),
      ]);
}

class _SessionRow extends StatelessWidget {
  final MarketSession s;
  final DateTime nowUtc;
  final bool last;
  const _SessionRow(this.s, this.nowUtc, {this.last = false});

  @override
  Widget build(BuildContext context) {
    final open = s.isOpen(nowUtc);
    final day = DateTime.utc(nowUtc.year, nowUtc.month, nowUtc.day);
    final o = day.add(Duration(hours: s.openUtc)).toLocal();
    final c = day.add(Duration(hours: s.closeUtc)).toLocal();
    final f = DateFormat('HH:mm');
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      decoration: BoxDecoration(border: last ? null : const Border(bottom: BorderSide(color: AppColors.border))),
      child: Row(children: [
        Container(width: 10, height: 10, decoration: BoxDecoration(color: open ? s.color : AppColors.border, shape: BoxShape.circle)),
        const SizedBox(width: 12),
        Expanded(child: Text(s.name, style: const TextStyle(fontWeight: FontWeight.w700))),
        Text('${f.format(o)} – ${f.format(c)}', style: const TextStyle(color: AppColors.muted, fontFeatures: tabular)),
        const SizedBox(width: 12),
        Pill(open ? 'OPEN' : 'CLOSED', color: open ? s.color : AppColors.muted),
      ]),
    );
  }
}
