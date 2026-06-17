# Despliegue a cPanel — Panel Agentes Voz IA

Subdominio: **agentsellingpanel.conexiatec.com** · BD: **conexiatec_panel** · docroot: `/home/conexiatec/agentsellingpanel.conexiatec.com/` · secretos: `/home/conexiatec/panel-secret/config.php` (fuera del docroot).

> ⚠️ **El cPanel es compartido** (WordPress + otras apps + ~11 BD `conexiatec_*`). Todo lo de abajo **solo crea/sube recursos NUEVOS de esta app**. No se toca nada existente. Los scripts traen una compuerta `$GO=$false` (modo comprobación) — primero enseñan el plan, no ejecutan.

## 0) Antes de empezar
- Confirmar el correo real de **SAC** para la copia de avisos (en `provision.ps1`, var `$CC`, ahora `sac@conexiatec.com`).
- Tener el `config.php` **local** presente (de él se leen apikey del PBX + webhook/token de n8n ya verificados).

## 1) Provisión (crea subdominio + BD + usuario + `config.prod.php`)
```
# 1a. Comprobar el plan (no crea nada):
powershell -File deploy\provision.ps1
# 1b. Revisar la salida; si OK, editar provision.ps1 -> $GO = $true y volver a ejecutar.
```
Genera `panel-secret/config.prod.php` (mysql + apikey + n8n[webhook,token,cc=SAC] + cron_token + auto_cut_100=true). **No toca** el `config.php` local (sqlite).

## 2) Subida (config + ficheros)
```
# 2a. Comprobar qué subiría (no sube nada):
powershell -File deploy\upload.ps1
# 2b. Si OK, editar upload.ps1 -> $GO = $true y volver a ejecutar.
```
Sube `config.prod.php` **como** `/panel-secret/config.php` y `public/*` al docroot. Nada más.

## 3) Crear el esquema en la BD
Opción A (rápida) — instalador de un solo uso (se **AUTO-BORRA** al terminar):
```
https://agentsellingpanel.conexiatec.com/api/install.php?token=<cron_token de config.prod.php>
```
Aplica `schema.sql` (CREATE IF NOT EXISTS, no borra nada) y se elimina solo tras hacerlo. **Verifica** que `…/api/install.php` ya da **404** (si no, bórralo a mano por File Manager).

Opción B — phpMyAdmin (cPanel) → BD `conexiatec_panel` → Importar → `public/api/schema.sql`.

## 4) Primer admin
```
curl -X POST https://agentsellingpanel.conexiatec.com/api/seed_admin.php \
  -H "Content-Type: application/json" \
  -H "X-Install-Token: <cron_token de config.prod.php>" \
  -d '{"email":"admin@conexiatec.com","nombre":"Admin","pass":"<contraseña-fuerte>"}'
```
Requiere el token (cierra la ventana de toma de control) y solo funciona una vez (si `usuarios` está vacía). Hazlo **justo después** del upload. Luego entra en la web y **enrola el 2FA** (QR) en el primer login.

## 5) Cron del medidor (avisos + auto-corte sin tener el panel abierto)
En cPanel → **Cron Jobs**, cada 10 min (vía web, con token — el módulo Cron de UAPI no está, se usó API2 o la UI):
```
curl -s "https://agentsellingpanel.conexiatec.com/api/cron.php?token=<cron_token>" >/dev/null 2>&1
```
Recalcula el consumo de todos los clientes desde el CDR, manda los avisos 75/100% y aplica el auto-corte. Tiene **lock anti-reentrada**. Cada pasada hace medición **completa** del mes de todos los clientes → con muchos clientes, subir el intervalo (15-30 min) para no saturar el PBX.

> NOTA del docroot: cPanel crea el subdominio con docroot bajo **`/home/conexiatec/public_html/agentsellingpanel.conexiatec.com/`** (no a nivel home). El `config.php` va a **`/home/conexiatec/panel-secret/`** (fuera de TODOS los docroots → no accesible por web); el `_bootstrap.php` lo localiza probando varias profundidades. `upload.ps1` ya sube al docroot correcto.

## 6) Verificación post-deploy
1. Abrir https://agentsellingpanel.conexiatec.com/ → login + 2FA.
2. Crear un cliente (tenant 216), «Leer agentes», «Actualizar consumo».
3. Comprobar que el cron deja registro en la tabla `auditoria`.
4. Probar un aviso (subir a 75%) → revisar que llega al correo del cliente + CC SAC.

## Notas de seguridad
- `config.php`/`config.prod.php` con secretos: **fuera del docroot** y **fuera de git**.
- `api/install.php`: **borrar tras usarlo**.
- `auto_cut_100=true`: el corte al 100% es automático; la **reactivación es manual** (GUI PBXware: Destination → AI Voice Agents → agente). El panel da el enlace directo.
- Rollback: como todo es nuevo y aislado, deshacer = borrar el subdominio + la BD `conexiatec_panel` + `/panel-secret/` (no afecta a nada más).
