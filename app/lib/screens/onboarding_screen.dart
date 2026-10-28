import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/app_state.dart';
import '../theme.dart';

class OnboardingScreen extends StatefulWidget {
  const OnboardingScreen({super.key});
  @override
  State<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends State<OnboardingScreen> {
  final _pc = PageController();
  int _page = 0;

  static const _pages = [
    (
      icon: Icons.candlestick_chart_rounded,
      color: AppColors.green,
      title: 'Forex day-trading signals',
      body: 'Intraday setups on the majors and gold, built from price action: trend pullbacks, break & retest and the London breakout. Every trade closes the same day.',
    ),
    (
      icon: Icons.bolt_rounded,
      color: AppColors.orange,
      title: 'News-aware by design',
      body: 'Signals pause around high-impact news to protect you from spikes, then the News Momentum strategy trades the clean move after the release.',
    ),
    (
      icon: Icons.workspace_premium_rounded,
      color: AppColors.gold,
      title: 'Elite mode',
      body: 'Every setup gets a 0–100 confluence score. Switch on Elite mode to only receive the strongest 85+ setups — fewer trades, higher quality.',
    ),
    (
      icon: Icons.verified_rounded,
      color: AppColors.blue,
      title: 'Results you can verify',
      body: 'Every signal is tracked automatically against live prices — wins and losses. Daily, weekly and monthly reports, nothing hidden.',
    ),
  ];

  void _next() {
    if (_page == _pages.length - 1) {
      context.read<AppState>().setOnboarded();
    } else {
      _pc.nextPage(duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Column(children: [
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: () => context.read<AppState>().setOnboarded(),
              child: const Text('Skip', style: TextStyle(color: AppColors.muted)),
            ),
          ),
          Expanded(
            child: PageView.builder(
              controller: _pc,
              itemCount: _pages.length,
              onPageChanged: (i) => setState(() => _page = i),
              itemBuilder: (_, i) {
                final p = _pages[i];
                return Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 32),
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                    Container(
                      width: 120,
                      height: 120,
                      decoration: BoxDecoration(
                        color: p.color.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(36),
                      ),
                      child: Icon(p.icon, size: 60, color: p.color),
                    ),
                    const SizedBox(height: 40),
                    Text(p.title,
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, height: 1.2)),
                    const SizedBox(height: 14),
                    Text(p.body,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: AppColors.muted, fontSize: 15, height: 1.5)),
                  ]),
                );
              },
            ),
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(
              _pages.length,
              (i) => AnimatedContainer(
                duration: const Duration(milliseconds: 250),
                margin: const EdgeInsets.symmetric(horizontal: 4),
                width: i == _page ? 22 : 8,
                height: 8,
                decoration: BoxDecoration(
                  color: i == _page ? AppColors.green : AppColors.border,
                  borderRadius: BorderRadius.circular(8),
                ),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(24),
            child: FilledButton(
              onPressed: _next,
              child: Text(_page == _pages.length - 1 ? 'Get started' : 'Next'),
            ),
          ),
        ]),
      ),
    );
  }
}
