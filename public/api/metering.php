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

$stc = db()->prepare('SELECT id, nombre, tenant, minutos_contratados, desvio_100 FROM clientes' . ($clientId ? ' WHERE id = ?' : ''));
$stc->execute($clientId ? [$clientId] : []);
$clientes = $stc->fetchAll();

$autoCut = !empty(cfg()['app']['auto_cut_100']);   // auto-corte al 100% (gateado en config hasta validar did.edit)

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

    $sta = db()->prepare('SELECT id, nombre, ddi, dial_number, ivr_corte, estado_desvio FROM agentes WHERE cliente_id = ?');
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

    // Auto-corte / auto-reactivación según el umbral del 100%:
    //  - total >= contratado  → cortar cada agente con DID (desviar a su IVR de corte / el del cliente)
    //  - total <  contratado  → restaurar cada agente cortado (volver al destino guardado)
    $cortados = []; $restaurados = [];
    $contr = (int)($cli['minutos_contratados'] ?? 0);
    if ($autoCut && $contr > 0) {
        $sobrepasa = ($total >= $contr);
        foreach ($agentes as $a) {
            $did = trim((string)($a['ddi'] ?? ''));
            if ($did === '') continue;   // sin DID público no se puede desviar por API
            $estado = (string)($a['estado_desvio'] ?? 'normal');

            if ($sobrepasa && $estado !== 'cortado') {
                $dest = trim((string)($a['ivr_corte'] ?? ''));
                if ($dest === '') $dest = trim((string)($cli['desvio_100'] ?? ''));
                if ($dest === '') continue;
                $antes = leer_destino_actual($server, $did);   // guarda el destino real (el agente) para poder restaurar
                if ($antes !== null && pbx_es_uuid($antes)) {  // destino = agente IA: no restaurable vía API → no cortar
                    audit($auth['id'], 'auto_corte_bloqueado', 'agente#' . $a['id'] . ' did=' . $did . ' dest_actual=UUID');
                    continue;
                }
                if ($antes !== null && $antes !== '' && $antes !== $dest) {
                    db()->prepare('UPDATE agentes SET did_dest_backup = ?, actualizado = NOW() WHERE id = ?')->execute([$antes, (int)$a['id']]);
                }
                $rr = pbx_call('did', 'edit', construir_params_did_edit($did, $dest), $server);
                if (!empty($rr['ok'])) {
                    db()->prepare("UPDATE agentes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")->execute([(int)$a['id']]);
                    audit($auth['id'], 'auto_corte', 'agente#' . $a['id'] . ' did=' . $did . ' dest=' . $dest);
                    $cortados[] = $a['nombre'];
                } else {
                    audit($auth['id'], 'auto_corte_error', 'agente#' . $a['id'] . ' did=' . $did);
                }
            } elseif (!$sobrepasa && $estado === 'cortado') {
                $back = trim((string)($a['did_dest_backup'] ?? ''));
                if ($back === '') continue;   // no se sabe a qué restaurar
                $rr = pbx_call('did', 'edit', construir_params_did_edit($did, $back), $server);
                if (!empty($rr['ok'])) {
                    db()->prepare("UPDATE agentes SET estado_desvio = 'normal', did_dest_backup = NULL, actualizado = NOW() WHERE id = ?")->execute([(int)$a['id']]);
                    audit($auth['id'], 'auto_reactivar', 'agente#' . $a['id'] . ' did=' . $did . ' dest=' . $back);
                    $restaurados[] = $a['nombre'];
                } else {
                    audit($auth['id'], 'auto_reactivar_error', 'agente#' . $a['id'] . ' did=' . $did);
                }
            }
        }
    }
    $resumen[] = ['cliente' => $cli['nombre'], 'minutos_total' => $total, 'contratado' => $contr, 'agentes' => $detalle, 'cortados' => $cortados, 'restaurados' => $restaurados];
}

json_out(['ok' => true, 'periodo' => $periodo, 'resumen' => $resumen]);
