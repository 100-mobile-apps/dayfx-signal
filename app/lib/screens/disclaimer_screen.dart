import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/app_state.dart';
import '../theme.dart';

class DisclaimerScreen extends StatefulWidget {
  final bool requireAccept;
  const DisclaimerScreen({super.key, this.requireAccept = false});
  @override
  State<DisclaimerScreen> createState() => _DisclaimerScreenState();
}

class _DisclaimerScreenState extends State<DisclaimerScreen> {
  bool _checked = false;

  static const _points = [
    ('High risk', 'Forex and gold are leveraged products. You can lose more than you expect, quickly. Only trade money you can afford to lose.'),
    ('Education, not advice', 'Signals are generated automatically from technical rules for educational purposes. They are not personal financial advice.'),
    ('No guarantees', 'No strategy wins every trade. Past results — including the tracked results in this app — do not guarantee future results.'),
    ('How results are tracked', 'Results use real market prices without spread or commission. A trade counts as a win once TP1 is hit (half closed, stop moved to entry). Your broker\'s fills may differ.'),
    ('Manage your risk', 'Always use the stop loss. Risk 1–2% of your account per trade at most — the lot size calculator helps you do this.'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Risk disclaimer'), automaticallyImplyLeading: !widget.requireAccept),
      body: SafeArea(
        child: Column(children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                for (final p in _points)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 16),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      const Padding(
                        padding: EdgeInsets.only(top: 2),
                        child: Icon(Icons.shield_outlined, color: AppColors.gold, size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(p.$1, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                          const SizedBox(height: 4),
                          Text(p.$2, style: const TextStyle(color: AppColors.muted, height: 1.45)),
                        ]),
                      ),
                    ]),
                  ),
              ],
            ),
          ),
          if (widget.requireAccept)
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
              child: Column(children: [
                CheckboxListTile(
                  value: _checked,
                  onChanged: (v) => setState(() => _checked = v ?? false),
                  activeColor: AppColors.green,
                  controlAffinity: ListTileControlAffinity.leading,
                  contentPadding: EdgeInsets.zero,
                  title: const Text('I understand the risks of trading', style: TextStyle(fontSize: 14)),
                ),
                const SizedBox(height: 8),
                FilledButton(
                  onPressed: _checked ? () => context.read<AppState>().acceptDisclaimer() : null,
                  child: const Text('Continue'),
                ),
              ]),
            ),
        ]),
      ),
    );
  }
}
