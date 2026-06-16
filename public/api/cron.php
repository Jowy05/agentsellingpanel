<?php
// public/api/cron.php — medición periódica desatendida (para que los avisos 75/100 y el auto-corte
// funcionen aunque NADIE tenga el panel abierto). Se llama desde el cron de cPanel:
//   CLI:  php /home/conexiatec/agentsellingpanel.conexiatec.com/api/cron.php
//   Web:  curl -s "https://agentsellingpanel.conexiatec.com/api/cron.php?token=<cron_token>"
// En web exige el token (config app.cron_token). En CLI no hace falta.
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/meter_run.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
  $token = (string)(cfg()['app']['cron_token'] ?? '');
  $given = (string)($_GET['token'] ?? $_POST['token'] ?? '');
  if ($token === '' || !hash_equals($token, $given)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'forbidden']);
    exit;
  }
}

try {
  $r = run_metering(0, false, 0);   // todos los clientes, medición completa, usuario = sistema
} catch (Throwable $e) {
  error_log('cron.php: ' . $e->getMessage());
  if ($isCli) { fwrite(STDERR, "cron error: " . $e->getMessage() . "\n"); exit(1); }
  http_response_code(500); header('Content-Type: application/json'); echo json_encode(['error' => 'cron_error']); exit;
}

if ($isCli) {
  echo "metering OK · periodo {$r['periodo']} · " . count($r['resumen']) . " clientes\n";
} else {
  header('Content-Type: application/json');
  echo json_encode($r);
}
