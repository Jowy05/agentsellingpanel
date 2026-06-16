<?php
// public/api/metering.php — recalcula el consumo POR AGENTE desde el CDR (web, con sesión).
// La lógica vive en lib/meter_run.php (compartida con cron.php).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/meter_run.php';

set_exception_handler(function (Throwable $e): void {
    error_log('metering.php: ' . $e->getMessage());
    json_out(['error' => 'Error interno del servidor.'], 500);
});
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Método no permitido'], 405);
$auth = require_auth(true);

$in       = body_json();
$clientId = isset($in['client_id']) ? (int)$in['client_id'] : 0;
$quick    = (($in['scope'] ?? '') === 'today');

json_out(run_metering($clientId, $quick, (int)$auth['id']));
