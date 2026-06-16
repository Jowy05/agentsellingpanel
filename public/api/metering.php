<?php
// public/api/metering.php — recalcula el consumo POR AGENTE desde el CDR y lo guarda.
// El total del cliente = suma de los minutos de sus agentes (vía clients.php list).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';
require __DIR__ . '/lib/notify.php';

set_exception_handler(function (Throwable $e): void {
    error_log('metering.php: ' . $e->getMessage());
    json_out(['error' => 'Error interno del servidor.'], 500);
});
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'Método no permitido'], 405);
$auth = require_auth(true);

$in       = body_json();
$clientId = isset($in['client_id']) ? (int)$in['client_id'] : 0;

$periodo = date('Y-m');
$day1    = date('M-d-Y', strtotime(date('Y-m-01')));  // primer día del mes en curso
$hoy     = date('M-d-Y');                               // hoy
$ayer    = date('M-d-Y', strtotime('-1 day'));         // ayer
$quick   = (($in['scope'] ?? '') === 'today');         // medición RÁPIDA: solo recalcula hoy (1 consulta/agente)

$stc = db()->prepare('SELECT id, nombre, correo, tenant, minutos_contratados, desvio_100 FROM clientes' . ($clientId ? ' WHERE id = ?' : ''));
$stc->execute($clientId ? [$clientId] : []);
$clientes = $stc->fetchAll();

$autoCut = !empty(cfg()['app']['auto_cut_100']);   // auto-corte al 100% (gateado en config hasta validar did.edit)

// Upsert COMPLETO: fija minutos_usados Y base_mes (= minutos del mes hasta AYER).
$upFull = db()->prepare(
    'INSERT INTO consumo (agente_id, periodo, minutos_usados, base_mes, actualizado)
          VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE minutos_usados = VALUES(minutos_usados), base_mes = VALUES(base_mes), actualizado = NOW()'
);
// Upsert RÁPIDO: solo minutos_usados (mantiene el base_mes ya guardado).
$upQuick = db()->prepare(
    'INSERT INTO consumo (agente_id, periodo, minutos_usados, base_mes, actualizado)
          VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE minutos_usados = VALUES(minutos_usados), actualizado = NOW()'
);
$baseStmt = db()->prepare('SELECT base_mes FROM consumo WHERE agente_id = ? AND periodo = ?');

$resumen = [];
foreach ($clientes as $cli) {
    $code = trim((string)$cli['tenant']);
    if ($code === '') { $resumen[] = ['cliente' => $cli['nombre'], 'error' => 'sin tenant']; continue; }
    $server = pbx_tenant_server($code);
    if ($server === null) { $resumen[] = ['cliente' => $cli['nombre'], 'error' => 'tenant no encontrado en la centralita']; continue; }

    $sta = db()->prepare('SELECT id, nombre, ddi, dial_number, ivr_corte, estado_desvio FROM agentes WHERE cliente_id = ?');
    $sta->execute([(int)$cli['id']]);
    $agentes = $sta->fetchAll();

    $total = 0; $detalle = [];
    foreach ($agentes as $a) {
        // Se mide por el DDI (público) o, si no hay, por el dial number (interno).
        $needle = trim((string)($a['ddi'] ?? ''));
        if ($needle === '') $needle = trim((string)($a['dial_number'] ?? ''));
        if ($needle === '') { $detalle[] = ['agente' => $a['nombre'], 'minutos' => 0, 'nota' => 'sin DDI ni dial']; continue; }

        // Hoy es el único día que cambia → siempre se recalcula (1 consulta).
        $mh     = pbx_cdr_minutos($needle, $hoy, $hoy, $server);
        $hoyMin = !empty($mh['ok']) ? (int)$mh['minutos'] : 0;

        if ($quick) {
            $baseStmt->execute([(int)$a['id'], $periodo]);
            $base = $baseStmt->fetchColumn();
            if ($base === false) {
                // Sin base previa: calcula el mes hasta ayer una vez y la guarda.
                $mp   = pbx_cdr_minutos($needle, $day1, $ayer, $server);
                $base = !empty($mp['ok']) ? (int)$mp['minutos'] : 0;
                $min  = $base + $hoyMin;
                $upFull->execute([(int)$a['id'], $periodo, $min, $base]);
            } else {
                $base = (int)$base;
                $min  = $base + $hoyMin;
                $upQuick->execute([(int)$a['id'], $periodo, $min, $base]);
            }
        } else {
            // Completo: re-mide el mes hasta ayer + hoy.
            $mp   = pbx_cdr_minutos($needle, $day1, $ayer, $server);
            $base = !empty($mp['ok']) ? (int)$mp['minutos'] : 0;
            $min  = $base + $hoyMin;
            $upFull->execute([(int)$a['id'], $periodo, $min, $base]);
        }
        $total += $min;
        $detalle[] = ['agente' => $a['nombre'], 'minutos' => $min];
    }
    audit($auth['id'], $quick ? 'metering_rapido' : 'metering', 'Cliente #' . $cli['id'] . ': ' . $total . ' min (' . count($agentes) . ' agentes)');

    // AUTO-CORTE al 100% (la reactivación es MANUAL en la GUI; la API no puede devolver el DID al agente IA):
    // si total >= contratado, desvía cada agente NO cortado a su IVR de corte (su ivr_corte o el del cliente).
    $cortados = [];
    $contr = (int)($cli['minutos_contratados'] ?? 0);
    if ($autoCut && $contr > 0 && $total >= $contr) {
        foreach ($agentes as $a) {
            if (($a['estado_desvio'] ?? 'normal') === 'cortado') continue;
            $did = trim((string)($a['ddi'] ?? ''));
            if ($did === '') continue;   // sin DID público no se puede desviar por API
            $dest = trim((string)($a['ivr_corte'] ?? ''));
            if ($dest === '') $dest = trim((string)($cli['desvio_100'] ?? ''));
            if ($dest === '' || !ctype_digit($dest)) continue;   // el IVR de corte debe ser un nº de IVR
            $cut = pbx_cortar_did($server, $did, $dest, 3);       // dest_type 3 = IVR
            if (!empty($cut['ok'])) {
                db()->prepare("UPDATE agentes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")->execute([(int)$a['id']]);
                audit($auth['id'], 'auto_corte', 'agente#' . $a['id'] . ' did=' . $did . ' -> IVR ' . $dest);
                $cortados[] = $a['nombre'];
            } else {
                audit($auth['id'], 'auto_corte_error', 'agente#' . $a['id'] . ' did=' . $did . ' ' . json_encode($cut['raw'] ?? $cut['error'] ?? null, JSON_UNESCAPED_UNICODE));
            }
        }
    }
    // Aviso por email al 75% / 100% (una sola vez por cliente/periodo/nivel).
    avisar_consumo_si_corresponde($cli, $total, $contr);

    $resumen[] = ['cliente' => $cli['nombre'], 'minutos_total' => $total, 'contratado' => $contr, 'agentes' => $detalle, 'cortados' => $cortados];
}

json_out(['ok' => true, 'periodo' => $periodo, 'resumen' => $resumen]);
