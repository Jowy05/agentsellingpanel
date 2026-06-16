<?php
// public/api/metering.php — recalcula el consumo POR AGENTE desde el CDR y lo guarda.
// El total del cliente = suma de los minutos de sus agentes (vía clients.php list).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

set_exception_handler(function (Throwable $e): void {
    error_log('metering.php: ' . $e->getMessage());
    json_out(['error' => 'Error interno del servidor.'], 500);
});
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Método no permitido'], 405);
$auth = require_auth(true);

$in       = body_json();
$clientId = isset($in['client_id']) ? (int)$in['client_id'] : 0;

$periodo = date('Y-m');
$inicio  = date('M-d-Y', strtotime(date('Y-m-01')));  // primer día del mes en curso
$fin     = date('M-d-Y');                               // hoy

$stc = db()->prepare('SELECT id, nombre, tenant FROM clientes' . ($clientId ? ' WHERE id = ?' : ''));
$stc->execute($clientId ? [$clientId] : []);
$clientes = $stc->fetchAll();

$upsert = db()->prepare(
    'INSERT INTO consumo (agente_id, periodo, minutos_usados, actualizado)
          VALUES (?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE minutos_usados = VALUES(minutos_usados), actualizado = NOW()'
);

$resumen = [];
foreach ($clientes as $cli) {
    $code = trim((string)$cli['tenant']);
    if ($code === '') { $resumen[] = ['cliente' => $cli['nombre'], 'error' => 'sin tenant']; continue; }
    $server = pbx_tenant_server($code);
    if ($server === null) { $resumen[] = ['cliente' => $cli['nombre'], 'error' => 'tenant no encontrado en la centralita']; continue; }

    $sta = db()->prepare('SELECT id, nombre, ddi, dial_number FROM agentes WHERE cliente_id = ?');
    $sta->execute([(int)$cli['id']]);
    $agentes = $sta->fetchAll();

    $total = 0; $detalle = [];
    foreach ($agentes as $a) {
        // Se mide por el DDI (público) o, si no hay, por el dial number (interno).
        $needle = trim((string)($a['ddi'] ?? ''));
        if ($needle === '') $needle = trim((string)($a['dial_number'] ?? ''));
        if ($needle === '') { $detalle[] = ['agente' => $a['nombre'], 'minutos' => 0, 'nota' => 'sin DDI ni dial']; continue; }

        $m   = pbx_cdr_minutos($needle, $inicio, $fin, $server);
        $min = !empty($m['ok']) ? (int)$m['minutos'] : 0;
        $upsert->execute([(int)$a['id'], $periodo, $min]);
        $total += $min;
        $detalle[] = ['agente' => $a['nombre'], 'minutos' => $min];
    }
    audit($auth['id'], 'metering', 'Cliente #' . $cli['id'] . ': ' . $total . ' min (' . count($agentes) . ' agentes)');
    $resumen[] = ['cliente' => $cli['nombre'], 'minutos_total' => $total, 'agentes' => $detalle];
}

json_out(['ok' => true, 'periodo' => $periodo, 'resumen' => $resumen]);
