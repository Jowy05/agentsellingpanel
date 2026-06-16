<?php
declare(strict_types=1);
// Cliente PBXware. La clave vive en config (fuera del docroot) y nunca se expone al navegador.
// Operaciones usadas (lista blanca): cdr.download (lectura), ext.list, did.list, did.edit.

function pbx_call(string $object, string $method, array $params = [], ?int $server = null): array {
  $c = cfg()['pbx'];
  $q = http_build_query(array_merge([
    'server' => $server ?? $c['server'],
    'apikey' => $c['apikey'],
    'action' => "pbxware.$object.$method",
  ], $params));
  $url = rtrim($c['base'], '/') . '/?' . $q;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  $res  = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res === false) return ['ok' => false, 'error' => 'curl: ' . $err];
  return ['ok' => true, 'http' => $code, 'data' => json_decode($res, true)];
}

// Prueba de conectividad: cuenta extensiones del tenant. No expone la clave.
function pbx_ping(): array {
  $r = pbx_call('ext', 'list');
  if (!$r['ok']) return $r;
  $d = $r['data'];
  $n = 0;
  if (is_array($d)) {
    $first = $d[0] ?? $d;                 // ext.list devuelve [{ "id": {...}, ... }]
    if (is_array($first)) $n = count($first);
  }
  return ['ok' => true, 'http' => $r['http'], 'extensiones' => $n];
}

// Suma los minutos del CDR de las llamadas del DDI dado, en [start,end] (formato Mmm-DD-YYYY),
// consultando DÍA A DÍA. El CDR pagina a máximo 1000 registros sin offset, así que se acota por
// fecha (un día de un tenant suele ir muy por debajo de 1000) para no perder llamadas.
function pbx_cdr_minutos(string $ddi, string $start, string $end, ?int $server = null): array {
  $d1 = DateTime::createFromFormat('M-d-Y', $start);
  $d2 = DateTime::createFromFormat('M-d-Y', $end);
  if (!$d1 || !$d2) return ['ok' => false, 'error' => 'fecha_invalida'];
  $d1->setTime(0, 0); $d2->setTime(0, 0);
  $seg = 0; $reg = 0; $guard = 0; $cur = clone $d1;
  while ($cur <= $d2 && $guard < 92) {
    $dia = $cur->format('M-d-Y');
    $r = pbx_call('cdr', 'download', ['start' => $dia, 'end' => $dia, 'limit' => 1000], $server);
    if (!empty($r['ok']) && is_array($r['data'])) {
      foreach (($r['data']['csv'] ?? []) as $row) {
        $from = (string)($row[0] ?? ''); $to = (string)($row[1] ?? '');
        if ($from === $ddi || $to === $ddi
            || strpos($from, '(' . $ddi . ')') !== false
            || strpos($to,   '(' . $ddi . ')') !== false) {
          $seg += (int)($row[3] ?? 0);   // Total Duration (s)
          $reg++;
        }
      }
    }
    $cur->modify('+1 day'); $guard++;
  }
  return ['ok' => true, 'minutos' => (int)round($seg / 60), 'segundos' => $seg, 'registros' => $reg];
}

// Resuelve el código de tenant (p.ej. "216") al número de servidor de la API (p.ej. 18).
// Usa pbxware.tenant.list en el master (server=1): cada entrada va keyed por el nº de servidor.
function pbx_tenant_server(string $code): ?int {
  $r = pbx_call('tenant', 'list', [], 1);
  if (empty($r['ok']) || !is_array($r['data'])) return null;
  foreach ($r['data'] as $srv => $t) {
    if (is_array($t) && (string)($t['tenantcode'] ?? '') === $code) return (int)$srv;
  }
  return null;
}

// Lista los agentes de voz IA de un tenant: DIDs cuyo destino (ext) es un UUID.
// Devuelve [{uuid, nombre, ddi}] con nombre = nombre del DID y ddi = número del DID.
function pbx_list_agents(int $server): array {
  $r = pbx_call('did', 'list', [], $server);
  if (empty($r['ok']) || !is_array($r['data'])) return ['ok' => false, 'error' => 'did_list'];
  $ag = [];
  foreach ($r['data'] as $d) {
    if (!is_array($d)) continue;
    $ext = (string)($d['ext'] ?? '');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ext)) {
      $ag[] = ['uuid' => $ext, 'nombre' => (string)($d['name'] ?? ''), 'ddi' => (string)($d['number'] ?? '')];
    }
  }
  return ['ok' => true, 'agentes' => $ag];
}
