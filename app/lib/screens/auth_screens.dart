import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config.dart';
import '../main.dart' show AppLogo;
import '../services/app_state.dart';
import '../theme.dart';
import '../widgets/common.dart';

class _AuthScaffold extends StatelessWidget {
  final String title;
  final String subtitle;
  final List<Widget> children;
  final bool showBack;
  const _AuthScaffold({required this.title, required this.subtitle, required this.children, this.showBack = false});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: showBack ? AppBar() : null,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (!showBack) ...[const Center(child: AppLogo(size: 60)), const SizedBox(height: 28)],
                Text(title, style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                Text(subtitle, style: const TextStyle(color: AppColors.muted, height: 1.4)),
                const SizedBox(height: 28),
                ...children,
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

String? _emailV(String? v) =>
    (v == null || !RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v.trim())) ? 'Enter a valid email' : null;
String? _passV(String? v) => (v == null || v.length < 8) ? 'At least 8 characters' : null;

class _PasswordField extends StatefulWidget {
  final TextEditingController controller;
  final String label;
  const _PasswordField(this.controller, {this.label = 'Password'});
  @override
  State<_PasswordField> createState() => _PasswordFieldState();
}

class _PasswordFieldState extends State<_PasswordField> {
  bool _hide = true;
  @override
  Widget build(BuildContext context) => TextFormField(
        controller: widget.controller,
        obscureText: _hide,
        validator: _passV,
        decoration: InputDecoration(
          labelText: widget.label,
          prefixIcon: const Icon(Icons.lock_outline_rounded, color: AppColors.muted),
          suffixIcon: IconButton(
            icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined, color: AppColors.muted),
            onPressed: () => setState(() => _hide = !_hide),
          ),
        ),
      );
}

// --------------------------------------------------------------------- Login
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<AppState>().login(_email.text.trim(), _pass.text);
    } catch (e) {
      if (mounted) toast(context, e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      title: 'Welcome back',
      subtitle: 'Sign in to get today\'s ${AppConfig.appName}.',
      children: [
        Form(
          key: _form,
          child: Column(children: [
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              validator: _emailV,
              decoration: const InputDecoration(
                  labelText: 'Email', prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.muted)),
            ),
            const SizedBox(height: 14),
            _PasswordField(_pass),
          ]),
        ),
        Align(
          alignment: Alignment.centerRight,
          child: TextButton(
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ForgotPasswordScreen())),
            child: const Text('Forgot password?', style: TextStyle(color: AppColors.muted)),
          ),
        ),
        const SizedBox(height: 8),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : const Text('Sign in'),
        ),
        const SizedBox(height: 16),
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          const Text('New here?', style: TextStyle(color: AppColors.muted)),
          TextButton(
            onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RegisterScreen())),
            child: const Text('Create a free account', style: TextStyle(color: AppColors.green, fontWeight: FontWeight.w700)),
          ),
        ]),
      ],
    );
  }
}

// ------------------------------------------------------------------ Register
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});
  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<AppState>().register(_name.text.trim(), _email.text.trim(), _pass.text);
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
    } catch (e) {
      if (mounted) toast(context, e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      showBack: true,
      title: 'Create your account',
      subtitle: 'Free forever. Get signals by push and email, and track every result.',
      children: [
        Form(
          key: _form,
          child: Column(children: [
            TextFormField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              validator: (v) => (v == null || v.trim().length < 2) ? 'Enter your name' : null,
              decoration: const InputDecoration(
                  labelText: 'Full name', prefixIcon: Icon(Icons.person_outline_rounded, color: AppColors.muted)),
            ),
            const SizedBox(height: 14),
            TextFormField(
              controller: _email,
              keyboardType: TextInputType.emailAddress,
              validator: _emailV,
              decoration: const InputDecoration(
                  labelText: 'Email', prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.muted)),
            ),
            const SizedBox(height: 14),
            _PasswordField(_pass),
          ]),
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : const Text('Create account'),
        ),
        const SizedBox(height: 14),
        const Text(
          'By continuing you agree that signals are for education only and trading carries a high risk of loss.',
          textAlign: TextAlign.center,
          style: TextStyle(color: AppColors.faint, fontSize: 12, height: 1.4),
        ),
      ],
    );
  }
}

// ------------------------------------------------------------ Forgot / Reset
class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});
  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  final _email = TextEditingController();
  final _code = TextEditingController();
  final _pass = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _sent = false;
  bool _busy = false;

  Future<void> _send() async {
    if (_emailV(_email.text) != null) {
      toast(context, 'Enter a valid email');
      return;
    }
    setState(() => _busy = true);
    try {
      await context.read<AppState>().requestReset(_email.text.trim());
      if (!mounted) return;
      setState(() => _sent = true);
      toast(context, 'If that email has an account, a 6-digit code is on its way.');
    } catch (e) {
      if (mounted) toast(context, e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _reset() async {
    if (!_form.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await context.read<AppState>().resetPassword(_email.text.trim(), _code.text.trim(), _pass.text);
      if (mounted) Navigator.of(context).popUntil((r) => r.isFirst);
    } catch (e) {
      if (mounted) toast(context, e.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return _AuthScaffold(
      showBack: true,
      title: 'Reset password',
      subtitle: _sent ? 'Enter the code we emailed you and choose a new password.' : 'We\'ll email you a 6-digit reset code.',
      children: [
        TextField(
          controller: _email,
          enabled: !_sent,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
              labelText: 'Email', prefixIcon: Icon(Icons.mail_outline_rounded, color: AppColors.muted)),
        ),
        if (_sent)
          Form(
            key: _form,
            child: Column(children: [
              const SizedBox(height: 14),
              TextFormField(
                controller: _code,
                keyboardType: TextInputType.number,
                maxLength: 6,
                validator: (v) => (v == null || v.trim().length != 6) ? 'Enter the 6-digit code' : null,
                decoration: const InputDecoration(
                    labelText: 'Code', counterText: '', prefixIcon: Icon(Icons.pin_outlined, color: AppColors.muted)),
              ),
              const SizedBox(height: 14),
              _PasswordField(_pass, label: 'New password'),
            ]),
          ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: _busy ? null : (_sent ? _reset : _send),
          child: _busy
              ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
              : Text(_sent ? 'Set new password' : 'Send code'),
        ),
      ],
    );
  }
}
