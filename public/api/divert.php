<?php
declare(strict_types=1);
// public/api/divert.php — POR AGENTE.
//   action='cut'        -> corta por API: apunta el DID del agente a su IVR de corte (id+dest_type=3+destination).
//   action='reactivado' -> SOLO marca estado='normal' en BD (la reactivación real se hace A MANO en la GUI,
//                          porque la API no puede devolver el DID al agente IA). No llama a la centralita.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'method_not_allowed'], 405);
$u = require_auth();

$in      = body_json();
$agentId = (int)($in['agent_id'] ?? 0);
$action  = (string)($in['action'] ?? '');
if ($agentId <= 0 || !in_array($action, ['cut', 'reactivado'], true)) json_out(['error' => 'datos_invalidos'], 422);

$st = db()->prepare('SELECT ag.id, ag.nombre, ag.ddi, ag.ivr_corte, c.tenant, c.desvio_100
                     FROM agentes ag JOIN clientes c ON c.id = ag.cliente_id WHERE ag.id = ?');
$st->execute([$agentId]);
$a = $st->fetch();
if (!$a) json_out(['error' => 'agente_no_encontrado'], 404);

// Reactivación: solo se confirma en el panel; el técnico ya lo ha hecho en la GUI.
if ($action === 'reactivado') {
    db()->prepare("UPDATE agentes SET estado_desvio = 'normal', did_dest_backup = NULL, actualizado = NOW() WHERE id = ?")->execute([$agentId]);
    audit($u['id'], 'reactivado_manual', 'agente#' . $agentId);
    json_out(['ok' => true, 'estado_desvio' => 'normal']);
}

// Corte por API.
$did = trim((string)($a['ddi'] ?? ''));
if ($did === '') json_out(['error' => 'agente_sin_ddi'], 422);
$dest = trim((string)($a['ivr_corte'] ?? ''));
if ($dest === '') $dest = trim((string)($a['desvio_100'] ?? ''));
if ($dest === '' || !ctype_digit($dest)) json_out(['error' => 'sin_ivr_de_corte', 'detalle' => 'El IVR de corte debe ser un número de IVR.'], 422);

$server = pbx_tenant_server(trim((string)$a['tenant']));
if ($server === null) json_out(['error' => 'tenant_no_encontrado'], 404);

$cut = pbx_cortar_did($server, $did, $dest, 3);   // dest_type 3 = IVR
if (empty($cut['ok'])) {
    audit($u['id'], 'desvio_cut_error', "agente#$agentId did=$did dest=$dest " . json_encode($cut['raw'] ?? $cut['error'] ?? null, JSON_UNESCAPED_UNICODE));
    json_out(['error' => 'pbx_error', 'detalle' => $cut['raw'] ?? $cut['error'] ?? null], 502);
}
db()->prepare("UPDATE agentes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")->execute([$agentId]);
audit($u['id'], 'desvio_cut', "agente#$agentId did=$did -> IVR $dest");
json_out(['ok' => true, 'estado_desvio' => 'cortado', 'did' => $did, 'destino' => $dest]);
