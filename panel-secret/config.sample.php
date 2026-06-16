<?php
// PLANTILLA de configuración de PRODUCCIÓN. provision.ps1 la rellena y genera panel-secret/config.prod.php,
// que upload.ps1 sube como /home/conexiatec/panel-secret/config.php (FUERA del docroot, nunca en git).
return [
  'db' => [
    'driver'  => 'mysql',
    'host'    => 'localhost',
    'name'    => 'conexiatec_panel',
    'user'    => 'conexiatec_panel',
    'pass'    => '__DB_PASS__',
    'charset' => 'utf8mb4',
  ],
  'pbx' => [
    'base'   => 'https://sip.conexiatec.com',
    'server' => 18,                                   // tenant 216 = server 18 (por defecto; se resuelve por código)
    'apikey' => '__PBX_APIKEY__',                     // clave del PBX; vive SOLO aquí (fuera del docroot), nunca en el navegador
  ],
  'app' => [
    'env'            => 'prod',
    'allowed_origin' => 'https://agentsellingpanel.conexiatec.com',
    'timezone'       => 'Europe/Madrid',
    'session_name'   => 'cxpanel',
    'totp_issuer'    => 'Conexia Panel',
    'cdr_date_fmt'   => 'M-d-Y',
    'auto_cut_100'   => true,                          // corta el agente automáticamente al 100% (did.edit a IVR)
    'cron_token'     => '__CRON_TOKEN__',              // protege api/cron.php cuando se llama por web (?token=...)
  ],
  'n8n' => [
    'webhook' => '__N8N_WEBHOOK__',                   // workflow PANEL-AGENTE-IA-MAIL
    'token'   => '__N8N_TOKEN__',                     // cabecera x-panel-token
    'cc'      => '__N8N_CC__',                         // copia del aviso (PROD: SAC)
  ],
];
