import 'package:flutter/material.dart';
import '../models.dart';
import '../theme.dart';

class MarketSession {
  final String name;
  final int openUtc, closeUtc; // hours
  final Color color;
  const MarketSession(this.name, this.openUtc, this.closeUtc, this.color);

  bool isOpen(DateTime utc) {
    if (!marketOpen(utc)) return false;
    final h = utc.hour;
    return openUtc < closeUtc ? (h >= openUtc && h < closeUtc) : (h >= openUtc || h < closeUtc);
  }

  DateTime nextOpenLocal(DateTime nowUtc) {
    var d = DateTime.utc(nowUtc.year, nowUtc.month, nowUtc.day, openUtc);
    while (!d.isAfter(nowUtc) || d.weekday == DateTime.saturday) {
      d = d.add(const Duration(days: 1));
    }
    return d.toLocal();
  }
}

/// Approximate standard hours (UTC). Daylight-saving shifts these by ~1h part of the year.
const sessions = [
  MarketSession('Sydney', 21, 6, AppColors.blue),
  MarketSession('Tokyo', 0, 9, Color(0xFFA855F7)),
  MarketSession('London', 7, 16, AppColors.green),
  MarketSession('New York', 12, 21, AppColors.orange),
];

/// Forex trades Sun 21:00 UTC → Fri 21:00 UTC.
bool marketOpen(DateTime utc) {
  final wd = utc.weekday;
  if (wd == DateTime.saturday) return false;
  if (wd == DateTime.friday && utc.hour >= 21) return false;
  if (wd == DateTime.sunday && utc.hour < 21) return false;
  return true;
}

/// Signals are only generated 07:00–19:00 UTC on trading days.
bool signalWindowOpen(DateTime utc) => marketOpen(utc) && utc.hour >= 7 && utc.hour < 19;

double pipSizeOf(String pair) => pair.startsWith('XAU') ? 0.1 : (pair.contains('JPY') ? 0.01 : 0.0001);
double contractSizeOf(String pair) => pair.startsWith('XAU') ? 100 : 100000;

/// Approximate USD value of 1 pip for 1 standard lot, using latest prices when available.
double pipValueUsdPerLot(String pair, List<PriceTick> prices) {
  final parts = pair.split('/');
  final quote = parts[1];
  final inQuote = pipSizeOf(pair) * contractSizeOf(pair);
  if (quote == 'USD') return inQuote;
  double? find(String p) {
    for (final t in prices) {
      if (t.pair == p) return t.price;
    }
    return null;
  }

  final usdQuote = find('USD/$quote');
  if (usdQuote != null && usdQuote > 0) return inQuote / usdQuote;
  final quoteUsd = find('$quote/USD');
  if (quoteUsd != null) return inQuote * quoteUsd;
  // fallbacks when prices are unavailable
  const approx = {'JPY': 150.0, 'CAD': 1.37, 'CHF': 0.88, 'GBP': 0.78, 'AUD': 1.52, 'NZD': 1.68, 'EUR': 0.92};
  return inQuote / (approx[quote] ?? 1.0);
}

const calculatorPairs = [
  'EUR/USD', 'GBP/USD', 'USD/JPY', 'AUD/USD', 'USD/CAD', 'EUR/JPY', 'XAU/USD', 'NZD/USD', 'USD/CHF', 'GBP/JPY',
];
