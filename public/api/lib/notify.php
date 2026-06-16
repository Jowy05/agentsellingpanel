<?php
declare(strict_types=1);
// Avisos de consumo por email vía el webhook de n8n (workflow PANEL-AGENTE-IA-MAIL).
// El webhook/token/cc viven en config['n8n']. El correo del cliente = clientes.correo.

// POST genérico al webhook de n8n. Devuelve true si HTTP 2xx.
function n8n_webhook_post(array $payload): bool {
  $n = cfg()['n8n'] ?? [];
  $url = trim((string)($n['webhook'] ?? ''));
  if ($url === '') return false;
  if (!isset($payload['cc']) && !empty($n['cc'])) $payload['cc'] = $n['cc'];
  $ch = curl_init($url);
  $headers = ['Content-Type: application/json'];
  if (!empty($n['token'])) $headers[] = 'x-panel-token: ' . $n['token'];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return $code >= 200 && $code < 300;
}

// Construye y envía el correo de aviso de consumo (nivel 75 o 100).
function aviso_consumo_html(string $nombre, int $nivel, int $usado, int $contr): array {
  $pct  = $contr > 0 ? (int)round($usado / $contr * 100) : 0;
  $rest = max(0, $contr - $usado);
  if ($nivel >= 100) {
    $subject = "Servicio desviado · {$nombre} (100% de minutos)";
    $intro   = "Tu Agente de Voz IA ha alcanzado el <b>100%</b> de los minutos contratados, por lo que el servicio se ha <b>desviado temporalmente</b>.";
    $cta     = "Para reactivarlo o ampliar tu bolsa de minutos, responde a este correo.";
    $color   = "#c0392b";
  } else {
    $subject = "Aviso de consumo · {$nombre} ({$pct}%)";
    $intro   = "Has consumido el <b>{$pct}%</b> de los minutos de tu Agente de Voz IA y te acercas al límite.";
    $cta     = "Si prevés más uso, podemos ampliar tu bolsa para evitar interrupciones.";
    $color   = "#d68910";
  }
  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;color:#1a1a1a">'
    . '<div style="border-left:4px solid ' . $color . ';padding:4px 14px;margin-bottom:16px">'
    . '<h2 style="margin:0 0 4px">' . $e($nombre) . '</h2>'
    . '<div style="color:#666;font-size:13px">Agente de Voz IA · Conexia</div></div>'
    . '<p>Hola,</p><p>' . $intro . '</p>'
    . '<table style="border-collapse:collapse;margin:14px 0;font-size:14px">'
    . '<tr><td style="padding:4px 14px 4px 0;color:#666">Minutos consumidos</td><td style="padding:4px 0"><b>' . $e($usado) . '</b> de ' . $e($contr) . ' min</td></tr>'
    . '<tr><td style="padding:4px 14px 4px 0;color:#666">Uso</td><td style="padding:4px 0"><b>' . $e($pct) . '%</b></td></tr>'
    . '<tr><td style="padding:4px 14px 4px 0;color:#666">Restantes</td><td style="padding:4px 0">' . $e($rest) . ' min</td></tr>'
    . '</table><p>' . $cta . '</p><p style="margin-top:18px">Un saludo,<br>Equipo Conexia</p></div>';
  return ['subject' => $subject, 'html' => $html];
}

// Comprueba el umbral del cliente y envía el aviso UNA vez por (cliente, periodo, nivel).
// Los flags se RESETEAN al bajar del umbral (p.ej. al ampliar minutos), para volver a avisar si se vuelve a cruzar
// — sin spam, porque el uso solo baja al recargar minutos.
function avisar_consumo_si_corresponde(array $cli, int $usado, int $contr): void {
  $correo = trim((string)($cli['correo'] ?? ''));
  if ($correo === '' || $contr <= 0) return;
  $pct     = (int)round($usado / $contr * 100);
  $cliId   = (int)$cli['id'];
  $periodo = date('Y-m');

  // Por debajo del 75% = han ampliado el plan -> resetear AMBOS avisos (75 y 100) para volver a enviarlos al cruzar.
  if ($pct < 75) {
    db()->prepare('DELETE FROM avisos_email WHERE cliente_id = ? AND periodo = ?')->execute([$cliId, $periodo]);
    return;
  }
  $nivel = $pct >= 100 ? 100 : 75;

  $chk = db()->prepare('SELECT 1 FROM avisos_email WHERE cliente_id = ? AND periodo = ? AND nivel = ?');
  $chk->execute([$cliId, $periodo, $nivel]);
  if ($chk->fetchColumn()) return;   // ya enviado este nivel (y no ha bajado del umbral desde entonces)

  $msg = aviso_consumo_html((string)$cli['nombre'], $nivel, $usado, $contr);
  $ok  = n8n_webhook_post(['to' => $correo, 'subject' => $msg['subject'], 'html' => $msg['html']]);

  $ins = db()->prepare('INSERT IGNORE INTO avisos_email (cliente_id, periodo, nivel, enviado) VALUES (?, ?, ?, NOW())');
  if ($ok) {
    $ins->execute([$cliId, $periodo, $nivel]);
    if ($nivel === 100) $ins->execute([$cliId, $periodo, 75]);   // al 100% ya no tiene sentido el de 75
    audit(0, 'aviso_email', "cliente#$cliId nivel=$nivel -> $correo");
  } else {
    audit(0, 'aviso_email_error', "cliente#$cliId nivel=$nivel -> $correo");
  }
}
