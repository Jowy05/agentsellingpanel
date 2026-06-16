<?php
// public/api/pbx_ping.php
// Prueba de conectividad con la centralita PBX (server-side).
// Solo administradores. Nunca expone la apikey ni secretos del PBX.

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

// Este endpoint es de solo lectura: exigimos GET.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_out(['error' => 'Método no permitido'], 405);
}

// Requiere sesión COMPLETA de administrador (uid + rol + 2FA).
$user = require_admin();

// Lanzamos el ping server-side. pbx_ping() encapsula la apikey internamente;
// jamás devolvemos credenciales ni payload crudo del PBX.
try {
    $res = pbx_ping();
} catch (\Throwable $e) {
    // No filtramos el mensaje de la excepción (podría contener URLs/secretos).
    audit($user['id'], 'pbx_ping', 'Excepción en conectividad PBX');
    json_out(['ok' => false, 'error' => 'Error de conectividad con la centralita'], 502);
}

$ok = (bool) ($res['ok'] ?? false);

// Saneamos las extensiones: devolvemos solo un recuento y una lista mínima
// de identificadores (cadenas/números), nunca el objeto crudo del PBX.
$extensiones = [];
$raw = $res['extensiones'] ?? [];
if (is_array($raw)) {
    foreach ($raw as $ext) {
        if (is_scalar($ext)) {
            // Extensión simple (p.ej. "1001").
            $extensiones[] = (string) $ext;
        } elseif (is_array($ext)) {
            // Si viene como objeto, extraemos solo un identificador conocido.
            foreach (['extension', 'ext', 'number', 'id'] as $k) {
                if (isset($ext[$k]) && is_scalar($ext[$k])) {
                    $extensiones[] = (string) $ext[$k];
                    break;
                }
            }
        }
    }
}

// Registramos el intento en auditoría (sin filtrar secretos).
audit(
    $user['id'],
    'pbx_ping',
    $ok ? ('Conectividad PBX OK (' . count($extensiones) . ' extensiones)') : 'Fallo de conectividad PBX'
);

// Respuesta segura: solo estado, recuento y lista mínima de extensiones.
json_out([
    'ok'          => $ok,
    'total'       => count($extensiones),
    'extensiones' => $extensiones,
], $ok ? 200 : 502);
