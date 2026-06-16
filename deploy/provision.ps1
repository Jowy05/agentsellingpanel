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

# --- Crear recursos NUEVOS ---
"Creando subdominio..."
$r1 = UAPI-Post "SubDomain/addsubdomain" @{ domain=$SUBDOMAIN; rootdomain=$ROOTDOMAIN; dir=$DOCROOT_DIR }
"  status=" + $r1.status + "  " + ($r1.errors -join "; ")
"Creando BD..."
$r2 = UAPI-Post "Mysql/create_database" @{ name=$DB }
"  status=" + $r2.status + "  " + ($r2.errors -join "; ")
"Creando usuario BD..."
$r3 = UAPI-Post "Mysql/create_user" @{ name=$DBUSER; password=$dbpass }
"  status=" + $r3.status + "  " + ($r3.errors -join "; ")
"Asignando privilegios..."
$r4 = UAPI-Post "Mysql/set_privileges_on_database" @{ user=$DBUSER; database=$DB; privileges="ALL PRIVILEGES" }
"  status=" + $r4.status + "  " + ($r4.errors -join "; ")

# --- Construir config.prod.php (fuera del docroot) con TODOS los secretos. NO toca el config.php local (sqlite). ---
$php = "C:\Users\Lucia\Documents\Panel-Minutos-App\.localtools\php\php.exe"
$ini = "C:\Users\Lucia\Documents\Panel-Minutos-App\.localtools\php\php.ini"
$builder = "C:\Users\Lucia\Documents\Panel-Minutos-App\deploy\build_prod_config.php"
& $php -c $ini $builder $dbpass $crontoken $CC
"config.prod.php generado en panel-secret\ (NO va a git; upload.ps1 lo sube como /home/conexiatec/panel-secret/config.php)."
"PROVISIÓN COMPLETA. Siguiente: (1) importar schema.sql en la BD, (2) ejecutar upload.ps1, (3) seed_admin, (4) cron."
