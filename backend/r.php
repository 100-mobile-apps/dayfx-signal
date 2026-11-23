<?php
// /r/CODE -> /app/?ref=CODE  (the web app records the click and keeps the code for sign-up)
$c = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? ''));
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
header('Location: ' . $base . '/app/' . ($c !== '' ? '?ref=' . rawurlencode(strtoupper($c)) : ''), true, 302);
