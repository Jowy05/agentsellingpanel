<?php
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/totp.php';

// Login paso 2: verificación de 2FA (código TOTP o código de recuperación).
// Solo POST: cualquier otro método -> 405.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Método no permitido'], 405);
}
require_csrf();   // anti-CSRF: exige Content-Type JSON + X-CSRF-Token de la sesión

// Debe existir un login pendiente (paso 1 superado con password correcta).
$pendingUid = $_SESSION['pending_uid'] ?? null;
if (!$pendingUid) {
    json_out(['error' => 'No hay verificación 2FA pendiente'], 401);
}
$pendingUid = (int)$pendingUid;

// Lectura y validación de entrada.
$in   = body_json();
$code = trim((string)($in['code'] ?? ''));
if ($code === '') {
    json_out(['error' => 'Código requerido'], 400);
}
// Acotar longitud razonable (TOTP 6-8 dígitos; recuperación, alfanumérico corto).
if (strlen($code) > 64) {
    json_out(['error' => 'Código inválido'], 400);
}

// Cargar el usuario pendiente (sentencia preparada).
$st = db()->prepare('SELECT id, rol, totp_secret, totp_enabled, estado, intentos, bloqueado_hasta FROM usuarios WHERE id = ?');
$st->execute([$pendingUid]);
$u = $st->fetch();

if (!$u) {
    // Usuario inexistente: limpiar estado pendiente.
    unset($_SESSION['pending_uid'], $_SESSION['pending_rol']);
    json_out(['error' => 'Sesión inválida'], 401);
}
$uid = (int)$u['id'];

// El usuario debe estar activo.
if (($u['estado'] ?? '') !== 'activo') {
    unset($_SESSION['pending_uid'], $_SESSION['pending_rol']);
    audit($uid, '2fa_fallo', 'Usuario no activo');
    json_out(['error' => 'Usuario desactivado'], 403);
}

// Anti-fuerza-bruta: si está bloqueado, 429 sin verificar nada.
if (!empty($u['bloqueado_hasta'])) {
    $bk = db()->prepare('SELECT (bloqueado_hasta > NOW()) AS bloqueado FROM usuarios WHERE id = ?');
    $bk->execute([$uid]);
    $bkRow = $bk->fetch();
    if ($bkRow && (int)$bkRow['bloqueado'] === 1) {
        audit($uid, '2fa_bloqueado', 'Intento durante bloqueo');
        json_out(['error' => 'locked'], 429);
    }
}

$valido = false;

// (a) Verificación TOTP, si hay secreto disponible.
if (!empty($u['totp_secret']) && totp_verify($u['totp_secret'], $code)) {
    $valido = true;
}

// (b) Código de recuperación NO usado (password_verify sobre code_hash).
if (!$valido) {
    $stc = db()->prepare('SELECT id, code_hash FROM codigos_recuperacion WHERE usuario_id = ? AND usado = 0');
    $stc->execute([$uid]);
    $recs = $stc->fetchAll();
    foreach ($recs as $rec) {
        if (password_verify($code, (string)$rec['code_hash'])) {
            // Marcar el código de recuperación como usado (solo si seguía sin usar: defensa contra reuso).
            $stm = db()->prepare('UPDATE codigos_recuperacion SET usado = 1 WHERE id = ? AND usado = 0');
            $stm->execute([(int)$rec['id']]);
            if ($stm->rowCount() === 1) {
                $valido = true;
                audit($uid, '2fa_recuperacion', 'Acceso con código de recuperación');
            }
            break;
        }
    }
}

if (!$valido) {
    // Fallo: contar intento (anti-fuerza-bruta) y, a partir de 5, bloquear 15 min.
    $upd = db()->prepare('UPDATE usuarios SET intentos = intentos + 1 WHERE id = ?');
    $upd->execute([$uid]);

    // Releer intentos para decidir bloqueo.
    $stn = db()->prepare('SELECT intentos FROM usuarios WHERE id = ?');
    $stn->execute([$uid]);
    $row = $stn->fetch();
    $intentos = $row ? (int)$row['intentos'] : 0;

    if ($intentos >= 5) {
        $lock = db()->prepare('UPDATE usuarios SET bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?');
        $lock->execute([$uid]);
        audit($uid, '2fa_bloqueado', 'Cuenta bloqueada por intentos 2FA');
        json_out(['error' => 'locked'], 429);
    }

    audit($uid, '2fa_fallo', 'Código 2FA incorrecto');
    json_out(['error' => 'Código incorrecto'], 401);
}

// Éxito: reset anti-fuerza-bruta, marcar 2FA habilitado (enrolado completado), ultimo_login.
$ok = db()->prepare('UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL, totp_enabled = 1, ultimo_login = NOW() WHERE id = ?');
$ok->execute([$uid]);

// Sesión COMPLETA: regenerar id (anti fijación), fijar uid/rol/twofa_ok, borrar pending_*.
session_regenerate_id(true);
$_SESSION['uid']      = $uid;
$_SESSION['rol']      = $u['rol'];
$_SESSION['twofa_ok'] = true;
unset($_SESSION['pending_uid'], $_SESSION['pending_rol']);

audit($uid, '2fa_ok', 'Verificación 2FA correcta');

// Respuesta: sin secretos (nunca totp_secret ni apikey).
json_out([
    'ok'  => true,
    'uid' => $uid,
    'rol' => $u['rol'],
]);
