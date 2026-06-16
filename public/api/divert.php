<?php
declare(strict_types=1);
// public/api/divert.php — Desvío POR AGENTE. Corta o restaura el destino del DID del agente.
// POST {agent_id, action:'cut'|'restore'}. audit() SIEMPRE.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

// TODO: verificar nombres de params de pbxware.did.edit con la centralita.
if (!function_exists('construir_params_did_edit')) {
  function construir_params_did_edit(string $did, string $dest): array {
    return ['did' => $did, 'destination' => $dest];
  }
}
// Lee el destino actual (ext) del DID en did.list del tenant.
if (!function_exists('leer_destino_actual')) {
  function leer_destino_actual(int $server, string $did): ?string {
    $r = pbx_call('did', 'list', [], $server);
    if (empty($r['ok']) || !is_array($r['data'])) return null;
    foreach ($r['data'] as $fila) {
      if (!is_array($fila)) continue;
      $num = (string)($fila['number'] ?? $fila['did'] ?? '');
      if ($num !== '' && $num === $did) {
        $d = $fila['ext'] ?? $fila['destination'] ?? null;
        return $d === null ? null : (string)$d;
      }
    }
    return null;
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'method_not_allowed'], 405);
$u = require_auth();

$in      = body_json();
$agentId = (int)($in['agent_id'] ?? 0);
$action  = (string)($in['action'] ?? '');
if ($agentId <= 0 || !in_array($action, ['cut', 'restore'], true)) json_out(['error' => 'datos_invalidos'], 422);

// Agente + su cliente (para resolver tenant→server e IVR por defecto del cliente).
$st = db()->prepare('SELECT ag.id, ag.nombre, ag.ddi, ag.ivr_corte, ag.did_dest_backup, ag.estado_desvio,
                            c.tenant, c.desvio_100
                     FROM agentes ag JOIN clientes c ON c.id = ag.cliente_id
                     WHERE ag.id = ?');
$st->execute([$agentId]);
$a = $st->fetch();
if (!$a) json_out(['error' => 'agente_no_encontrado'], 404);

$did = trim((string)($a['ddi'] ?? ''));
if ($did === '') json_out(['error' => 'agente_sin_ddi'], 422);   // sin DID público no se puede desviar por API

$server = pbx_tenant_server(trim((string)$a['tenant']));
if ($server === null) json_out(['error' => 'tenant_no_encontrado'], 404);

if ($action === 'cut') {
  $dest = trim((string)($a['ivr_corte'] ?? ''));
  if ($dest === '') $dest = trim((string)($a['desvio_100'] ?? ''));   // IVR de corte por defecto del cliente
  if ($dest === '') json_out(['error' => 'sin_ivr_de_corte'], 422);

  $antes = leer_destino_actual($server, $did);
  if ($antes !== null && $antes !== '' && $antes !== $dest) {
    db()->prepare('UPDATE agentes SET did_dest_backup = ?, actualizado = NOW() WHERE id = ?')->execute([$antes, $agentId]);
  }
  $r = pbx_call('did', 'edit', construir_params_did_edit($did, $dest), $server);
  if (empty($r['ok'])) {
    audit($u['id'], 'desvio_cut_error', "agente#$agentId did=$did dest=$dest");
    json_out(['error' => 'pbx_error', 'http' => (int)($r['http'] ?? 0)], 502);
  }
  db()->prepare("UPDATE agentes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")->execute([$agentId]);
  audit($u['id'], 'desvio_cut', "agente#$agentId did=$did antes=" . ($antes ?? '?') . " dest=$dest");
  json_out(['ok' => true, 'estado_desvio' => 'cortado', 'did' => $did, 'destino' => $dest]);
}

// restore
$dest = trim((string)($a['did_dest_backup'] ?? ''));
if ($dest === '') json_out(['error' => 'sin_destino_restaurar'], 422);
$r = pbx_call('did', 'edit', construir_params_did_edit($did, $dest), $server);
if (empty($r['ok'])) {
  audit($u['id'], 'desvio_restore_error', "agente#$agentId did=$did dest=$dest");
  json_out(['error' => 'pbx_error', 'http' => (int)($r['http'] ?? 0)], 502);
}
db()->prepare("UPDATE agentes SET estado_desvio = 'normal', did_dest_backup = NULL, actualizado = NOW() WHERE id = ?")->execute([$agentId]);
audit($u['id'], 'desvio_restore', "agente#$agentId did=$did dest=$dest");
json_out(['ok' => true, 'estado_desvio' => 'normal', 'did' => $did, 'destino' => $dest]);
