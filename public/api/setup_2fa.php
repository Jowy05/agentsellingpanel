<?php
declare(strict_types=1);
// setup_2fa.php · Enrolado/cambio de 2FA del Panel de minutos · Agente IA (Conexia)
// Requiere sesión completa (uid+twofa_ok) o enrolado pendiente ($_SESSION['pending_uid']).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/totp.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Solo se admiten GET (obtener secreto/QR) y POST (confirmar enrolado).
if ($metodo !== 'GET' && $metodo !== 'POST') {
    json_out(['error' => 'Método no permitido'], 405);
}

// Resuelve el usuario destino: sesión completa o enrolado pendiente (pending_uid).
$uid       = null;
$en_curso  = false; // true => login en curso (pending_uid), sin sesión completa todavía
if (!empty($_SESSION['uid']) && !empty($_SESSION['twofa_ok'])) {
    // Sesión ya completa: permite re-enrolar/cambiar 2FA.
    $uid = (int)$_SESSION['uid'];
} elseif (!empty($_SESSION['pending_uid'])) {
    // Login en curso pendiente de 2FA/enrolado.
    $uid      = (int)$_SESSION['pending_uid'];
    $en_curso = true;
} else {
    json_out(['error' => 'No autorizado'], 401);
}

// Carga el usuario y comprueba que siga activo. (rol se lee de BD: fuente de verdad)
$st = db()->prepare('SELECT id, email, rol, totp_secret, totp_enabled, estado FROM usuarios WHERE id = ? LIMIT 1');
$st->execute([$uid]);
$usuario = $st->fetch();
if (!$usuario) {
    // Sesión apuntando a un usuario inexistente: limpiar para evitar estados zombis.
    unset($_SESSION['pending_uid'], $_SESSION['pending_rol']);
    json_out(['error' => 'Usuario no encontrado'], 404);
}
if ($usuario['estado'] !== 'activo') {
    unset($_SESSION['pending_uid'], $_SESSION['pending_rol']);
    json_out(['error' => 'Cuenta desactivada'], 403);
}

$issuer    = cfg()['app']['totp_issuer'] ?? 'Conexia';
$yaActivado = ((int)$usuario['totp_enabled'] === 1);

// ---------------------------------------------------------------------------
// GET: devuelve (o genera) el secreto y la URI otpauth para el QR.
// - Sin secreto: genera uno (totp_enabled sigue 0).
// - Re-enrolado (ya activado): genera un secreto NUEVO pendiente, sin desactivar
//   el actual ni exponer el secreto vivo, hasta que POST lo confirme.
// ---------------------------------------------------------------------------
if ($metodo === 'GET') {
    $secret = (string)($usuario['totp_secret'] ?? '');

    if ($secret === '' || $yaActivado) {
        // Genera un secreto pendiente. No tocamos totp_enabled para no bloquear al usuario.
        $secret = totp_secret();
        // Guardamos el secreto pendiente en sesión; solo se persiste al confirmar (POST).
        $_SESSION['pending_totp_secret'] = $secret;
    } else {
        // Hay secreto y aún no está activado: reutilizamos el guardado.
        $_SESSION['pending_totp_secret'] = $secret;
    }

    $uri = totp_uri($usuario['email'], $secret, $issuer);

    json_out([
        'secret'      => $secret,
        'otpauth_uri' => $uri,
    ], 200);
}

// ---------------------------------------------------------------------------
// POST {code}: verifica el código y completa el enrolado.
// ---------------------------------------------------------------------------

// Anti-fuerza-bruta del código TOTP durante el enrolado (por sesión).
$_SESSION['2fa_enroll_intentos'] = (int)($_SESSION['2fa_enroll_intentos'] ?? 0);
if ($_SESSION['2fa_enroll_intentos'] >= 5) {
    audit((int)$usuario['id'], '2fa_enroll_bloqueo', 'Demasiados intentos de código TOTP en enrolado');
    json_out(['error' => 'Demasiados intentos. Solicita un nuevo código QR.'], 429);
}

