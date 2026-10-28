import 'dart:async';
import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config.dart';
import '../models.dart';

class ApiException implements Exception {
  final String message;
  final int status;
  ApiException(this.message, [this.status = 0]);
  @override
  String toString() => message;
}

class Api {
  Api._();
  static final Api instance = Api._();

  String? token;
  void Function()? onUnauthorized;

  Uri _uri(String path, [Map<String, String>? q]) {
    final base = AppConfig.apiBase;
    if (base.endsWith('?r=')) {
      final query = q == null ? '' : '&${Uri(queryParameters: q).query}';
      return Uri.parse('$base$path$query');
    }
    return Uri.parse('$base$path').replace(queryParameters: q);
  }

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<Map<String, dynamic>> _send(Future<http.Response> req) async {
    http.Response res;
    try {
      res = await req.timeout(const Duration(seconds: 20));
    } on TimeoutException {
      throw ApiException('The server is taking too long. Check your connection.');
    } catch (_) {
      throw ApiException('No internet connection.');
    }
    Map<String, dynamic> body;
    try {
      body = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('Unexpected server response (${res.statusCode}).', res.statusCode);
    }
    if (res.statusCode == 401 && token != null) onUnauthorized?.call();
    if (res.statusCode >= 400) {
      throw ApiException((body['error'] ?? 'Something went wrong').toString(), res.statusCode);
    }
    return body;
  }

  Future<Map<String, dynamic>> get(String path, [Map<String, String>? q]) =>
      _send(http.get(_uri(path, q), headers: _headers));
  Future<Map<String, dynamic>> post(String path, [Map<String, dynamic>? data]) =>
      _send(http.post(_uri(path), headers: _headers, body: jsonEncode(data ?? {})));
  Future<Map<String, dynamic>> delete(String path) => _send(http.delete(_uri(path), headers: _headers));

  // ------------------------------------------------------------------ endpoints
  Future<List<Signal>> signals({String scope = 'active', bool eliteOnly = false, int? beforeId, int limit = 30}) async {
    final r = await get('/signals', {
      'scope': scope,
      'limit': '$limit',
      if (eliteOnly) 'tier': 'elite',
      if (beforeId != null) 'before_id': '$beforeId',
    });
    return (r['signals'] as List).map((e) => Signal.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<Signal> signal(int id) async =>
      Signal.fromJson((await get('/signals/$id'))['signal'] as Map<String, dynamic>);

  Future<Stats> stats(String period, {bool eliteOnly = false}) async =>
      Stats.fromJson(await get('/stats', {'period': period, if (eliteOnly) 'tier': 'elite'}));

  Future<List<NewsEvent>> news() async {
    final r = await get('/news');
    return (r['events'] as List).map((e) => NewsEvent.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<PriceTick>> prices() async {
    final r = await get('/prices');
    final p = r['prices'];
    if (p is! Map) return [];
    return p.entries
        .map((e) => PriceTick(
              e.key as String,
              ((e.value as Map)['price'] as num).toDouble(),
              (((e.value as Map)['change_pct'] ?? 0) as num).toDouble(),
            ))
        .toList();
  }
}
