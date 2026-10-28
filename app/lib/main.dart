import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'config.dart';
import 'screens/auth_screens.dart';
import 'screens/disclaimer_screen.dart';
import 'screens/home_shell.dart';
import 'screens/onboarding_screen.dart';
import 'screens/signal_detail_screen.dart';
import 'services/app_state.dart';
import 'services/push.dart';
import 'theme.dart';

final navigatorKey = GlobalKey<NavigatorState>();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
    systemNavigationBarColor: AppColors.surface,
  ));
  await PushService.instance.init();
  final state = AppState();
  unawaited(state.init());
  runApp(ChangeNotifierProvider.value(value: state, child: const DayFxApp()));
}

class DayFxApp extends StatefulWidget {
  const DayFxApp({super.key});
  @override
  State<DayFxApp> createState() => _DayFxAppState();
}

class _DayFxAppState extends State<DayFxApp> {
  StreamSubscription<int>? _tapSub;

  @override
  void initState() {
    super.initState();
    _tapSub = PushService.instance.taps.listen((id) {
      navigatorKey.currentState?.push(MaterialPageRoute(builder: (_) => SignalDetailScreen(signalId: id)));
    });
  }

  @override
  void dispose() {
    _tapSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      navigatorKey: navigatorKey,
      theme: buildTheme(),
      home: const _Root(),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    Widget child;
    if (!s.ready) {
      child = const _Splash();
    } else if (!s.onboarded) {
      child = const OnboardingScreen();
    } else if (!s.loggedIn) {
      child = const LoginScreen();
    } else if (!s.disclaimerAccepted) {
      child = const DisclaimerScreen(requireAccept: true);
    } else {
      child = const HomeShell();
    }
    return AnimatedSwitcher(duration: const Duration(milliseconds: 300), child: child);
  }
}

class _Splash extends StatelessWidget {
  const _Splash();
  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          AppLogo(size: 72),
          SizedBox(height: 18),
          Text(AppConfig.appName, style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          SizedBox(height: 6),
          Text('Forex day-trading signals', style: TextStyle(color: AppColors.muted)),
        ]),
      ),
    );
  }
}

class AppLogo extends StatelessWidget {
  final double size;
  const AppLogo({super.key, this.size = 56});
  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.28),
        gradient: const LinearGradient(
          colors: [Color(0xFF16A34A), Color(0xFF22C55E)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: Icon(Icons.candlestick_chart_rounded, color: AppColors.bg, size: size * 0.58),
    );
  }
}
