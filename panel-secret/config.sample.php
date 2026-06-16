<?php
// Copia este archivo a config.php (en /home/conexiatec/panel-secret/) y rellena los secretos.
// config.php vive FUERA del docroot y NUNCA se sube a git.
return [
  'db' => [
    'host'    => 'localhost',
    'name'    => 'conexiatec_panel',
    'user'    => 'conexiatec_panel',
    'pass'    => '__PON_AQUI_LA_PASS_DE_LA_BD__',
    'charset' => 'utf8mb4',
  ],
  'pbx' => [
    'base'   => 'https://sip.conexiatec.com',
    'server' => 18,                                   // tenant 216 = server 18
    'apikey' => '__PON_AQUI_LA_APIKEY_DEL_TENANT__',  // clave del tenant, NUNCA la master
  ],
  'app' => [
    'env'            => 'prod',
    'allowed_origin' => 'https://agentsellingpanel.conexiatec.com',
    'session_name'   => 'cxpanel',
    'totp_issuer'    => 'Conexia Panel',
    'cdr_date_fmt'   => 'M-d-Y',                       // PHP date() -> "Jun-01-2026"
  ],
  'n8n' => [
    'webhook' => '',                                   // URL del webhook de avisos (a rellenar)
  ],
];
