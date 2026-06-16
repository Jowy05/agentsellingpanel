# =====================================================================
#  SUBIDA AISLADA — Panel de minutos (cPanel de Conexia)
#  Sube panel-secret/config.php  -> /home/conexiatec/panel-secret/
#       public/*                 -> /home/conexiatec/panel.conexiatec.com/
#  SOLO escribe dentro de esas dos rutas. No toca nada más.
#
#  SEGURIDAD: $GO = $false por defecto (solo lista lo que haría).
# =====================================================================
$ErrorActionPreference = "Stop"
$GO = $false   # <-- poner a $true SOLO tras revisar

$cp = "https://conexiatec.com:2083"
$sh = "C:\Users\Lucia\Documents\WEB CONEXIA\_tooling\deploy\_upload-design-v140.sh"
$tok = ((Get-Content $sh | Where-Object { $_ -like 'TOKEN=*' } | Select-Object -First 1).Split('"'))[1]
$H = @{ Authorization = ("cpanel conexiatec:" + $tok) }

$APP = "C:\Users\Lucia\Documents\Panel-Minutos-App"
$DOCROOT_REMOTE = "/agentsellingpanel.conexiatec.com"   # relativo al home de cPanel
$SECRET_REMOTE  = "/panel-secret"

function Remote-Mkdir($parent, $name) {
  if (-not $GO) { "  [mkdir] $parent/$name"; return }
  try { Invoke-RestMethod -Uri ("$cp/execute/Fileman/mkdir") -Headers $H -Method Post -Body @{ path=$parent; name=$name } -TimeoutSec 60 | Out-Null } catch {}
}
function Remote-Upload($localFile, $remoteDir) {
  if (-not $GO) { "  [put]   $remoteDir/$([IO.Path]::GetFileName($localFile))"; return }
  $r = & curl.exe -sS -m 120 -H ("Authorization: cpanel conexiatec:" + $tok) `
      -F ("dir=" + $remoteDir) -F "overwrite=1" -F ("file-1=@" + $localFile) `
      "$cp/execute/Fileman/upload_files"
  "  put $([IO.Path]::GetFileName($localFile)) -> $remoteDir : " + ($r -replace '\s+',' ').Substring(0,[Math]::Min(80,($r).Length))
}

# Sube recursivamente $localRoot al $remoteRoot (home-relativo), creando subdirectorios.
function Upload-Tree($localRoot, $remoteRoot) {
  $localRoot = (Resolve-Path $localRoot).Path
  # ficheros del nivel raíz
  Get-ChildItem -LiteralPath $localRoot -File | ForEach-Object { Remote-Upload $_.FullName $remoteRoot }
  # subdirectorios (crear y subir)
  Get-ChildItem -LiteralPath $localRoot -Directory -Recurse | ForEach-Object {
    $rel = $_.FullName.Substring($localRoot.Length).TrimStart('\').Replace('\','/')
    $parent = $remoteRoot
    $name = Split-Path $rel -Leaf
    $relParent = (Split-Path $rel -Parent).Replace('\','/')
    if ($relParent) { $parent = "$remoteRoot/$relParent" }
    Remote-Mkdir $parent $name
    Get-ChildItem -LiteralPath $_.FullName -File | ForEach-Object { Remote-Upload $_.FullName "$remoteRoot/$rel" }
  }
}

"== SUBIDA ==  GO=$GO"
"-- secreto (fuera del docroot) --"
Remote-Mkdir "/" "panel-secret"
$cfg = Join-Path $APP "panel-secret\config.php"
if (Test-Path $cfg) { Remote-Upload $cfg $SECRET_REMOTE } else { "  (config.php no existe aún; ejecuta provision.ps1 primero)" }

"-- público (docroot) --"
Upload-Tree (Join-Path $APP "public") $DOCROOT_REMOTE

if (-not $GO) { "`n[Modo comprobación] No se ha subido nada. Pon `$GO = `$true para subir de verdad." }
else { "`nSUBIDA COMPLETA. Verifica en https://panel.conexiatec.com/" }
