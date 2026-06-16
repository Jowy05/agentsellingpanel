# Panel de minutos · Agente IA — App de producción (Conexia)

App web para el equipo técnico: revende el Agente de Voz IA por minutos, mide consumo
(CDR), avisa al 75 %/100 % y al 100 % desvía el DID del agente a un IVR de respaldo.

**Stack:** PHP 8 + MySQL + frontend React (precompilado). Vive en el **cPanel de Conexia**,
en el subdominio dedicado **agentsellingpanel.conexiatec.com**. Los técnicos entran a la web → login
+ 2FA → operan. La clave del PBX vive **solo en el servidor**, nunca en el navegador.

## Estructura (y a dónde va en el cPanel)

```
Panel-Minutos-App/
├─ panel-secret/            -> /home/conexiatec/panel-secret/      (FUERA del docroot)
│   ├─ config.sample.php        plantilla; copiar a config.php y rellenar secretos
│   └─ config.php               (NO en git) clave PBX, credenciales BD
├─ public/                  -> /home/conexiatec/agentsellingpanel.conexiatec.com/   (docroot del subdominio)
│   ├─ .htaccess                cabeceras de seguridad, HTTPS, bloqueos
│   ├─ index.html               panel (SPA) — frontend precompilado
│   ├─ assets/                  logo, etc.
│   └─ api/                     backend PHP (lo único que habla con el PBX)
│       ├─ _bootstrap.php       config + PDO + sesión + cabeceras + helpers
│       ├─ schema.sql           esquema MySQL
│       ├─ lib/totp.php         2FA TOTP (RFC 6238)
│       ├─ lib/pbx.php          cliente PBXware (lectura + did.edit)
│       └─ *.php                 endpoints (login, 2fa, clientes, métricas, desvío)
└─ deploy/                  scripts de despliegue (UAPI cPanel) — NO se suben
```

## Seguridad / aislamiento (CRÍTICO)
- En el cPanel hay **otras apps y la web (WordPress en /public_html)**: **NO se toca nada**
  que no sea de esta app. Solo se crea el subdominio nuevo + una BD nueva + se sube a su docroot.
- La clave del PBX y las credenciales de BD viven en `panel-secret/config.php`, **fuera del
  docroot** (no accesible por web). `config.php` nunca se sube a git.
- Mínimo privilegio: el backend solo usa de la API `cdr.download`, `ext.list`, `did.list` y
  `did.edit`; el desvío se hace **entre IVRs por número** (sin UUID).

## Despliegue (resumen)
1. Crear subdominio `agentsellingpanel.conexiatec.com` (docroot `/home/conexiatec/agentsellingpanel.conexiatec.com`).
2. Crear BD `conexiatec_panel` + usuario + privilegios; importar `schema.sql`.
3. Subir `panel-secret/` a `/home/conexiatec/panel-secret/` y rellenar `config.php`.
4. Subir `public/` al docroot.
5. Verificar login + 2FA + ping al PBX (server-side).

## Estado / hitos
- **M1 (en curso):** esqueleto seguro — login + 2FA, BD de usuarios, ping al PBX server-side.
- **M2:** clientes (CRUD en BD) + lectura de minutos (CDR) + reglas 75/100 %.
- **M3:** avisos (n8n) + desvío automático por IVR (`did.edit`) con guardado/restauración.
- **M4:** gestión de cuentas del equipo, auditoría, pulido y paso a clientes reales.
