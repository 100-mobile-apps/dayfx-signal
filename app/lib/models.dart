double _d(dynamic v) => v == null ? 0 : (v as num).toDouble();

class AppUser {
  final int id;
  final String name;
  final String email;
  final bool emailAlerts;
  final bool pushAlerts;
  final bool eliteOnly;

  AppUser({
    required this.id,
    required this.name,
    required this.email,
    required this.emailAlerts,
    required this.pushAlerts,
    required this.eliteOnly,
  });

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: j['id'] as int,
        name: j['name'] as String,
        email: j['email'] as String,
        emailAlerts: j['email_alerts'] == true,
        pushAlerts: j['push_alerts'] == true,
        eliteOnly: j['elite_only'] == true,
      );
}

class Signal {
  final int id;
  final String pair;
  final String direction;
  final String strategy;
  final String strategyName;
  final String tier;
  final int score;
  final double entry, sl, tp1, tp2;
  final List<String> reasons;
  final String status;
  final double? resultPips;
  final double maxFavorablePips;
  final DateTime createdAt;
  final DateTime closesAt;
  final DateTime? closedAt;

  Signal({
    required this.id,
    required this.pair,
    required this.direction,
    required this.strategy,
    required this.strategyName,
    required this.tier,
    required this.score,
    required this.entry,
    required this.sl,
    required this.tp1,
    required this.tp2,
    required this.reasons,
    required this.status,
    required this.resultPips,
    required this.maxFavorablePips,
    required this.createdAt,
    required this.closesAt,
    required this.closedAt,
  });

  bool get isBuy => direction == 'BUY';
  bool get isOpen => status == 'ACTIVE' || status == 'TP1';
  bool get isElite => tier == 'ELITE';

  double get pipSize => pair.startsWith('XAU') ? 0.1 : (pair.contains('JPY') ? 0.01 : 0.0001);
  int get decimals => pair.startsWith('XAU') ? 2 : (pair.contains('JPY') ? 3 : 5);
  double get riskPips => (entry - sl).abs() / pipSize;
  String fmt(double v) => v.toStringAsFixed(decimals);

  factory Signal.fromJson(Map<String, dynamic> j) => Signal(
        id: j['id'] as int,
        pair: j['pair'] as String,
        direction: j['direction'] as String,
        strategy: j['strategy'] as String,
        strategyName: (j['strategy_name'] ?? j['strategy']) as String,
        tier: j['tier'] as String,
        score: j['score'] as int,
        entry: _d(j['entry']),
        sl: _d(j['sl']),
        tp1: _d(j['tp1']),
        tp2: _d(j['tp2']),
        reasons: (j['reasons'] as List? ?? []).map((e) => e.toString()).toList(),
        status: j['status'] as String,
        resultPips: j['result_pips'] == null ? null : _d(j['result_pips']),
        maxFavorablePips: _d(j['max_favorable_pips']),
        createdAt: DateTime.parse(j['created_at'] as String).toLocal(),
        closesAt: DateTime.parse(j['closes_at'] as String).toLocal(),
        closedAt: j['closed_at'] == null ? null : DateTime.parse(j['closed_at'] as String).toLocal(),
      );
}

class NewsEvent {
  final String title, currency, impact;
  final DateTime time;
  final String? forecast, previous;

  NewsEvent({
    required this.title,
    required this.currency,
    required this.impact,
    required this.time,
    this.forecast,
    this.previous,
  });

  factory NewsEvent.fromJson(Map<String, dynamic> j) => NewsEvent(
        title: j['title'] as String,
        currency: j['currency'] as String,
        impact: j['impact'] as String,
        time: DateTime.parse(j['time'] as String).toLocal(),
        forecast: (j['forecast'] as String?)?.isEmpty == true ? null : j['forecast'] as String?,
        previous: (j['previous'] as String?)?.isEmpty == true ? null : j['previous'] as String?,
      );
}

class Agg {
  final String key;
  final String? name;
  final int wins, losses, breakeven, total;
  final double? winRate;
  final double pips;

  Agg({
    required this.key,
    this.name,
    required this.wins,
    required this.losses,
    required this.breakeven,
    required this.total,
    required this.winRate,
    required this.pips,
  });

  factory Agg.fromJson(Map<String, dynamic> j, [String keyField = 'key']) => Agg(
        key: (j[keyField] ?? '').toString(),
        name: j['name'] as String?,
        wins: j['wins'] as int,
        losses: j['losses'] as int,
        breakeven: j['breakeven'] as int,
        total: j['total'] as int,
        winRate: j['win_rate'] == null ? null : _d(j['win_rate']),
        pips: _d(j['pips']),
      );
}

class Stats {
  final Agg summary;
  final List<Agg> byStrategy;
  final List<Agg> byTier;
  final List<Agg> daily;
  final DateTime? engineHeartbeat;

  Stats({
    required this.summary,
    required this.byStrategy,
    required this.byTier,
    required this.daily,
    required this.engineHeartbeat,
  });

  factory Stats.fromJson(Map<String, dynamic> j) => Stats(
        summary: Agg.fromJson({'key': 'all', ...j['summary'] as Map<String, dynamic>}),
        byStrategy: (j['by_strategy'] as List).map((e) => Agg.fromJson(e as Map<String, dynamic>)).toList(),
        byTier: (j['by_tier'] as List).map((e) => Agg.fromJson(e as Map<String, dynamic>)).toList(),
        daily: (j['daily'] as List).map((e) => Agg.fromJson(e as Map<String, dynamic>, 'date')).toList(),
        engineHeartbeat: j['engine_heartbeat'] == null
            ? null
            : DateTime.fromMillisecondsSinceEpoch((j['engine_heartbeat'] as int) * 1000),
      );
}

class PriceTick {
  final String pair;
  final double price;
  final double changePct;

  PriceTick(this.pair, this.price, this.changePct);
}
