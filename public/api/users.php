<?php
declare(strict_types=1);
// Gestión de cuentas del equipo (solo admin). Panel de minutos · Agente IA · Conexia.
require __DIR__ . '/_bootstrap.php';

// Solo POST: cualquier cambio o consulta de gestión pasa por POST + JSON.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'method_not_allowed'], 405);
}

// Todo este endpoint exige admin con sesión completa (uid + rol + 2FA).
$admin = require_admin();
$in    = body_json();
if (!is_array($in)) {
  json_out(['error' => 'body_invalido'], 422);
}
$action = (string)($in['action'] ?? '');

// Roles y estados válidos del equipo.
const ROLES_VALIDOS    = ['admin', 'tecnico'];
const ESTADOS_VALIDOS  = ['activo', 'desactivado'];

/**
 * Genera una contraseña temporal aleatoria fuerte.
 * 16 caracteres a partir de un alfabeto sin ambigüedades, usando random_int (CSPRNG).
 */
function generar_pass_temporal(): string {
  $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789@#%+=?';
  $max  = strlen($alfabeto) - 1;
  $pass = '';
  for ($i = 0; $i < 16; $i++) {
    $pass .= $alfabeto[random_int(0, $max)];
  }
  return $pass;
}

/** Lee y valida el id del cuerpo o responde 422. */
function leer_id(array $in): int {
  $id = (int)($in['id'] ?? 0);
  if ($id <= 0) json_out(['error' => 'id_invalido'], 422);
  return $id;
}

