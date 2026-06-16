-- Panel de minutos · Agente IA — esquema MySQL (utf8mb4)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL UNIQUE,
  nombre          VARCHAR(120) NOT NULL,
  pass_hash       VARCHAR(255) NOT NULL,
  rol             ENUM('admin','tecnico') NOT NULL DEFAULT 'tecnico',
  totp_secret     VARCHAR(64) DEFAULT NULL,          -- base32; NULL = 2FA no enrolado
  totp_enabled    TINYINT(1) NOT NULL DEFAULT 0,
  estado          ENUM('activo','desactivado') NOT NULL DEFAULT 'activo',
  intentos        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME DEFAULT NULL,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_login    DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS codigos_recuperacion (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,
  usado      TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clientes (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug                VARCHAR(120) NOT NULL UNIQUE,
  nombre              VARCHAR(190) NOT NULL,
  correo              VARCHAR(190) DEFAULT NULL,
  sector              VARCHAR(120) DEFAULT NULL,
  plan                VARCHAR(120) DEFAULT NULL,
  minutos_contratados INT UNSIGNED NOT NULL DEFAULT 0,
  alta                VARCHAR(40) DEFAULT NULL,
  tenant              VARCHAR(40) DEFAULT NULL,       -- server PBX (p.ej. 18)
  ddi                 VARCHAR(60) DEFAULT NULL,       -- DID del agente
  desvio_100          VARCHAR(190) DEFAULT NULL,      -- destino al 100%: IVR o número al que se desvía el DID
  did_dest_backup     VARCHAR(190) DEFAULT NULL,      -- destino original del DID (el agente), guardado antes del desvío para restaurar
  estado_desvio       ENUM('normal','cortado') NOT NULL DEFAULT 'normal',
  creado              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS consumo (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id     INT UNSIGNED NOT NULL,
  periodo        VARCHAR(7) NOT NULL,                 -- AAAA-MM
  minutos_usados INT UNSIGNED NOT NULL DEFAULT 0,
  aviso_75       TINYINT(1) NOT NULL DEFAULT 0,
  aviso_100      TINYINT(1) NOT NULL DEFAULT 0,
  actualizado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cliente_periodo (cliente_id, periodo),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS agentes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id      INT UNSIGNED NOT NULL,
  uuid            VARCHAR(64) DEFAULT NULL,        -- id del agente de voz (destino UUID del DID); NULL si manual
  nombre          VARCHAR(190) NOT NULL,
  dial_number     VARCHAR(40) DEFAULT NULL,        -- número interno del agente (por aquí se mide si no hay DDI público)
  ddi             VARCHAR(60) DEFAULT NULL,        -- número público (opcional); por aquí se mide y se desvía si existe
  ivr_corte       VARCHAR(190) DEFAULT NULL,       -- IVR/destino de corte de ESTE agente; NULL = usa el del cliente
  did_dest_backup VARCHAR(190) DEFAULT NULL,       -- destino original del DID, guardado al cortar
  estado_desvio   ENUM('normal','cortado') NOT NULL DEFAULT 'normal',
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cli_ddi (cliente_id, ddi),
  FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auditoria (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED DEFAULT NULL,
  accion     VARCHAR(80) NOT NULL,
  detalle    TEXT,
  ip         VARCHAR(64),
  cuando     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
