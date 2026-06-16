# =====================================================================
#  PROVISIÓN AISLADA — Panel de minutos (cPanel de Conexia)
#  Crea SOLO recursos NUEVOS: subdominio panel.conexiatec.com + BD
#  conexiatec_panel + usuario. NO toca nada existente.
#
#  SEGURIDAD: por defecto $GO = $false => solo comprueba (lectura) y
#  muestra el plan. Pon $GO = $true para crear de verdad.
# =====================================================================
$ErrorActionPreference = "Stop"
$GO = $false   # <-- poner a $true SOLO tras revisar

$SUBDOMAIN = "agentsellingpanel"
$ROOTDOMAIN = "conexiatec.com"
$DOCROOT_DIR = "agentsellingpanel.conexiatec.com"   # relativo a /home/conexiatec
$DB = "conexiatec_panel"
$DBUSER = "conexiatec_panel"
$CC = "sac@conexiatec.com"   # copia de los avisos en PROD (confirmar el correo real de SAC)

$cp = "https://conexiatec.com:2083"
$sh = "C:\Users\Lucia\Documents\WEB CONEXIA\_tooling\deploy\_upload-design-v140.sh"
$tok = ((Get-Content $sh | Where-Object { $_ -like 'TOKEN=*' } | Select-Object -First 1).Split('"'))[1]
if ([string]::IsNullOrWhiteSpace($tok)) { throw "No se pudo extraer el TOKEN de cPanel de $sh (revisa el fichero)." }
$H = @{ Authorization = ("cpanel conexiatec:" + $tok) }

function UAPI-Get($path) { Invoke-RestMethod -Uri ("$cp/execute/$path") -Headers $H -TimeoutSec 60 }
function UAPI-Post($path, [hashtable]$body) {
  Invoke-RestMethod -Uri ("$cp/execute/$path") -Headers $H -Method Post -Body $body -TimeoutSec 60
}

# --- Comprobaciones de seguridad (lectura): no colisionar con lo existente ---
$dom = UAPI-Get "DomainInfo/list_domains"
$subExists = @($dom.data.sub_domains) -contains "$SUBDOMAIN.$ROOTDOMAIN"
$dbs = (UAPI-Get "Mysql/list_databases").data.database
$dbExists = @($dbs) -contains $DB

"== PLAN =="
"Subdominio:  $SUBDOMAIN.$ROOTDOMAIN   (docroot /home/conexiatec/$DOCROOT_DIR)   existe? $subExists"
"BD:          $DB                                                          existe? $dbExists"
"Usuario BD:  $DBUSER"
"GO:          $GO"

if (-not $GO) { "`n[Modo comprobación] No se ha creado nada. Revisa el plan y pon `$GO = `$true para ejecutar."; return }
if ($subExists -or $dbExists) { throw "ABORTADO: el subdominio o la BD ya existen. No se sobrescribe nada." }

# --- Generar contraseña fuerte para la BD + token del cron (sin comillas ni backslash) ---
$chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%*-_'.ToCharArray()
$dbpass = -join (1..28 | ForEach-Object { $chars | Get-Random })
$hexchars = '0123456789abcdef'.ToCharArray()
$crontoken = -join (1..40 | ForEach-Object { $hexchars | Get-Random })

# --- Crear recursos NUEVOS (abortando si algo falla, para no dejar nada a medias) ---
# Si una operación falla a mitad, revierte MANUALMENTE solo lo de esta app (ver DEPLOY.md): borra el
# subdominio agentsellingpanel + la BD/usuario conexiatec_panel. NUNCA toques nada más del cPanel.
function Check($r, $paso) {
  if ($r.status -ne 1) {
    $det = ($r.errors -join '; ')
    throw ("FALLO en " + $paso + ": " + $det + " -> revierte SOLO lo de esta app (ver DEPLOY.md). No se sigue.")
  }
  "  OK " + $paso
}
"Creando subdominio..."
Check (UAPI-Post "SubDomain/addsubdomain" @{ domain=$SUBDOMAIN; rootdomain=$ROOTDOMAIN; dir=$DOCROOT_DIR }) "addsubdomain"
"Creando BD..."
Check (UAPI-Post "Mysql/create_database" @{ name=$DB }) "create_database"
"Creando usuario BD..."
Check (UAPI-Post "Mysql/create_user" @{ name=$DBUSER; password=$dbpass }) "create_user"
"Asignando privilegios..."
Check (UAPI-Post "Mysql/set_privileges_on_database" @{ user=$DBUSER; database=$DB; privileges="ALL PRIVILEGES" }) "set_privileges"

# --- Construir config.prod.php (fuera del docroot) con TODOS los secretos. NO toca el config.php local (sqlite). ---
$php = "C:\Users\Lucia\Documents\Panel-Minutos-App\.localtools\php\php.exe"
$ini = "C:\Users\Lucia\Documents\Panel-Minutos-App\.localtools\php\php.ini"
$builder = "C:\Users\Lucia\Documents\Panel-Minutos-App\deploy\build_prod_config.php"
& $php -c $ini $builder $dbpass $crontoken $CC
"config.prod.php generado en panel-secret\ (NO va a git; upload.ps1 lo sube como /home/conexiatec/panel-secret/config.php)."
"PROVISION COMPLETA. Siguiente: (1) importar schema.sql en la BD, (2) ejecutar upload.ps1, (3) seed_admin, (4) cron."
