<?php
declare(strict_types=1);
// Núcleo de medición de consumo (CDR) + auto-corte al 100% + aviso por email.
// Lo usan metering.php (web, con sesión) y cron.php (CLI/token, sin sesión).
require_once __DIR__ . '/pbx.php';
require_once __DIR__ . '/notify.php';

// $clientId 0 = todos. $quick = solo recalcula hoy. $auditUser = id de usuario para auditoría (0 = sistema/cron).
function run_metering(int $clientId = 0, bool $quick = false, int $auditUser = 0): array {
  $periodo = date('Y-m');
  $day1    = date('M-d-Y', strtotime(date('Y-m-01')));
  $hoy     = date('M-d-Y');
  $ayer    = date('M-d-Y', strtotime('-1 day'));
  $autoCut = !empty(cfg()['app']['auto_cut_100']);

  $stc = db()->prepare('SELECT id, nombre, correo, tenant, minutos_contratados, desvio_100 FROM clientes' . ($clientId ? ' WHERE id = ?' : ''));
  $stc->execute($clientId ? [$clientId] : []);
  $clientes = $stc->fetchAll();

  $upFull = db()->prepare(
    'INSERT INTO consumo (agente_id, periodo, minutos_usados, base_mes, actualizado)
          VALUES (?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE minutos_usados = VALUES(minutos_usados), base_mes = VALUES(base_mes), actualizado = NOW()'
  );
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
      $needle = trim((string)($a['ddi'] ?? ''));
      if ($needle === '') $needle = trim((string)($a['dial_number'] ?? ''));
      if ($needle === '') { $detalle[] = ['agente' => $a['nombre'], 'minutos' => 0, 'nota' => 'sin DDI ni dial']; continue; }

      $mh     = pbx_cdr_minutos($needle, $hoy, $hoy, $server);   // hoy = único día que cambia
      $hoyMin = !empty($mh['ok']) ? (int)$mh['minutos'] : 0;

      if ($quick) {
        $baseStmt->execute([(int)$a['id'], $periodo]);
        $base = $baseStmt->fetchColumn();
        if ($base === false) {
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
        $mp   = pbx_cdr_minutos($needle, $day1, $ayer, $server);
        $base = !empty($mp['ok']) ? (int)$mp['minutos'] : 0;
        $min  = $base + $hoyMin;
        $upFull->execute([(int)$a['id'], $periodo, $min, $base]);
      }
      $total += $min;
      $detalle[] = ['agente' => $a['nombre'], 'minutos' => $min];
    }
    audit($auditUser, $quick ? 'metering_rapido' : 'metering', 'Cliente #' . $cli['id'] . ': ' . $total . ' min (' . count($agentes) . ' agentes)');

    // AUTO-CORTE al 100% (reactivación manual en la GUI; la API no devuelve el DID al agente IA).
    $cortados = [];
    $contr = (int)($cli['minutos_contratados'] ?? 0);
    if ($autoCut && $contr > 0 && $total >= $contr) {
      foreach ($agentes as $a) {
        if (($a['estado_desvio'] ?? 'normal') === 'cortado') continue;
        $did = trim((string)($a['ddi'] ?? ''));
        if ($did === '') continue;
        $dest = trim((string)($a['ivr_corte'] ?? ''));
        if ($dest === '') $dest = trim((string)($cli['desvio_100'] ?? ''));
        if ($dest === '' || !ctype_digit($dest)) continue;
        $cut = pbx_cortar_did($server, $did, $dest, 3);   // dest_type 3 = IVR
        if (!empty($cut['ok'])) {
          db()->prepare("UPDATE agentes SET estado_desvio = 'cortado', actualizado = NOW() WHERE id = ?")->execute([(int)$a['id']]);
          audit($auditUser, 'auto_corte', 'agente#' . $a['id'] . ' did=' . $did . ' -> IVR ' . $dest);
          $cortados[] = $a['nombre'];
        } else {
          audit($auditUser, 'auto_corte_error', 'agente#' . $a['id'] . ' did=' . $did . ' ' . json_encode($cut['raw'] ?? $cut['error'] ?? null, JSON_UNESCAPED_UNICODE));
        }
      }
    }
    // Aviso por email al 75% / 100% (se resetea al bajar de 75%).
    avisar_consumo_si_corresponde($cli, $total, $contr);

    $resumen[] = ['cliente' => $cli['nombre'], 'minutos_total' => $total, 'contratado' => $contr, 'agentes' => $detalle, 'cortados' => $cortados];
  }

  return ['ok' => true, 'periodo' => $periodo, 'resumen' => $resumen];
}
