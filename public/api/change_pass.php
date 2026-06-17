<?php
declare(strict_types=1);
// public/api/change_pass.php — el usuario logueado cambia SU PROPIA contraseña (verifica la actual).
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'method_not_allowed'], 405);
$u = require_auth();   // sesión completa (uid + 2FA); admin o técnico

$in      = body_json();
$current = (string)($in['current'] ?? '');
$new     = (string)($in['new'] ?? '');

$st = db()->prepare('SELECT pass_hash FROM usuarios WHERE id = ?');
$st->execute([(int)$u['id']]);
$hash = $st->fetchColumn();
if ($hash === false) json_out(['error' => 'no_encontrado'], 404);

if (!password_verify($current, (string)$hash)) {
  json_out(['error' => 'pass_actual_incorrecta', 'detalle' => 'La contraseña actual no es correcta.'], 403);
}
if (mb_strlen($new) < 10) json_out(['error' => 'pass_debil', 'detalle' => 'La nueva contraseña debe tener al menos 10 caracteres.'], 422);
if (strlen($new) > 72)    json_out(['error' => 'pass_invalida', 'detalle' => 'La nueva contraseña no debe superar 72 bytes.'], 422);
if (password_verify($new, (string)$hash)) json_out(['error' => 'pass_igual', 'detalle' => 'La nueva contraseña debe ser distinta de la actual.'], 422);

$newHash = password_hash($new, PASSWORD_DEFAULT);
if ($newHash === false) json_out(['error' => 'hash_error'], 500);

db()->prepare('UPDATE usuarios SET pass_hash = ?, intentos = 0, bloqueado_hasta = NULL WHERE id = ?')
    ->execute([$newHash, (int)$u['id']]);
audit($u['id'], 'change_pass', 'cambio de contraseña propia');
json_out(['ok' => true]);
