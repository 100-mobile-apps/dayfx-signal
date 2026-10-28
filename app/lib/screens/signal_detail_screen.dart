import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';
import 'package:share_plus/share_plus.dart';
import '../config.dart';
import '../models.dart';
import '../services/api.dart';
import '../theme.dart';
import '../utils/market.dart';
import '../widgets/common.dart';

class SignalDetailScreen extends StatefulWidget {
  final Signal? signal;
  final int? signalId;
  const SignalDetailScreen({super.key, this.signal, this.signalId});
  @override
  State<SignalDetailScreen> createState() => _SignalDetailScreenState();
}

class _SignalDetailScreenState extends State<SignalDetailScreen> {
  Signal? _s;
  String? _error;
  final _balance = TextEditingController(text: '1000');
  final _risk = TextEditingController(text: '1');
  List<PriceTick> _prices = [];

  @override
  void initState() {
    super.initState();
    _s = widget.signal;
    _load();
    Api.instance.prices().then((p) {
      if (mounted) setState(() => _prices = p);
    }).catchError((_) {});
  }

  Future<void> _load() async {
    final id = widget.signal?.id ?? widget.signalId;
    if (id == null) return;
    try {
      final s = await Api.instance.signal(id);
      if (mounted) setState(() => _s = s);
    } catch (e) {
      if (mounted && _s == null) setState(() => _error = e.toString());
    }
  }

  void _copy(String label, String v) {
    Clipboard.setData(ClipboardData(text: v));
    toast(context, '$label copied: $v');
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return Scaffold(
      appBar: AppBar(
        title: Text(s == null ? 'Signal' : '${s.pair} ${s.direction}'),
        actions: [
          if (s != null)
            IconButton(
              icon: const Icon(Icons.ios_share_rounded),
              onPressed: () => Share.share(
                '${s.direction} ${s.pair} (${tierLabel(s.tier)} ${s.score}/100)\n'
                'Entry ${s.fmt(s.entry)}  SL ${s.fmt(s.sl)}\nTP1 ${s.fmt(s.tp1)}  TP2 ${s.fmt(s.tp2)}\n\n'
                'Free forex day-trading signals: ${AppConfig.playStoreUrl}',
              ),
            ),
        ],
      ),
      body: s == null
          ? (_error != null
              ? ErrorRetry(message: _error!, onRetry: _load)
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: _body(s)),
    );
  }

  Widget _body(Signal s) {
    final dirColor = s.isBuy ? AppColors.green : AppColors.red;
    final st = statusStyle(s.status, s.resultPips);
    final df = DateFormat('EEE d MMM, HH:mm');
    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
      physics: const AlwaysScrollableScrollPhysics(),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(18),
            child: Column(children: [
              Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Text(s.direction,
                          style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: dirColor)),
                      const SizedBox(width: 10),
                      Text(s.pair, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w900)),
                    ]),
                    const SizedBox(height: 6),
                    Wrap(spacing: 6, runSpacing: 6, children: [
                      Pill(tierLabel(s.tier), color: tierColor(s.tier),
                          icon: s.isElite ? Icons.workspace_premium_rounded : null),
                      Pill(st.label, color: st.color),
                      Pill(s.strategyName, color: AppColors.muted),
                    ]),
                  ]),
                ),
                Column(children: [
                  ScoreRing(score: s.score, color: tierColor(s.tier), size: 58),
                  const SizedBox(height: 4),
                  const Text('confluence', style: TextStyle(color: AppColors.faint, fontSize: 10)),
                ]),
              ]),
              if (!s.isOpen) ...[
                const Divider(height: 28),
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  const Text('Result', style: TextStyle(color: AppColors.muted)),
                  Text(signedPips(s.resultPips),
                      style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                          fontFeatures: tabular,
                          color: (s.resultPips ?? 0) >= 0 ? AppColors.green : AppColors.red)),
                ]),
              ],
            ]),
          ),
        ),
        const SectionTitle('Trade levels · tap to copy'),
        Card(
          child: Column(children: [
            _LevelRow('Entry (market)', s.fmt(s.entry), AppColors.text, null, () => _copy('Entry', s.fmt(s.entry))),
            _LevelRow('Stop loss', s.fmt(s.sl), AppColors.red, '-${s.riskPips.toStringAsFixed(1)} pips',
                () => _copy('Stop loss', s.fmt(s.sl))),
            _LevelRow('Take profit 1 · 1R', s.fmt(s.tp1), AppColors.green, '+${s.riskPips.toStringAsFixed(1)} pips',
                () => _copy('TP1', s.fmt(s.tp1))),
            _LevelRow('Take profit 2 · 2R', s.fmt(s.tp2), AppColors.green,
                '+${(s.riskPips * 2).toStringAsFixed(1)} pips', () => _copy('TP2', s.fmt(s.tp2)),
                last: true),
          ]),
        ),
        const SizedBox(height: 10),
        const _Hint(
          'Plan: close half at TP1 and move your stop to entry — the rest runs risk-free to TP2. '
          'Any trade still open at 21:00 UTC is closed (day trading, no overnight holds).',
        ),
        const SectionTitle('Why this signal'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(children: [
              for (final r in s.reasons)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 5),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.check_circle_rounded, size: 18, color: AppColors.green),
                    const SizedBox(width: 10),
                    Expanded(child: Text(r, style: const TextStyle(height: 1.35))),
                  ]),
                ),
            ]),
          ),
        ),
        const SectionTitle('Position size'),
        _RiskCalc(signal: s, balance: _balance, risk: _risk, prices: _prices, onChanged: () => setState(() {})),
        const SectionTitle('Timeline'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(children: [
              _TimeRow('Signal published', df.format(s.createdAt)),
              _TimeRow('Auto-close (end of day)', df.format(s.closesAt)),
              if (s.closedAt != null) _TimeRow('Closed', df.format(s.closedAt!)),
              _TimeRow('Best move in your favour', '${s.maxFavorablePips.toStringAsFixed(1)} pips'),
            ]),
          ),
        ),
        const SizedBox(height: 18),
        const Text(
          'Educational signal generated automatically from technical rules. Not financial advice. '
          'Trading involves a high risk of loss.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AppColors.faint, fontSize: 11, height: 1.4),
        ),
      ],
    );
  }
}

