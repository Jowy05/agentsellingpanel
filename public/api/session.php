<?php
// public/api/session.php
// Devuelve los datos del usuario en sesion (sesion completa requerida).
// Solo lectura ("whoami"): GET + require_auth() (uid + rol + twofa_ok).
require __DIR__ . '/_bootstrap.php';

// Solo GET: cualquier otro metodo no procede.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_out(['error' => 'Metodo no permitido'], 405);
}

// Exige sesion completa (uid + rol + twofa_ok).
$auth = require_auth();

// Consulta a BD los datos actuales del usuario (sentencia preparada).
// Solo columnas no sensibles: jamas se exponen pass_hash, totp_secret, etc.
try {
    $stmt = db()->prepare(
        'SELECT id, email, nombre, rol, estado FROM usuarios WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$auth['id']]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    // No filtrar detalles de PDO/SQL al cliente.
    json_out(['error' => 'Error interno'], 500);
}

// El usuario podria haber sido eliminado o desactivado tras iniciar sesion.
// En ambos casos la sesion deja de ser valida: se destruye y se exige re-login.
if (!$user || ($user['estado'] ?? '') !== 'activo') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }
    session_destroy();
    json_out(['error' => 'no_auth'], 401);
}

// Respuesta segun ESPEC: {user:{id,email,nombre,rol}}.
json_out([
    'user' => [
        'id'     => (int) $user['id'],
        'email'  => (string) $user['email'],
        'nombre' => (string) $user['nombre'],
        'rol'    => (string) $user['rol'],
    ],
], 200);
