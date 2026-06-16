<?php
// public/api/seed_admin.php
// Creación del PRIMER admin (un solo uso). Solo funciona si la tabla usuarios está vacía.
require __DIR__ . '/_bootstrap.php';

// Solo se permite por POST (cambio de estado)
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['error' => 'metodo_no_permitido'], 405);
}

// Inicialización de un solo uso: si ya existe cualquier usuario, no se permite
$total = (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($total > 0) {
    json_out(['error' => 'ya_inicializado'], 403);
}

// Leer y validar entrada (solo se aceptan valores escalares; nada de arrays anidados)
$in = body_json();
$email  = is_string($in['email']  ?? null) ? trim($in['email'])  : '';
$nombre = is_string($in['nombre'] ?? null) ? trim($in['nombre']) : '';
$pass   = is_string($in['pass']   ?? null) ? $in['pass']         : '';

if ($email === '' || $nombre === '' || $pass === '') {
    json_out(['error' => 'faltan_datos'], 400);
}

// Normalizar email (coherente con el índice UNIQUE) y validar formato
$email = mb_strtolower($email);
if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(['error' => 'email_invalido'], 400);
}

// Longitud razonable del nombre
if (mb_strlen($nombre) > 120) {
    json_out(['error' => 'nombre_invalido'], 400);
}

// Contraseña fuerte: mínimo 10 caracteres.
// bcrypt (PASSWORD_DEFAULT) trunca en 72 bytes: rechazamos por encima para evitar
// que el usuario crea proteger más de lo que realmente se hashea, y como límite anti-DoS.
$passBytes = strlen($pass);
if (mb_strlen($pass) < 10) {
    json_out(['error' => 'pass_debil', 'detalle' => 'La contraseña debe tener al menos 10 caracteres.'], 400);
}
if ($passBytes > 72) {
    json_out(['error' => 'pass_invalida', 'detalle' => 'La contraseña no debe superar 72 bytes.'], 400);
}

// Hash de la contraseña (nunca texto plano)
$hash = password_hash($pass, PASSWORD_DEFAULT);
if ($hash === false) {
    json_out(['error' => 'hash_error'], 500);
}

// Insertar el admin: estado activo, 2FA aún sin habilitar (se enrola en el primer login).
// Si por una carrera concurrente otra petición ya creó un usuario, el UNIQUE(email)
// o la propia condición de inicialización lo impedirán: tratamos el conflicto como 'ya_inicializado'.
try {
    $st = db()->prepare(
        'INSERT INTO usuarios
            (email, nombre, pass_hash, rol, totp_secret, totp_enabled, estado, intentos, bloqueado_hasta, creado)
         VALUES (?, ?, ?, ?, NULL, 0, ?, 0, NULL, NOW())'
    );
    $st->execute([$email, $nombre, $hash, 'admin', 'activo']);
} catch (PDOException $e) {
    // No filtramos detalles internos de SQL en la respuesta
    if ($e->getCode() === '23000') { // violación de integridad (p.ej. email duplicado por carrera)
        json_out(['error' => 'ya_inicializado'], 403);
    }
    json_out(['error' => 'error_interno'], 500);
}

$nuevoId = (int) db()->lastInsertId();

// Registrar en auditoría (acción sensible). audit() nunca rompe la petición.
audit($nuevoId, 'seed_admin', 'Creación del primer administrador: ' . $email);

json_out(['ok' => true], 200);
