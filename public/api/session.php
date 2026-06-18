<?php
// public/api/session.php
// "whoami" + token CSRF. GET. Devuelve SIEMPRE el token CSRF de la sesión (lo necesita el SPA
// incluso sin login, para poder enviar el POST de login). Incluye 'user' solo si hay sesión completa.
require __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_out(['error' => 'Metodo no permitido'], 405);
}

$resp = ['user' => null, 'csrf' => csrf_token()];

// ¿Sesión completa (uid + 2FA)?
if (!empty($_SESSION['uid']) && !empty($_SESSION['twofa_ok'])) {
    $uid = (int) $_SESSION['uid'];
    try {
        $stmt = db()->prepare('SELECT id, email, nombre, rol, estado FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$uid]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        json_out(['error' => 'Error interno'], 500);
    }

    if ($user && ($user['estado'] ?? '') === 'activo') {
        // Presencia: marca "visto" como mucho cada 30 s.
        $now = time();
        if (($now - (int)($_SESSION['touch'] ?? 0)) >= 30) {
            try { db()->prepare('UPDATE usuarios SET ultimo_visto = NOW() WHERE id = ?')->execute([$uid]); } catch (Throwable $e) {}
            $_SESSION['touch'] = $now;
        }
        $resp['user'] = [
            'id'     => (int) $user['id'],
            'email'  => (string) $user['email'],
            'nombre' => (string) $user['nombre'],
            'rol'    => (string) $user['rol'],
        ];
    } else {
        // El usuario fue eliminado o desactivado tras iniciar sesión: invalidar y emitir token nuevo.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
        if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
        $resp['csrf'] = csrf_token();
    }
}

json_out($resp, 200);
