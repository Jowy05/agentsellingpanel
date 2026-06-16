<?php
// public/api/login.php — Login paso 1 (password). 2FA en paso 2.
// Modelo: 2 pasos. Password ok -> {step:'2fa'} (totp_enabled=1) o {step:'enroll'} (totp_enabled=0).
// Nunca completa sesión aquí: solo fija pending_*. La sesión completa (uid/rol/twofa_ok) se crea en el paso 2.
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// --- Solo POST (cambios de estado por POST) ---
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'método no permitido'], 405);
}

// Hash dummy (formato bcrypt válido) para igualar el tiempo de respuesta
// cuando el email no existe y evitar fugas por temporización (user enumeration).
const LOGIN_DUMMY_HASH = '$2y$10$usesomesillystringforsalttocompareagainstdummyvaluehashxx';

// Mensaje genérico: jamás revelar si el email existe o no.
$GENERICO = 'credenciales incorrectas';

$in    = body_json();
$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['pass'] ?? '');

// --- Validación de entrada ---
// Límites defensivos: email razonable y password no vacía.
// (No se valida formato estricto del email para no filtrar nada; basta con que sea no vacío y acotado.)
if ($email === '' || $pass === '' || strlen($email) > 190 || strlen($pass) > 4096) {
    json_out(['error' => $GENERICO], 401);
}

try {
    // --- Buscar usuario activo por email (sentencia preparada) ---
    // Se calcula en SQL si la cuenta está bloqueada (NOW() de MySQL) para evitar
    // desajustes de zona horaria entre PHP y la base de datos.
    $st = db()->prepare(
        'SELECT id, pass_hash, rol, totp_enabled, intentos,
                (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado
           FROM usuarios
          WHERE email = ? AND estado = "activo"
          LIMIT 1'
    );
    $st->execute([$email]);
    $u = $st->fetch();

    // --- Usuario inexistente / inactivo: respuesta y tiempo equivalentes ---
    if (!$u) {
        // Igualar coste de CPU para no filtrar existencia por temporización.
        password_verify($pass, LOGIN_DUMMY_HASH);
        // No se registra el email en crudo (evita log injection y almacenar PII arbitraria).
        audit(null, 'login_fallido', 'usuario no encontrado o inactivo');
        json_out(['error' => $GENERICO], 401);
    }

    $uid = (int)$u['id'];

    // --- ¿Cuenta bloqueada por fuerza bruta? ---
    if (!empty($u['bloqueado'])) {
        audit($uid, 'login_bloqueado', 'intento durante bloqueo temporal');
        json_out(['error' => 'locked'], 429);
    }

    // --- Verificar contraseña (password_verify, nunca texto plano) ---
    if (!password_verify($pass, (string)$u['pass_hash'])) {
        $intentos = (int)$u['intentos'] + 1;

        if ($intentos >= 5) {
            // A partir de 5 fallos: bloquear 15 minutos.
            $up = db()->prepare(
                'UPDATE usuarios
                    SET intentos = ?, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                  WHERE id = ?'
            );
            $up->execute([$intentos, $uid]);
            audit($uid, 'login_bloqueado', 'bloqueo por intentos=' . $intentos);
            json_out(['error' => 'locked'], 429);
        }

        $up = db()->prepare('UPDATE usuarios SET intentos = ? WHERE id = ?');
        $up->execute([$intentos, $uid]);
        audit($uid, 'login_fallido', 'password incorrecta (intentos=' . $intentos . ')');
        json_out(['error' => $GENERICO], 401);
    }

    // --- Password correcta: resetear contador y desbloquear ---
    $up = db()->prepare('UPDATE usuarios SET intentos = 0, bloqueado_hasta = NULL WHERE id = ?');
    $up->execute([$uid]);

    // --- No completar sesión: 2FA obligatorio. Mitiga fijación de sesión renovando el id. ---
    session_regenerate_id(true);
    // Limpiar cualquier estado de sesión previo (incl. una posible sesión completa anterior).
    unset($_SESSION['uid'], $_SESSION['rol'], $_SESSION['twofa_ok']);
    $_SESSION['pending_uid'] = $uid;
    $_SESSION['pending_rol'] = (string)$u['rol'];

    if ((int)$u['totp_enabled'] === 1) {
        // 2FA ya configurado -> pedir código.
        audit($uid, 'login_ok', 'paso 1 ok, requiere 2fa');
        json_out(['step' => '2fa']);
    }

    // 2FA no configurado -> forzar enrolado antes de entrar.
    audit($uid, 'login_ok', 'paso 1 ok, requiere enrolado 2fa');
    json_out(['step' => 'enroll']);

} catch (Throwable $e) {
    // Nunca filtrar detalles de la excepción (SQL, secretos, trazas) en la respuesta.
    json_out(['error' => 'error_interno'], 500);
}
