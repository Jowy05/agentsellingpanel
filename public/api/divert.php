<?php
declare(strict_types=1);
// Desvio al 100% por IVR. Corta o restaura el destino del DID del cliente en la centralita.
// Contrato: require_auth(); POST {client_id, action:'cut'|'restore'}. audit() SIEMPRE.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/pbx.php';

// ===================================================================
// TODO: verificar nombres de params de pbxware.did.edit con la centralita
// Los parametros EXACTOS de did.edit estan PENDIENTES de confirmar contra
// la API real de PBXware. Centralizamos aqui su construccion para que, cuando
// se confirmen los nombres correctos, solo haya que tocar esta funcion.
// ===================================================================
if (!function_exists('construir_params_did_edit')) {
  function construir_params_did_edit(string $did, string $dest): array {
    // 'did'  -> numero/identificador del DID a editar
    // 'dest' -> destino al que apuntar (desvío al 100% o destino original guardado)
    return [
      'did'         => $did,
      'destination' => $dest,
    ];
  }
}

// Localiza el destino actual del DID en la lista de DIDs de la centralita.
// La estructura de did.list esta pendiente de confirmar, por eso buscamos el
// destino de forma defensiva en las claves mas probables.
if (!function_exists('leer_destino_actual')) {
  function leer_destino_actual(string $did): ?string {
    $r = pbx_call('did', 'list');
    if (empty($r['ok'])) return null;

    $data = $r['data'] ?? null;
    if (!is_array($data)) return null;

    // did.list puede venir como [ { "<id>": {campos...}, ... } ] o como lista plana.
    $filas = $data[0] ?? $data;
    if (!is_array($filas)) return null;

    foreach ($filas as $fila) {
      if (!is_array($fila)) continue;
      $num = (string)($fila['did'] ?? $fila['number'] ?? $fila['ddi'] ?? '');
      if ($num !== '' && $num === $did) {
        $dest = $fila['destination'] ?? $fila['dest'] ?? null;
        return $dest === null ? null : (string)$dest;
      }
    }
    return null;
  }
}

// Solo cambios por POST.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(['error' => 'method_not_allowed'], 405);
}

// Sesion completa obligatoria (uid + rol + twofa_ok). Cualquier tecnico autenticado.
$u = require_auth();

$in       = body_json();
$clientId = (int)($in['client_id'] ?? 0);
$action   = (string)($in['action'] ?? '');

if ($clientId <= 0 || !in_array($action, ['cut', 'restore'], true)) {
  json_out(['error' => 'datos_invalidos'], 422);
}

// Carga el cliente (sentencia preparada, nunca concatenamos entrada).
$st = db()->prepare('SELECT id, slug, nombre, ddi, desvio_100, did_dest_backup, estado_desvio
                     FROM clientes WHERE id = ?');
$st->execute([$clientId]);
$cli = $st->fetch();

if (!$cli) {
  json_out(['error' => 'cliente_no_encontrado'], 404);
}

$did = trim((string)($cli['ddi'] ?? ''));
if ($did === '') {
  json_out(['error' => 'cliente_sin_ddi'], 422);
}

if ($action === 'cut') {
  $destDespues = trim((string)($cli['desvio_100'] ?? ''));
  if ($destDespues === '') {
    json_out(['error' => 'cliente_sin_desvio'], 422);
  }

  // Lee el destino actual del DID para poder restaurarlo despues.
  $destAntes = leer_destino_actual($did);   // null si no se pudo leer

  // Guarda el backup del destino actual SOLO si:
  //  - lo hemos podido leer, y
  //  - no coincide ya con el destino de desvío (evita perder el backup real si se
  //    pulsa 'cut' dos veces: en el segundo corte el "actual" ya seria el destino de desvío).
  if ($destAntes !== null && $destAntes !== '' && $destAntes !== $destDespues) {
    db()->prepare('UPDATE clientes SET did_dest_backup = ?, actualizado = NOW() WHERE id = ?')
        ->execute([$destAntes, $clientId]);
  }

  // Aplica el cambio en la centralita: apunta el DID al IVR de corte.
  $params = construir_params_did_edit($did, $destDespues);
  $r = pbx_call('did', 'edit', $params);

  if (empty($r['ok'])) {
    // No tocamos estado si la centralita no confirma el cambio.
    audit($u['id'], 'desvio_cut_error',
      "did={$did} antes=" . ($destAntes ?? '?') . " despues={$destDespues} resultado=fallo");
    // Devolvemos solo el codigo HTTP del PBX; nunca su data/apikey ni secretos.
    json_out(['error' => 'pbx_error', 'http' => (int)($r['http'] ?? 0)], 502);
  }

  // Cambio confirmado: marca el desvio como cortado.
  db()->prepare("UPDATE clientes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")
      ->execute([$clientId]);

  audit($u['id'], 'desvio_cut',
    "did={$did} antes=" . ($destAntes ?? '?') . " despues={$destDespues} resultado=ok");

  json_out(['ok' => true, 'estado_desvio' => 'cortado', 'did' => $did, 'destino' => $destDespues]);
}

// action === 'restore'
$destAntes   = trim((string)($cli['desvio_100'] ?? ''));      // destino mientras estaba cortado
$destDespues = trim((string)($cli['did_dest_backup'] ?? '')); // destino original del DID (el agente), guardado al cortar

if ($destDespues === '') {
  json_out(['error' => 'sin_destino_restaurar'], 422);
}

$params = construir_params_did_edit($did, $destDespues);
$r = pbx_call('did', 'edit', $params);

if (empty($r['ok'])) {
  // No tocamos estado si la centralita no confirma el cambio.
  audit($u['id'], 'desvio_restore_error',
    "did={$did} antes={$destAntes} despues={$destDespues} resultado=fallo");
  json_out(['error' => 'pbx_error', 'http' => (int)($r['http'] ?? 0)], 502);
}

// Cambio confirmado: marca el desvio como normal y limpia el backup.
db()->prepare("UPDATE clientes SET estado_desvio = 'normal', did_dest_backup = NULL, actualizado = NOW() WHERE id = ?")
    ->execute([$clientId]);

audit($u['id'], 'desvio_restore',
  "did={$did} antes={$destAntes} despues={$destDespues} resultado=ok");

json_out(['ok' => true, 'estado_desvio' => 'normal', 'did' => $did, 'destino' => $destDespues]);
