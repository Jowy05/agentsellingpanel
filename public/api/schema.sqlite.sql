-- Esquema SQLite (SOLO para ejecución LOCAL). En producción se usa schema.sql (MySQL).
PRAGMA foreign_keys=ON;

CREATE TABLE IF NOT EXISTS usuarios (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  email           TEXT NOT NULL UNIQUE,
  nombre          TEXT NOT NULL,
  pass_hash       TEXT NOT NULL,
  rol             TEXT NOT NULL DEFAULT 'tecnico',
  totp_secret     TEXT DEFAULT NULL,
  totp_enabled    INTEGER NOT NULL DEFAULT 0,
  estado          TEXT NOT NULL DEFAULT 'activo',
  intentos        INTEGER NOT NULL DEFAULT 0,
  bloqueado_hasta TEXT DEFAULT NULL,
  creado          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login    TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS codigos_recuperacion (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER NOT NULL,
  code_hash  TEXT NOT NULL,
  usado      INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS clientes (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  slug                TEXT NOT NULL UNIQUE,
  nombre              TEXT NOT NULL,
  correo              TEXT,
  sector              TEXT,
  plan                TEXT,
  minutos_contratados INTEGER NOT NULL DEFAULT 0,
  alta                TEXT,
  tenant              TEXT,
  ddi                 TEXT,
  desvio_100          TEXT,
  did_dest_backup     TEXT,
  estado_desvio       TEXT NOT NULL DEFAULT 'normal',
  creado              TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS agentes (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  cliente_id      INTEGER NOT NULL,
  uuid            TEXT,
  nombre          TEXT NOT NULL,
  dial_number     TEXT,
  ddi             TEXT,
  ivr_corte       TEXT,
  did_dest_backup TEXT,
  estado_desvio   TEXT NOT NULL DEFAULT 'normal',
  creado          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (cliente_id, ddi),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
);

-- Consumo POR AGENTE y periodo (AAAA-MM). El total del cliente = suma de sus agentes.
CREATE TABLE IF NOT EXISTS consumo (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  agente_id      INTEGER NOT NULL,
  periodo        TEXT NOT NULL,
  minutos_usados INTEGER NOT NULL DEFAULT 0,
  base_mes       INTEGER NOT NULL DEFAULT 0,   -- minutos del mes hasta AYER (la medición rápida solo recalcula hoy)
  actualizado    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (agente_id, periodo),
  FOREIGN KEY (agente_id) REFERENCES agentes(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS auditoria (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  usuario_id INTEGER DEFAULT NULL,
  accion     TEXT NOT NULL,
  detalle    TEXT,
  ip         TEXT,
  cuando     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Avisos de consumo ya enviados (una sola vez por cliente/periodo/nivel: 75 o 100).
CREATE TABLE IF NOT EXISTS avisos_email (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  cliente_id INTEGER NOT NULL,
  periodo    TEXT NOT NULL,
  nivel      INTEGER NOT NULL,
  enviado    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (cliente_id, periodo, nivel),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
);
