import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../services/app_state.dart';
import '../services/push.dart';
import '../widgets/dev_promo.dart';
import 'news_screen.dart';
import 'results_screen.dart';
import 'settings_screen.dart';
import 'signals_screen.dart';
import 'tools_screen.dart';

class HomeShell extends StatefulWidget {
  const HomeShell({super.key});
  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tab = 0;
  final _signalsKey = GlobalKey<SignalsScreenState>();
  Timer? _pollTimer;
  Timer? _promoTimer;

  @override
  void initState() {
    super.initState();
    // While the app is open, check every 2 minutes (the background job covers the rest).
    _pollTimer = Timer.periodic(const Duration(minutes: 2), (_) async {
      final n = await PushService.instance.pollNow();
      if (n > 0 && mounted) _signalsKey.currentState?.refresh(silent: true);
    });
    // Promo only after the user has had time to use the app, and only on the Signals tab.
    _promoTimer = Timer(const Duration(seconds: 25), () {
      if (mounted && _tab == 0 && (ModalRoute.of(context)?.isCurrent ?? false)) {
        DevPromo.maybeShow(context, context.read<AppState>());
      }
    });
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _promoTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: IndexedStack(index: _tab, children: [
        SignalsScreen(key: _signalsKey),
        const NewsScreen(),
        const ResultsScreen(),
        const ToolsScreen(),
        const SettingsScreen(),
      ]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.show_chart_rounded), label: 'Signals'),
          NavigationDestination(icon: Icon(Icons.event_note_rounded), label: 'News'),
          NavigationDestination(icon: Icon(Icons.insights_rounded), label: 'Results'),
          NavigationDestination(icon: Icon(Icons.calculate_outlined), label: 'Tools'),
          NavigationDestination(icon: Icon(Icons.settings_outlined), label: 'Settings'),
        ],
      ),
    );
  }
}
