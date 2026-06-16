<?php
// public/api/install.php — INSTALADOR DE UN SOLO USO (prod). Aplica schema.sql (CREATE IF NOT EXISTS,
// idempotente, NO borra nada) sobre la BD MySQL nueva. Protegido con el cron_token.
// Uso:  https://agentsellingpanel.conexiatec.com/api/install.php?token=<cron_token>
// DESPUÉS DE USARLO: BORRA este archivo del servidor.
require __DIR__ . '/_bootstrap.php';

$token = (string)(cfg()['app']['cron_token'] ?? '');
$given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '' || !hash_equals($token, $given)) { http_response_code(403); echo 'forbidden'; exit; }
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/schema.sql';
if (!is_file($file)) { echo "ERROR: no se encuentra schema.sql\n"; exit; }

$sql = (string)file_get_contents($file);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // quita comentarios de línea
$stmts = array_filter(array_map('trim', explode(';', $sql)), static fn($s) => $s !== '');

// El esquema es CREATE TABLE IF NOT EXISTS (idempotente, no destructivo); en MySQL el DDL hace auto-commit,
// así que si fallara a mitad basta con volver a llamar (las tablas ya creadas se saltan).
$pdo = db();
$ok = 0; $err = [];
foreach ($stmts as $s) {
  try { $pdo->exec($s); $ok++; }
  catch (Throwable $e) { $err[] = $e->getMessage(); }
}
echo "Sentencias aplicadas: $ok\n";
if ($err) echo "Errores:\n - " . implode("\n - ", $err) . "\n";
try {
  $t = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  echo "Tablas: " . implode(', ', $t) . "\n";
} catch (Throwable $e) { /* no crítico */ }

// Auto-borrado: si no hubo errores, este instalador se elimina solo (no deja superficie de ataque).
if (!$err && @unlink(__FILE__)) {
  echo "\n✓ Esquema aplicado. install.php se ha AUTO-BORRADO del servidor.\n";
} else {
  echo "\n⚠ BORRA api/install.php manualmente del servidor (no se pudo auto-borrar o hubo errores).\n";
}
