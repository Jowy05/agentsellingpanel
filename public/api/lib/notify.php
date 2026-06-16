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
  $pct    = $contr > 0 ? (int)round($usado / $contr * 100) : 0;
  $pctBar = max(0, min(100, $pct));
  $rest   = max(0, $contr - $usado);
  $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  $n = $e($nombre);

  if ($nivel >= 100) {
    $subject = "Servicio desviado · {$nombre} (100% de minutos)";
    $badge   = '100% · DESVIADO';
    $titulo  = 'Has alcanzado el 100% de tus minutos';
    $intro   = "El Agente de Voz IA de <b>{$n}</b> ha consumido el <b>100%</b> de los minutos contratados, por lo que las llamadas se han <b>desviado temporalmente</b>.";
    $pie     = 'Para <b>reactivar el servicio</b> y ampliar tu bolsa de minutos, responde a este correo y te ayudamos enseguida.';
    $color   = '#c0392b'; $soft = '#fdecea';
  } else {
    $subject = "Aviso de consumo · {$nombre} ({$pct}%)";
    $badge   = $pct . '% CONSUMIDO';
    $titulo  = 'Estás llegando al límite de minutos';
    $intro   = "El Agente de Voz IA de <b>{$n}</b> ha consumido el <b>{$pct}%</b> de los minutos contratados.";
    $pie     = 'Si prevés más uso, podemos <b>ampliar tu bolsa</b> para evitar interrupciones. Responde a este correo y lo vemos.';
    $color   = '#b9770e'; $soft = '#fef6e7';
  }

  $html =
  '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#eef2f1;margin:0;padding:24px 0">'
  . '<tr><td align="center">'
  . '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;font-family:Arial,Helvetica,sans-serif">'
  .   '<tr><td style="background:#0f2e2b;padding:22px 30px">'
  .     '<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:.3px">Conexia</span>'
  .     '<span style="color:#6fd0bf;font-size:13px"> &nbsp;&middot;&nbsp; Agente de Voz IA</span>'
  .   '</td></tr>'
  .   '<tr><td style="padding:28px 30px 6px">'
  .     '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:' . $soft . ';border-radius:10px"><tr><td style="padding:16px 18px">'
  .       '<span style="display:inline-block;background:' . $color . ';color:#ffffff;font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;letter-spacing:.4px">' . $badge . '</span>'
  .       '<div style="color:' . $color . ';font-size:18px;font-weight:700;margin-top:10px">' . $titulo . '</div>'
  .     '</td></tr></table>'
  .     '<p style="color:#222;font-size:15px;line-height:1.55;margin:20px 0 4px">Hola,</p>'
  .     '<p style="color:#222;font-size:15px;line-height:1.55;margin:0 0 20px">' . $intro . '</p>'
  .     '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 6px"><tr>'
  .       '<td style="font-size:13px;color:#666">Consumo del periodo</td>'
  .       '<td align="right" style="font-size:13px;color:' . $color . ';font-weight:700">' . $pct . '%</td>'
  .     '</tr></table>'
  .     '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#e6eae9;border-radius:8px"><tr><td style="padding:0;font-size:0;line-height:0">'
  .       '<table role="presentation" cellpadding="0" cellspacing="0" width="' . $pctBar . '%"><tr><td style="background:' . $color . ';height:10px;border-radius:8px;font-size:0;line-height:0">&nbsp;</td></tr></table>'
  .     '</td></tr></table>'
  .     '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:22px 0 4px;font-size:14px">'
  .       '<tr><td style="padding:8px 0;color:#666;border-bottom:1px solid #eee">Minutos consumidos</td><td align="right" style="padding:8px 0;color:#111;font-weight:700;border-bottom:1px solid #eee">' . $e($usado) . ' / ' . $e($contr) . ' min</td></tr>'
  .       '<tr><td style="padding:8px 0;color:#666;border-bottom:1px solid #eee">Restantes</td><td align="right" style="padding:8px 0;color:#111;border-bottom:1px solid #eee">' . $e($rest) . ' min</td></tr>'
  .     '</table>'
  .     '<p style="color:#444;font-size:14px;line-height:1.55;margin:20px 0 4px">' . $pie . '</p>'
  .   '</td></tr>'
  .   '<tr><td style="background:#f4f6f6;padding:16px 30px;color:#8a8a8a;font-size:12px;line-height:1.5">'
  .     'Conexia Telecom &middot; Aviso automático del consumo de tu Agente de Voz IA.'
  .   '</td></tr>'
  . '</table></td></tr></table>';

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
