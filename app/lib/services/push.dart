import 'dart:async';
import 'dart:convert';
import 'dart:io' show Platform;
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:workmanager/workmanager.dart';
import '../models.dart';
import 'api.dart';

/// Background alerts without Firebase:
///  - WorkManager wakes the app about every 15 min (Android's minimum) and runs [pollAndNotify].
///  - New signals and TP1 / win / loss changes are shown as local notifications.
/// The same poll also runs every couple of minutes while the app is open.
const _taskName = 'dayfx_poll';
const _kKnown = 'known_status';

final _plugin = FlutterLocalNotificationsPlugin();
bool _pluginReady = false;

Future<void> _initPlugin({void Function(NotificationResponse)? onTap}) async {
  if (_pluginReady) return;
  await _plugin.initialize(
    const InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(),
    ),
    onDidReceiveNotificationResponse: onTap,
  );
  final android = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
  await android?.createNotificationChannel(const AndroidNotificationChannel(
    'signals',
    'New signals',
    description: 'Alerts when a new day-trading signal is published',
    importance: Importance.high,
  ));
  await android?.createNotificationChannel(const AndroidNotificationChannel(
    'results',
    'Trade results',
    description: 'TP1 hit, wins, losses and closed trades',
    importance: Importance.defaultImportance,
  ));
  _pluginReady = true;
}

NotificationDetails _details(String channel) => NotificationDetails(
      android: AndroidNotificationDetails(
        channel,
        channel == 'signals' ? 'New signals' : 'Trade results',
        importance: channel == 'signals' ? Importance.high : Importance.defaultImportance,
        priority: channel == 'signals' ? Priority.high : Priority.defaultPriority,
        color: const Color(0xFF22C55E),
      ),
      iOS: const DarwinNotificationDetails(),
    );

/// Forex is closed Fri 21:00 UTC to Sun 21:00 UTC; the engine only runs 06:00-22:00 UTC.
bool _worthPolling(DateTime utc) {
  final wd = utc.weekday, h = utc.hour;
  if (wd == DateTime.saturday) return false;
  if (wd == DateTime.friday && h >= 22) return false;
  if (wd == DateTime.sunday) return false;
  return h >= 6 && h < 22;
}

/// One poll. Returns the number of notifications shown. Safe to call from the UI or the background isolate.
Future<int> pollAndNotify({bool force = false}) async {
  final prefs = await SharedPreferences.getInstance();
  await prefs.reload();
  final token = prefs.getString('token');
  if (token == null || !(prefs.getBool('push_enabled') ?? true)) return 0;
  if (!force && !_worthPolling(DateTime.now().toUtc())) return 0;

  Api.instance.token = token;
  final eliteOnly = prefs.getBool('elite_only') ?? false;
  final wantResults = prefs.getBool('result_alerts') ?? true;

  final List<Signal> active, recent;
  try {
    active = await Api.instance.signals(scope: 'active', limit: 30);
    recent = await Api.instance.signals(scope: 'history', limit: 15);
  } catch (_) {
    return 0; // offline or server hiccup: try again next run
  }

  final byId = <int, Signal>{for (final s in [...recent, ...active]) s.id: s};
  final raw = prefs.getString(_kKnown);
  final known = raw == null ? null : (jsonDecode(raw) as Map<String, dynamic>);

  var shown = 0;
  if (known != null) {
    await _initPlugin();
    final newest = byId.values.toList()..sort((a, b) => a.id.compareTo(b.id));
    for (final s in newest) {
      if (eliteOnly && !s.isElite) continue;
      final prev = known['${s.id}'] as String?;
      if (prev == null) {
        // new signal: only worth an alert while it is still running
        if (s.isOpen) {
          await _plugin.show(
            s.id,
            '${s.direction} ${s.pair} · ${tierText(s.tier)} (${s.score}/100)',
            'Entry ${s.fmt(s.entry)}  SL ${s.fmt(s.sl)}  TP1 ${s.fmt(s.tp1)}  TP2 ${s.fmt(s.tp2)}',
            _details('signals'),
            payload: '${s.id}',
          );
          shown++;
        }
      } else if (prev != s.status && wantResults) {
        await _plugin.show(
          100000 + s.id,
          '${s.direction} ${s.pair}: ${resultText(s.status)}',
          s.resultPips == null ? 'Signal #${s.id}' : 'Signal #${s.id} · ${s.resultPips! >= 0 ? '+' : ''}${s.resultPips!.toStringAsFixed(1)} pips',
          _details('results'),
          payload: '${s.id}',
        );
        shown++;
      }
    }
  }

  // Remember the latest statuses (first run only seeds, so installing never spams old signals).
  final ids = byId.keys.toList()..sort();
  final keep = ids.length > 60 ? ids.sublist(ids.length - 60) : ids;
  await prefs.setString(_kKnown, jsonEncode({for (final id in keep) '$id': byId[id]!.status}));
  return shown;
}