class _LevelRow extends StatelessWidget {
  final String label, value;
  final String? sub;
  final Color color;
  final VoidCallback onTap;
  final bool last;
  const _LevelRow(this.label, this.value, this.color, this.sub, this.onTap, {this.last = false});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(border: last ? null : const Border(bottom: BorderSide(color: AppColors.border))),
        child: Row(children: [
          Expanded(child: Text(label, style: const TextStyle(color: AppColors.muted))),
          Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
            Text(value, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: color, fontFeatures: tabular)),
            if (sub != null) Text(sub!, style: const TextStyle(color: AppColors.faint, fontSize: 11)),
          ]),
          const SizedBox(width: 10),
          const Icon(Icons.copy_rounded, size: 16, color: AppColors.faint),
        ]),
      ),
    );
  }
}

class _TimeRow extends StatelessWidget {
  final String a, b;
  const _TimeRow(this.a, this.b);
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(children: [
          Expanded(child: Text(a, style: const TextStyle(color: AppColors.muted))),
          Text(b, style: const TextStyle(fontWeight: FontWeight.w600)),
        ]),
      );
}

class _Hint extends StatelessWidget {
  final String text;
  const _Hint(this.text);
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: AppColors.blue.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: AppColors.blue.withValues(alpha: 0.25)),
        ),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const Icon(Icons.lightbulb_outline_rounded, size: 18, color: AppColors.blue),
          const SizedBox(width: 10),
          Expanded(child: Text(text, style: const TextStyle(fontSize: 13, height: 1.4))),
        ]),
      );
}

class _RiskCalc extends StatelessWidget {
  final Signal signal;
  final TextEditingController balance, risk;
  final List<PriceTick> prices;
  final VoidCallback onChanged;
  const _RiskCalc(
      {required this.signal, required this.balance, required this.risk, required this.prices, required this.onChanged});

  @override
  Widget build(BuildContext context) {
    final bal = double.tryParse(balance.text) ?? 0;
    final rp = double.tryParse(risk.text) ?? 0;
    final riskUsd = bal * rp / 100;
    final pipVal = pipValueUsdPerLot(signal.pair, prices);
    final lots = signal.riskPips > 0 ? riskUsd / (signal.riskPips * pipVal) : 0.0;
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(children: [
          Row(children: [
            Expanded(
              child: TextField(
                controller: balance,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Balance (USD)'),
                onChanged: (_) => onChanged(),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: TextField(
                controller: risk,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: const InputDecoration(labelText: 'Risk %'),
                onChanged: (_) => onChanged(),
              ),
            ),
          ]),
          const SizedBox(height: 16),
          Row(children: [
            Expanded(child: _Out('Lot size', lots.toStringAsFixed(2), AppColors.green)),
            Expanded(child: _Out('Money at risk', '\$${riskUsd.toStringAsFixed(2)}', AppColors.red)),
            Expanded(child: _Out('Stop', '${signal.riskPips.toStringAsFixed(1)} pips', AppColors.text)),
          ]),
          if (rp > 2) ...[
            const SizedBox(height: 12),
            const Text('⚠️ Risking more than 2% per trade can wipe an account fast.',
                style: TextStyle(color: AppColors.gold, fontSize: 12)),
          ],
        ]),
      ),
    );
  }
}

class _Out extends StatelessWidget {
  final String label, value;
  final Color color;
  const _Out(this.label, this.value, this.color);
  @override
  Widget build(BuildContext context) => Column(children: [
        Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
        const SizedBox(height: 4),
        Text(value, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: color, fontFeatures: tabular)),
      ]);
}
