import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models.dart';
import 'api.dart';
import 'push.dart';

/// Auth + user preferences. Single source of truth for the UI.
class AppState extends ChangeNotifier {
  late SharedPreferences _prefs;
  AppUser? user;
  bool ready = false;

  bool get loggedIn => user != null;
  bool get onboarded => _prefs.getBool('onboarded') ?? false;
  bool get disclaimerAccepted => _prefs.getBool('disclaimer_ok') ?? false;
  bool get developerOffers => _prefs.getBool('dev_offers') ?? true;
  bool get resultAlerts => _prefs.getBool('result_alerts') ?? true;
  int get launchCount => _prefs.getInt('launches') ?? 0;

  Future<void> init() async {
    _prefs = await SharedPreferences.getInstance();
    await _prefs.setInt('launches', launchCount + 1);
    Api.instance.onUnauthorized = () => logout(remote: false);
    final token = _prefs.getString('token');
    if (token != null) {
      Api.instance.token = token;
      try {
        final r = await Api.instance.get('/me');
        user = AppUser.fromJson(r['user'] as Map<String, dynamic>);
        await _syncPush();
      } on ApiException catch (e) {
        if (e.status == 401) {
          await PushService.instance.stop();
          await _clearSession();
        } else {
          // offline: keep the session, user data will load later
          user = AppUser(id: 0, name: _prefs.getString('name') ?? '', email: _prefs.getString('email') ?? '',
              emailAlerts: true, pushAlerts: true, eliteOnly: _prefs.getBool('elite_only') ?? false);
        }
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<void> setOnboarded() async {
    await _prefs.setBool('onboarded', true);
    notifyListeners();
  }

  Future<void> acceptDisclaimer() async {
    await _prefs.setBool('disclaimer_ok', true);
    notifyListeners();
  }

  Future<void> setDeveloperOffers(bool v) async {
    await _prefs.setBool('dev_offers', v);
    notifyListeners();
  }

  Future<void> setResultAlerts(bool v) async {
    await _prefs.setBool('result_alerts', v);
    await _syncPush();
    notifyListeners();
  }

  int getInt(String k) => _prefs.getInt(k) ?? 0;
  Future<void> setInt(String k, int v) => _prefs.setInt(k, v);

  // ---------------------------------------------------------------- auth
  Future<void> login(String email, String password) async =>
      _handleAuth(await Api.instance.post('/auth/login', {'email': email, 'password': password}));

  Future<void> register(String name, String email, String password) async =>
      _handleAuth(await Api.instance.post('/auth/register', {'name': name, 'email': email, 'password': password}));

  Future<void> requestReset(String email) => Api.instance.post('/auth/forgot', {'email': email});

  Future<void> resetPassword(String email, String code, String password) async => _handleAuth(
      await Api.instance.post('/auth/reset', {'email': email, 'code': code, 'password': password}));

  Future<void> _handleAuth(Map<String, dynamic> r) async {
    final token = r['token'] as String;
    Api.instance.token = token;
    await _prefs.setString('token', token);
    _setUser(AppUser.fromJson(r['user'] as Map<String, dynamic>));
    await _syncPush();
  }

  void _setUser(AppUser u) {
    user = u;
    _prefs.setString('name', u.name);
    _prefs.setString('email', u.email);
    _prefs.setBool('elite_only', u.eliteOnly);
    notifyListeners();
  }

  Future<void> updateSettings({bool? emailAlerts, bool? pushAlerts, bool? eliteOnly}) async {
    final r = await Api.instance.post('/me/settings', {
      if (emailAlerts != null) 'email_alerts': emailAlerts,
      if (pushAlerts != null) 'push_alerts': pushAlerts,
      if (eliteOnly != null) 'elite_only': eliteOnly,
    });
    _setUser(AppUser.fromJson(r['user'] as Map<String, dynamic>));
    await _syncPush();
  }

  Future<void> logout({bool remote = true}) async {
    if (remote) {
      try {
        await Api.instance.post('/auth/logout');
      } catch (_) {}
    }
    await PushService.instance.stop();
    await _clearSession();
    notifyListeners();
  }

  Future<void> deleteAccount() async {
    await Api.instance.delete('/me');
    await PushService.instance.stop();
    await _clearSession();
    notifyListeners();
  }

  Future<void> _clearSession() async {
    user = null;
    Api.instance.token = null;
    await _prefs.remove('token');
    for (final key in ['name', 'email', 'elite_only', 'push_enabled', 'result_alerts', 'disclaimer_ok']) {
      await _prefs.remove(key);
    }
  }

  Future<void> _syncPush() async {
    final u = user;
    if (u == null) return;
    // The background job reads these from SharedPreferences.
    await _prefs.setBool('push_enabled', u.pushAlerts);
    await _prefs.setBool('elite_only', u.eliteOnly);
    await PushService.instance.apply(enabled: u.pushAlerts);
  }
}
