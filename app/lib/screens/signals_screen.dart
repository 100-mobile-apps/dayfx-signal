import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../models.dart';
import '../services/api.dart';
import '../services/app_state.dart';
import '../theme.dart';
import '../utils/market.dart';
import '../widgets/common.dart';
import '../widgets/signal_card.dart';

class SignalsScreen extends StatefulWidget {
  const SignalsScreen({super.key});
  @override
  State<SignalsScreen> createState() => SignalsScreenState();
}

class SignalsScreenState extends State<SignalsScreen> {
  String _scope = 'active';
  bool? _eliteFilter; // null = follow account setting
  List<Signal> _items = [];
  Stats? _week;
  String? _error;
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  Timer? _timer;

  bool _accountElite = false;
  bool get _elite => _eliteFilter ?? _accountElite;

  @override
  void initState() {
    super.initState();
    _accountElite = context.read<AppState>().user?.eliteOnly ?? false;
    refresh();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) {
      if (_scope == 'active') refresh(silent: true);
      if (mounted) setState(() {}); // tick session clock
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> refresh({bool silent = false}) async {
    if (!silent) setState(() => _loading = true);
    try {
      final results = await Future.wait([
        Api.instance.signals(scope: _scope, eliteOnly: _elite),
        Api.instance.stats('week', eliteOnly: _elite),
      ]);
      if (!mounted) return;
      setState(() {
        _items = results[0] as List<Signal>;
        _week = results[1] as Stats;
        _error = null;
        _hasMore = _scope == 'history' && _items.length >= 30;
      });
    } catch (e) {
      if (mounted && !silent) setState(() => _error = e.toString());
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore || _items.isEmpty) return;
    setState(() => _loadingMore = true);
    try {
      final more = await Api.instance.signals(scope: _scope, eliteOnly: _elite, beforeId: _items.last.id);
      if (!mounted) return;
      setState(() {
        _items.addAll(more);
        _hasMore = more.length >= 30;
      });
    } catch (_) {
    } finally {
      if (mounted) setState(() => _loadingMore = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AppState>().user;
    if ((user?.eliteOnly ?? false) != _accountElite) {
      _accountElite = user?.eliteOnly ?? false;
      if (_eliteFilter == null) WidgetsBinding.instance.addPostFrameCallback((_) => refresh());
    }
    final nowUtc = DateTime.now().toUtc();
    return SafeArea(
      child: RefreshIndicator(
        color: AppColors.green,
        backgroundColor: AppColors.surface,
        onRefresh: refresh,
        child: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.pixels > n.metrics.maxScrollExtent - 300) _loadMore();
            return false;
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
            physics: const AlwaysScrollableScrollPhysics(),
            children: [
              Row(children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('Hi ${user?.name.split(' ').first ?? 'trader'} 👋',
                        style: const TextStyle(color: AppColors.muted, fontSize: 14)),
                    const SizedBox(height: 2),
                    const Text('Day-trade signals', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
                  ]),
                ),
                _MarketBadge(nowUtc),
              ]),
              const SizedBox(height: 14),
              _SessionStrip(nowUtc),
              const SizedBox(height: 12),
              if (_week != null) _WeekBanner(_week!.summary, _elite),
              const SizedBox(height: 16),
              Row(children: [
                Expanded(
                  child: SegmentedButton<String>(
                    segments: const [
                      ButtonSegment(value: 'active', label: Text('Live')),
                      ButtonSegment(value: 'history', label: Text('History')),
                    ],
                    selected: {_scope},
                    showSelectedIcon: false,
                    style: SegmentedButton.styleFrom(
                      backgroundColor: AppColors.surface,
                      selectedBackgroundColor: AppColors.surface2,
                      selectedForegroundColor: AppColors.text,
                      foregroundColor: AppColors.muted,
                      side: const BorderSide(color: AppColors.border),
                    ),
                    onSelectionChanged: (s) {
                      setState(() => _scope = s.first);
                      refresh();
                    },
                  ),
                ),
                const SizedBox(width: 10),
                FilterChip(
                  selected: _elite,
                  onSelected: (v) {
                    setState(() => _eliteFilter = v);
                    refresh();
                  },
                  avatar: Icon(Icons.workspace_premium_rounded, size: 16, color: _elite ? AppColors.bg : AppColors.gold),
                  label: const Text('Elite'),
                  showCheckmark: false,
                  selectedColor: AppColors.gold,
                  backgroundColor: AppColors.surface,
                  labelStyle: TextStyle(color: _elite ? AppColors.bg : AppColors.text, fontWeight: FontWeight.w700),
                  side: const BorderSide(color: AppColors.border),
                ),
              ]),
              const SizedBox(height: 16),
              ..._content(nowUtc),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _content(DateTime nowUtc) {
    if (_loading && _items.isEmpty) {
      return const [Padding(padding: EdgeInsets.all(48), child: Center(child: CircularProgressIndicator()))];
    }
    if (_error != null && _items.isEmpty) return [ErrorRetry(message: _error!, onRetry: refresh)];
    if (_items.isEmpty) {
      final open = signalWindowOpen(nowUtc);
      return [
        EmptyState(
          icon: _scope == 'active' ? Icons.radar_rounded : Icons.history_rounded,
          title: _scope == 'active' ? 'Scanning the market…' : 'No closed signals yet',
          message: _scope == 'active'
              ? (open
                  ? 'No setup meets the ${_elite ? 'Elite' : 'quality'} bar right now. The engine checks every 15 minutes — you\'ll get a push the moment one appears.'
                  : 'Signals are generated during London and New York hours on trading days. Get ready for the next session.')
              : 'Closed trades and their results will appear here.',
        ),
      ];
    }
    return [
      for (final s in _items) Padding(padding: const EdgeInsets.only(bottom: 12), child: SignalCard(s)),
      if (_loadingMore) const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
    ];
  }
}

class _MarketBadge extends StatelessWidget {
  final DateTime utc;
  const _MarketBadge(this.utc);
  @override
  Widget build(BuildContext context) {
    final open = marketOpen(utc);
    return Pill(open ? 'MARKET OPEN' : 'MARKET CLOSED',
        color: open ? AppColors.green : AppColors.muted, icon: Icons.circle);
  }
}

class _SessionStrip extends StatelessWidget {
  final DateTime utc;
  const _SessionStrip(this.utc);
  @override
  Widget build(BuildContext context) {
    return Row(children: [
      for (final s in sessions)
        Expanded(
          child: Container(
            margin: EdgeInsets.only(right: s == sessions.last ? 0 : 8),
            padding: const EdgeInsets.symmetric(vertical: 10),
            decoration: BoxDecoration(
              color: s.isOpen(utc) ? s.color.withValues(alpha: 0.12) : AppColors.surface,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: s.isOpen(utc) ? s.color.withValues(alpha: 0.4) : AppColors.border),
            ),
            child: Column(children: [
              Text(s.name, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: s.isOpen(utc) ? s.color : AppColors.muted)),
              const SizedBox(height: 2),
              Text(s.isOpen(utc) ? 'Open' : 'Closed', style: const TextStyle(fontSize: 11, color: AppColors.faint)),
            ]),
          ),
        ),
    ]);
  }
}

class _WeekBanner extends StatelessWidget {
  final Agg a;
  final bool elite;
  const _WeekBanner(this.a, this.elite);
  @override
  Widget build(BuildContext context) {
    final wr = a.winRate;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(16),
        gradient: LinearGradient(
          colors: [AppColors.green.withValues(alpha: 0.16), AppColors.surface],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('This week${elite ? ' · Elite' : ''}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
            const SizedBox(height: 4),
            Text(wr == null ? 'No closed trades yet' : '${wr.toStringAsFixed(0)}% win rate',
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, fontFeatures: tabular)),
            const SizedBox(height: 2),
            Text('${a.wins}W · ${a.losses}L${a.breakeven > 0 ? ' · ${a.breakeven} BE' : ''}',
                style: const TextStyle(color: AppColors.muted, fontSize: 12, fontFeatures: tabular)),
          ]),
        ),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(signedPips(a.pips),
              style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  fontFeatures: tabular,
                  color: a.pips >= 0 ? AppColors.green : AppColors.red)),
          const Text('tracked live', style: TextStyle(color: AppColors.faint, fontSize: 11)),
        ]),
      ]),
    );
  }
}