$in   = body_json();
$code = isset($in['code']) ? preg_replace('/\D/', '', (string)$in['code']) : '';

if ($code === '' || strlen($code) < 6 || strlen($code) > 8) {
    json_out(['error' => 'Falta el código de verificación'], 400);
}

// Secreto a verificar: el pendiente en sesión (flujo correcto desde GET).
$secret = (string)($_SESSION['pending_totp_secret'] ?? '');
if ($secret === '') {
    // Sin re-activar: si existe un secreto en BC y no estaba activado, sirve de respaldo.
    if (!$yaActivado && (string)($usuario['totp_secret'] ?? '') !== '') {
        $secret = (string)$usuario['totp_secret'];
    } else {
        json_out(['error' => 'Primero solicita el código QR'], 400);
    }
}

// Verifica el TOTP contra el secreto pendiente.
if (!totp_verify($secret, $code, 1)) {
    $_SESSION['2fa_enroll_intentos']++;
    audit((int)$usuario['id'], '2fa_enroll_fallo', 'Código TOTP incorrecto durante enrolado');
    json_out(['error' => 'Código incorrecto'], 401);
}

// Genera 8 códigos de recuperación legibles (p.ej. ABCD-2345).
$recovery = [];
for ($i = 0; $i < 8; $i++) {
    $recovery[] = generar_codigo_recuperacion();
}

// Persiste todo de forma atómica: secreto definitivo, activación y códigos.
$pdo = db();
$pdo->beginTransaction();
try {
    // Fija el secreto confirmado y activa 2FA. (reset de bloqueos de login)
    $up = $pdo->prepare(
        'UPDATE usuarios
            SET totp_secret = ?, totp_enabled = 1, intentos = 0, bloqueado_hasta = NULL, ultimo_login = NOW()
          WHERE id = ?'
    );
    $up->execute([$secret, (int)$usuario['id']]);

    // Borra códigos de recuperación previos.
    $del = $pdo->prepare('DELETE FROM codigos_recuperacion WHERE usuario_id = ?');
    $del->execute([(int)$usuario['id']]);

    // Guarda solo el hash de cada código (jamás en claro en BD).
    $ins = $pdo->prepare('INSERT INTO codigos_recuperacion (usuario_id, code_hash, usado) VALUES (?, ?, 0)');
    foreach ($recovery as $rc) {
        $ins->execute([(int)$usuario['id'], password_hash($rc, PASSWORD_DEFAULT)]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Nunca exponemos detalles internos del error.
    json_out(['error' => 'No se pudo completar el alta de 2FA'], 500);
}

// Eleva privilegios -> regenera el id de sesión para evitar fijación.
session_regenerate_id(true);

// Fija sesión completa y limpia el estado pendiente.
$_SESSION['uid']      = (int)$usuario['id'];
$_SESSION['rol']      = $usuario['rol'];
$_SESSION['twofa_ok'] = true;
unset(
    $_SESSION['pending_uid'],
    $_SESSION['pending_rol'],
    $_SESSION['pending_totp_secret'],
    $_SESSION['2fa_enroll_intentos']
);

audit((int)$usuario['id'], '2fa_enroll_ok', 'Activación de 2FA y generación de códigos de recuperación');

// Los códigos en claro se devuelven UNA sola vez.
json_out([
    'step'           => 'ok',
    'recovery_codes' => $recovery,
], 200);

/**
 * Genera un código de recuperación legible con formato XXXX-XXXX.
 * Alfabeto sin caracteres ambiguos (sin 0/O, 1/I/L). Aleatoriedad criptográfica.
 */
function generar_codigo_recuperacion(): string
{
    $alfabeto = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $len = strlen($alfabeto);
    $out = '';
    for ($i = 0; $i < 8; $i++) {
        if ($i === 4) {
            $out .= '-';
        }
        $out .= $alfabeto[random_int(0, $len - 1)];
    }
    return $out;
}