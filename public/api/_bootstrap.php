<?php
declare(strict_types=1);
// Bootstrap común: carga config (fuera del docroot), PDO, sesión, cabeceras y helpers.
// Cada endpoint hace: require __DIR__ . '/_bootstrap.php';

function cfg(): array {
  static $c = null;
  if ($c === null) {
    $path = getenv('CXPANEL_CONFIG') ?: (__DIR__ . '/../../panel-secret/config.php');
    if (!is_file($path)) {
      http_response_code(500);
      header('Content-Type: application/json');
      echo json_encode(['error' => 'config_missing']);
      exit;
    }
    $c = require $path;
  }
  return $c;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $d = cfg()['db'];
    $driver = $d['driver'] ?? 'mysql';
    if ($driver === 'sqlite') {
      // Modo LOCAL: SQLite + capa de compatibilidad (ejecuta el MISMO SQL que prod).
      require_once __DIR__ . '/lib/sqlite_compat.php';
      $pdo = new SqliteCompatPDO('sqlite:' . $d['path'], null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      $pdo->exec('PRAGMA foreign_keys=ON');
    } else {
      $cs  = !empty($d['charset']) ? $d['charset'] : 'utf8mb4';   // nunca vacío (evita negociar latin1)
      $dsn = "mysql:host={$d['host']};dbname={$d['name']};charset={$cs}";
      $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
      ]);
    }
  }
  return $pdo;
}

function security_headers(): void {
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('Referrer-Policy: no-referrer');
  header('Content-Type: application/json; charset=utf-8');
  $o = cfg()['app']['allowed_origin'] ?? '';
  if ($o && (($_SERVER['HTTP_ORIGIN'] ?? '') === $o)) {
    header("Access-Control-Allow-Origin: $o");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  }
}

function start_session(): void {
  session_name(cfg()['app']['session_name'] ?? 'cxpanel');
  // secure solo cuando la conexión es HTTPS (en local http funciona; en prod siempre https).
  $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
  session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict',
  ]);
  session_start();
}

function json_out($data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function body_json(): array {
  $j = json_decode(file_get_contents('php://input') ?: '[]', true);
  return is_array($j) ? $j : [];
}

function require_auth(bool $require2fa = true): array {
  if (empty($_SESSION['uid'])) json_out(['error' => 'no_auth'], 401);
  if ($require2fa && empty($_SESSION['twofa_ok'])) json_out(['error' => '2fa_required'], 401);
  return ['id' => (int)$_SESSION['uid'], 'rol' => $_SESSION['rol'] ?? 'tecnico'];
}

function require_admin(): array {
  $u = require_auth();
  if (($u['rol'] ?? '') !== 'admin') json_out(['error' => 'forbidden'], 403);
  return $u;
}

function audit(?int $uid, string $accion, string $detalle = ''): void {
  try {
    db()->prepare('INSERT INTO auditoria (usuario_id, accion, detalle, ip) VALUES (?,?,?,?)')
        ->execute([$uid, $accion, $detalle, $_SERVER['REMOTE_ADDR'] ?? '']);
  } catch (Throwable $e) { /* la auditoría nunca debe romper la petición */ }
}

// Zona horaria fija (periodos/fechas coherentes; el CDR y los avisos dependen del mes correcto).
date_default_timezone_set(cfg()['app']['timezone'] ?? 'Europe/Madrid');

// Arranque por defecto (en CLI header()/session son inocuos; el cron usa este mismo bootstrap)
security_headers();
start_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