/** Devuelve el usuario por id (FETCH_ASSOC) o responde 404 si no existe. */
function obtener_usuario(int $id): array {
  $st = db()->prepare('SELECT id, email, nombre, rol, estado FROM usuarios WHERE id = ?');
  $st->execute([$id]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  if (!$u) json_out(['error' => 'no_encontrado'], 404);
  return $u;
}

switch ($action) {

  // -------- Listar usuarios (sin pass_hash ni totp_secret) --------
  case 'list': {
    // Selección explícita: nunca se exponen pass_hash ni totp_secret.
    $st = db()->query(
      'SELECT id, email, nombre, rol, totp_enabled, estado, intentos,
              bloqueado_hasta, creado, ultimo_login, ultimo_visto
         FROM usuarios
        ORDER BY creado DESC'
    );
    $usuarios = $st->fetchAll(PDO::FETCH_ASSOC);
    // "online" = visto en los últimos 90 s. Se usa el "ahora" de la BD (misma base horaria que ultimo_visto)
    // y se compara la DIFERENCIA, así no importa la zona horaria.
    $dbNow = (string) db()->query('SELECT NOW()')->fetchColumn();   // compat sqlite -> CURRENT_TIMESTAMP
    $tNow  = strtotime($dbNow) ?: time();
    foreach ($usuarios as &$u) {
      $uv = $u['ultimo_visto'] ?? null;
      $u['online'] = ($uv && ($tNow - (strtotime((string)$uv) ?: 0)) <= 90);
    }
    unset($u);
    json_out(['usuarios' => $usuarios]);
    break;
  }

  // -------- Crear usuario --------
  case 'create': {
    $email  = strtolower(trim((string)($in['email'] ?? '')));
    $nombre = trim((string)($in['nombre'] ?? ''));
    $rol    = (string)($in['rol'] ?? '');

    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      json_out(['error' => 'email_invalido'], 422);
    }
    if ($nombre === '' || mb_strlen($nombre) > 120) {
      json_out(['error' => 'nombre_requerido'], 422);
    }
    if (!in_array($rol, ROLES_VALIDOS, true)) {
      json_out(['error' => 'rol_invalido'], 422);
    }

    // Email único (sentencia preparada).
    $chk = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch(PDO::FETCH_ASSOC)) {
      json_out(['error' => 'email_duplicado'], 409);
    }

    // Contraseña temporal (se devuelve UNA sola vez). 2FA obligatorio en primer login.
    $tmp  = generar_pass_temporal();
    $hash = password_hash($tmp, PASSWORD_DEFAULT);

    $ins = db()->prepare(
      'INSERT INTO usuarios (email, nombre, pass_hash, rol, totp_secret, totp_enabled, estado, intentos, bloqueado_hasta, creado)
       VALUES (?, ?, ?, ?, NULL, 0, ?, 0, NULL, NOW())'
    );
    $ins->execute([$email, $nombre, $hash, $rol, 'activo']);
    $nuevoId = (int)db()->lastInsertId();

    // Auditoría sin secretos: nunca se registra la contraseña temporal.
    audit($admin['id'], 'user_create', "id=$nuevoId email=$email rol=$rol");

    // La contraseña temporal solo se muestra aquí, una vez.
    json_out(['ok' => true, 'id' => $nuevoId, 'tmp_pass' => $tmp], 201);
    break;
  }

  // -------- Actualizar datos básicos --------
  case 'update': {
    $id = leer_id($in);
    obtener_usuario($id); // 404 si no existe

    $esYoMismo = ($id === (int)$admin['id']);

    $campos  = [];
    $vals    = [];
    $detalle = [];

    if (array_key_exists('nombre', $in)) {
      $nombre = trim((string)$in['nombre']);
      if ($nombre === '' || mb_strlen($nombre) > 120) json_out(['error' => 'nombre_requerido'], 422);
      $campos[]  = 'nombre = ?';
      $vals[]    = $nombre;
      $detalle[] = "nombre=$nombre";
    }

    if (array_key_exists('rol', $in)) {
      $rol = (string)$in['rol'];
      if (!in_array($rol, ROLES_VALIDOS, true)) json_out(['error' => 'rol_invalido'], 422);
      // Evita que un admin se autodegrade y se quede sin acceso de administración.
      if ($esYoMismo && $rol !== 'admin') json_out(['error' => 'auto_degradacion'], 409);
      $campos[]  = 'rol = ?';
      $vals[]    = $rol;
      $detalle[] = "rol=$rol";
    }

    if (array_key_exists('estado', $in)) {
      $estado = (string)$in['estado'];
      if (!in_array($estado, ESTADOS_VALIDOS, true)) {
        json_out(['error' => 'estado_invalido'], 422);
      }
      // Evita que un admin se desactive a sí mismo (no quedarse fuera).
      if ($esYoMismo && $estado === 'desactivado') json_out(['error' => 'auto_desactivacion'], 409);
      $campos[]  = 'estado = ?';
      $vals[]    = $estado;
      $detalle[] = "estado=$estado";
    }

    if (!$campos) json_out(['error' => 'sin_cambios'], 422);

    $vals[] = $id;
    // Lista de columnas controlada por servidor (whitelist); valores siempre parametrizados.
    $sql = 'UPDATE usuarios SET ' . implode(', ', $campos) . ' WHERE id = ?';
    db()->prepare($sql)->execute($vals);

    audit($admin['id'], 'user_update', "id=$id " . implode(' ', $detalle));
    json_out(['ok' => true]);
    break;
  }

  // -------- Reset de 2FA --------
  case 'reset_2fa': {
    $id = leer_id($in);
    obtener_usuario($id);

    db()->prepare('UPDATE usuarios SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?')
        ->execute([$id]);
    // Invalida los códigos de recuperación existentes.
    db()->prepare('DELETE FROM codigos_recuperacion WHERE usuario_id = ?')
        ->execute([$id]);

    audit($admin['id'], 'user_reset_2fa', "id=$id");
    json_out(['ok' => true]);
    break;
  }

  // -------- Reset de contraseña (temporal, devuelta una vez) --------
  case 'reset_pass': {
    $id = leer_id($in);
    obtener_usuario($id);

    $tmp  = generar_pass_temporal();
    $hash = password_hash($tmp, PASSWORD_DEFAULT);

    // Nueva contraseña y desbloqueo de intentos previos.
    db()->prepare(
      'UPDATE usuarios SET pass_hash = ?, intentos = 0, bloqueado_hasta = NULL WHERE id = ?'
    )->execute([$hash, $id]);

    // Auditoría sin secretos: no se registra la contraseña temporal.
    audit($admin['id'], 'user_reset_pass', "id=$id");

    // La contraseña temporal solo se muestra aquí, una vez.
    json_out(['ok' => true, 'tmp_pass' => $tmp]);
    break;
  }

  // -------- Desactivar cuenta --------
  case 'deactivate': {
    $id = leer_id($in);
    // No permitir que el admin se desactive a sí mismo (evita quedarse fuera).
    if ($id === (int)$admin['id']) json_out(['error' => 'auto_desactivacion'], 409);
    obtener_usuario($id);

    db()->prepare("UPDATE usuarios SET estado = 'desactivado' WHERE id = ?")->execute([$id]);
    audit($admin['id'], 'user_deactivate', "id=$id");
    json_out(['ok' => true]);
    break;
  }

  // -------- Activar cuenta --------
  case 'activate': {
    $id = leer_id($in);
    obtener_usuario($id);

    db()->prepare("UPDATE usuarios SET estado = 'activo' WHERE id = ?")->execute([$id]);
    audit($admin['id'], 'user_activate', "id=$id");
    json_out(['ok' => true]);
    break;
  }

  // -------- Borrar cuenta (admin protegido) --------
  case 'delete': {
    $id = leer_id($in);
    if ($id === (int)$admin['id']) json_out(['error' => 'auto_borrado', 'detalle' => 'No puedes borrar tu propia cuenta.'], 409);
    $u = obtener_usuario($id);
    if (($u['rol'] ?? '') === 'admin') json_out(['error' => 'admin_no_borrable', 'detalle' => 'Las cuentas admin no se pueden borrar.'], 409);
    // codigos_recuperacion se borran en cascada (FK ON DELETE CASCADE).
    db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
    audit($admin['id'], 'user_delete', "id=$id email=" . ($u['email'] ?? ''));
    json_out(['ok' => true]);
    break;
  }

  default:
    json_out(['error' => 'accion_desconocida'], 400);
}
