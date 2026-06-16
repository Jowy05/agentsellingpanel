<?php
declare(strict_types=1);
// Cliente PBXware. La clave vive en config (fuera del docroot) y nunca se expone al navegador.
// Operaciones usadas (lista blanca): cdr.download (lectura), ext.list, did.list, did.edit.

function pbx_call(string $object, string $method, array $params = []): array {
  $c = cfg()['pbx'];
  $q = http_build_query(array_merge([
    'server' => $c['server'],
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

// Suma minutos del CDR cuyos campos From/To contengan $needle (DID/extensión del agente),
// en el rango [start, end] con formato Mmm-DD-YYYY (p.ej. "Jun-01-2026").
// NOTA: el parámetro de paginación ('page') está pendiente de confirmar contra la API real;
// hoy se sabe que la respuesta trae 'next_page' y ~16 registros por página.
function pbx_cdr_minutos(string $needle, string $start, string $end): array {
  $page = 0; $segundos = 0; $registros = 0; $guard = 0; $next = false;
  do {
    $r = pbx_call('cdr', 'download', ['start' => $start, 'end' => $end, 'page' => $page]);
    if (!$r['ok']) return $r;
    $d = $r['data'];
    foreach (($d['csv'] ?? []) as $row) {
      $from = (string)($row[0] ?? '');
      $to   = (string)($row[1] ?? '');
      if (stripos($from, $needle) !== false || stripos($to, $needle) !== false) {
        $segundos += (int)($row[3] ?? 0);     // columna "Total Duration"
        $registros++;
      }
    }
    $next = !empty($d['next_page']);
    $page++; $guard++;
  } while ($next && $guard < 500);
  return ['ok' => true, 'minutos' => (int)round($segundos / 60), 'segundos' => $segundos, 'registros' => $registros];
}
