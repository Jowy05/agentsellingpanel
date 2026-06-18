<?php
// public/api/logout.php · Cierra la sesión del usuario
require __DIR__ . '/_bootstrap.php';

// Solo se permite cerrar sesión por POST (evita logout vía GET/CSRF por enlace o prefetch)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'Método no permitido'], 405);
}
require_csrf();   // anti-CSRF: evita logout forzado cross-site

// Registramos el cierre solo si había una sesión COMPLETA (uid presente).
// No exponemos datos de sesión en la respuesta ni en la auditoría.
$uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
if ($uid > 0) {
    audit($uid, 'logout', 'Cierre de sesión');
}

// Vaciamos por completo el contenido de la sesión (incluye uid/rol/twofa_ok y pending_*)
$_SESSION = [];

// Invalidamos la cookie de sesión en el navegador si se usan cookies
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]
    );
}

// Destruimos la sesión en el servidor
session_destroy();

// Respuesta idempotente: siempre ok aunque no hubiera sesión activa
json_out(['ok' => true]);
