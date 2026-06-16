<?php
// Genera panel-secret/config.prod.php a partir de la plantilla config.sample.php, rellenando los secretos.
// Lee apikey + n8n (webhook/token) del config.php LOCAL (ya verificado contra el PBX y n8n real).
// NO toca el config.php local (que sigue siendo el de SQLite para pruebas).
// Uso: php build_prod_config.php <db_pass> <cron_token> <cc>
[$_, $dbpass, $crontoken, $cc] = array_pad($argv, 4, '');
if ($dbpass === '' || $crontoken === '' || $cc === '') { fwrite(STDERR, "uso: build_prod_config.php <db_pass> <cron_token> <cc>\n"); exit(1); }

$dir   = 'C:/Users/Lucia/Documents/Panel-Minutos-App/panel-secret';
$local = @require $dir . '/config.php';
if (!is_array($local)) { fwrite(STDERR, "ERROR: no se pudo leer el config.php local\n"); exit(1); }

$apikey  = (string)($local['pbx']['apikey'] ?? '');
$webhook = (string)($local['n8n']['webhook'] ?? '');
$token   = (string)($local['n8n']['token'] ?? '');
if ($apikey === '' || $webhook === '' || $token === '') { fwrite(STDERR, "ERROR: faltan apikey/webhook/token en el config local\n"); exit(1); }

// Seguridad: ningún secreto puede contener comilla simple o backslash (rompería el string del template).
foreach (['dbpass'=>$dbpass,'crontoken'=>$crontoken,'cc'=>$cc,'apikey'=>$apikey,'webhook'=>$webhook,'token'=>$token] as $k=>$v) {
  if (strpbrk($v, "'\\") !== false) { fwrite(STDERR, "ERROR: el valor '$k' contiene caracteres no permitidos (' o \\)\n"); exit(1); }
}

$tpl = file_get_contents($dir . '/config.sample.php');
$out = strtr($tpl, [
  '__DB_PASS__'     => $dbpass,
  '__PBX_APIKEY__'  => $apikey,
  '__N8N_WEBHOOK__' => $webhook,
  '__N8N_TOKEN__'   => $token,
  '__N8N_CC__'      => $cc,
  '__CRON_TOKEN__'  => $crontoken,
]);
if (strpos($out, '__') !== false) { fwrite(STDERR, "AVISO: quedan placeholders sin rellenar.\n"); }
file_put_contents($dir . '/config.prod.php', $out);
echo "config.prod.php generado: db(mysql)+apikey+n8n[webhook,token,cc=$cc]+cron_token. auto_cut_100=true.\n";