String tierText(String t) => t == 'ELITE' ? 'ELITE' : (t == 'HIGH' ? 'HIGH PROB' : 'STANDARD');

String resultText(String status) => switch (status) {
      'TP1' => 'TP1 hit ✅ stop moved to entry',
      'WIN' => 'WIN ✅',
      'LOSS' => 'Stopped out ❌',
      'BREAKEVEN' => 'Closed at breakeven',
      'CLOSED_EOD' => 'Closed at end of day',
      _ => status,
    };

/// Entry point for WorkManager's background isolate.
@pragma('vm:entry-point')
void callbackDispatcher() {
  Workmanager().executeTask((task, inputData) async {
    try {
      await pollAndNotify();
    } catch (_) {}
    return true;
  });
}

class PushService {
  PushService._();
  static final PushService instance = PushService._();

  final _taps = StreamController<int>.broadcast();

  /// Emits a signal id when the user taps a notification.
  Stream<int> get taps => _taps.stream;

  /// Background polling works on Android. iOS only gets alerts while the app is open.
  bool get available => Platform.isAndroid;

  void _handleTap(NotificationResponse r) {
    final id = int.tryParse(r.payload ?? '');
    if (id != null) _taps.add(id);
  }

  Future<void> init() async {
    try {
      await _initPlugin(onTap: _handleTap);
      if (Platform.isAndroid) await Workmanager().initialize(callbackDispatcher);
      final launch = await _plugin.getNotificationAppLaunchDetails();
      if (launch?.didNotificationLaunchApp == true) {
        final id = int.tryParse(launch?.notificationResponse?.payload ?? '');
        if (id != null) Future.delayed(const Duration(milliseconds: 800), () => _taps.add(id));
      }
    } catch (e) {
      debugPrint('Notifications init failed: $e');
    }
  }

  Future<bool> requestPermission() async {
    try {
      final android = _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>();
      if (android != null) return await android.requestNotificationsPermission() ?? true;
      final ios = _plugin.resolvePlatformSpecificImplementation<IOSFlutterLocalNotificationsPlugin>();
      return await ios?.requestPermissions(alert: true, badge: true, sound: true) ?? true;
    } catch (_) {
      return false;
    }
  }

  /// Turn background alerts on or off. The poll reads its filters (Elite, results) from SharedPreferences.
  Future<void> apply({required bool enabled}) async {
    try {
      if (!enabled) {
        if (Platform.isAndroid) await Workmanager().cancelByUniqueName(_taskName);
        return;
      }
      await requestPermission();
      if (Platform.isAndroid) {
        await Workmanager().registerPeriodicTask(
          _taskName,
          _taskName,
          frequency: const Duration(minutes: 15),
          existingWorkPolicy: ExistingPeriodicWorkPolicy.update,
          constraints: Constraints(networkType: NetworkType.connected),
          backoffPolicy: BackoffPolicy.linear,
          backoffPolicyDelay: const Duration(minutes: 5),
        );
      }
      unawaited(pollAndNotify(force: true)); // seeds on first run, alerts afterwards
    } catch (e) {
      debugPrint('Could not schedule background alerts: $e');
    }
  }

  /// Poll right now (used while the app is open). Returns how many alerts were shown.
  Future<int> pollNow() => pollAndNotify();

  /// Logout / delete account.
  Future<void> stop() async {
    try {
      if (Platform.isAndroid) await Workmanager().cancelByUniqueName(_taskName);
    } catch (_) {}
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kKnown);
    try {
      await _plugin.cancelAll();
    } catch (_) {}
  }

  Future<void> showTest() async {
    await _initPlugin();
    await requestPermission();
    await _plugin.show(
      999999,
      'DayFX alerts are working ✅',
      'You\'ll get a notification like this when a new signal appears.',
      _details('signals'),
    );
  }
}
