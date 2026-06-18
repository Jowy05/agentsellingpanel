<?php
declare(strict_types=1);
// public/api/send_mail.php — envío MANUAL de un correo (desde la pestaña Avisos) vía el webhook de n8n.
// Requiere sesión (admin o técnico). Audita cada envío. No añade CC (correo directo al destinatario).
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/lib/notify.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['error' => 'method_not_allowed'], 405);
$u = require_admin();   // envío manual de correos (a destinatario arbitrario): solo admin

$in      = body_json();
$to      = strtolower(trim((string)($in['to'] ?? '')));
$subject = trim((string)($in['subject'] ?? ''));
$cuerpo  = (string)($in['body'] ?? '');

if (!filter_var($to, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'email_invalido', 'detalle' => 'El destinatario no es un correo válido.'], 422);
if ($subject === '' || mb_strlen($subject) > 200) json_out(['error' => 'asunto_requerido'], 422);
if (trim($cuerpo) === '') json_out(['error' => 'mensaje_requerido'], 422);

$ok = n8n_webhook_post(['to' => $to, 'subject' => $subject, 'html' => correo_generico_html($cuerpo)]);   // CC a SAC (del config)
audit($u['id'], 'mail_manual', 'to=' . $to . ' asunto=' . mb_substr($subject, 0, 80));

json_out($ok ? ['ok' => true] : ['error' => 'envio_fallido', 'detalle' => 'No se pudo enviar (revisa el webhook de n8n).'], $ok ? 200 : 502);
