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

# --- Generar contraseña fuerte para la BD ---
$chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%*-_'.ToCharArray()
$dbpass = -join (1..28 | ForEach-Object { $chars | Get-Random })

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

# --- Construir config.php real (fuera del docroot) con los secretos ---
$pbxkey = (Get-Content "C:\Users\Lucia\Documents\AI AGENT\credenciales.md")[1].Trim()
$cfgPath = "C:\Users\Lucia\Documents\Panel-Minutos-App\panel-secret\config.php"
$sample = Get-Content "C:\Users\Lucia\Documents\Panel-Minutos-App\panel-secret\config.sample.php" -Raw
$cfg = $sample.Replace("__PON_AQUI_LA_PASS_DE_LA_BD__", $dbpass).Replace("__PON_AQUI_LA_APIKEY_DEL_TENANT__", $pbxkey)
[System.IO.File]::WriteAllText($cfgPath, $cfg, (New-Object System.Text.UTF8Encoding($false)))
"config.php generado en panel-secret\config.php (NO se sube a git; lo sube upload.ps1 a /home/conexiatec/panel-secret/)."
"PROVISIÓN COMPLETA. Siguiente: importar schema.sql y ejecutar upload.ps1."
