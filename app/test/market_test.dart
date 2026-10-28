import 'package:dayfx_signals/models.dart';
import 'package:dayfx_signals/utils/market.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('forex market closes on the UTC weekend', () {
    expect(marketOpen(DateTime.utc(2026, 10, 23, 20, 59)), isTrue);
    expect(marketOpen(DateTime.utc(2026, 10, 23, 21)), isFalse);
    expect(marketOpen(DateTime.utc(2026, 10, 24, 12)), isFalse);
    expect(marketOpen(DateTime.utc(2026, 10, 25, 20)), isFalse);
    expect(marketOpen(DateTime.utc(2026, 10, 25, 21)), isTrue);
  });

  test('signals are generated only in the intraday UTC window', () {
    expect(signalWindowOpen(DateTime.utc(2026, 10, 26, 6, 59)), isFalse);
    expect(signalWindowOpen(DateTime.utc(2026, 10, 26, 7)), isTrue);
    expect(signalWindowOpen(DateTime.utc(2026, 10, 26, 19)), isFalse);
  });

  test('pip values use live USD conversion for non-USD quotes', () {
    expect(pipValueUsdPerLot('EUR/USD', []), closeTo(10, 0.001));
    expect(pipValueUsdPerLot('USD/JPY', [PriceTick('USD/JPY', 150, 0)]), closeTo(6.667, 0.001));
  });
}
