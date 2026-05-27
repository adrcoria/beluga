-- ============================================================
-- Acuática Beluga — Schema MySQL 5.7+ compatible
-- ============================================================
SET NAMES utf8mb4;
SET time_zone = '-06:00';

-- ── Sucursales ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sucursales (
    id        CHAR(36)      NOT NULL,
    nombre    VARCHAR(120)  NOT NULL,
    direccion VARCHAR(255)  NOT NULL DEFAULT '',
    activa    TINYINT(1)    NOT NULL DEFAULT 1,
    creado_en TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Usuarios del sistema ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
    id            CHAR(36)      NOT NULL,
    nombre        VARCHAR(120)  NOT NULL,
    email         VARCHAR(160)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    rol           ENUM('super_admin','gerencia','caja','coordinacion','secretaria') NOT NULL,
    sucursales    JSON          NOT NULL,
    activo        TINYINT(1)    NOT NULL DEFAULT 1,
    creado_en     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Instructores ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS instructores (
    id          CHAR(36)     NOT NULL,
    nombre      VARCHAR(120) NOT NULL,
    iniciales   VARCHAR(10)  NOT NULL,
    telefono    VARCHAR(20)  NOT NULL DEFAULT '',
    email       VARCHAR(160) NULL,
    niveles     JSON         NOT NULL,
    sucursales  JSON         NOT NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Catálogo de planes ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS planes_catalogo (
    id             CHAR(36)                          NOT NULL,
    modalidad      ENUM('PART','GRUPAL','MATRO')     NOT NULL,
    clases_semana  TINYINT UNSIGNED                  NOT NULL,
    mensualidad    DECIMAL(8,2)                      NOT NULL,
    label          VARCHAR(80)                       NOT NULL,
    activo         TINYINT(1)                        NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_plan (modalidad, clases_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Alumnos ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS alumnos (
    folio               VARCHAR(10)                      NOT NULL,
    nombre              VARCHAR(160)                     NOT NULL,
    fecha_nacimiento    DATE                             NULL,
    foto                VARCHAR(500)                     NULL,
    tutor               VARCHAR(160)                     NULL,
    telefono            VARCHAR(20)                      NOT NULL DEFAULT '',
    email               VARCHAR(160)                     NULL,
    modalidad           ENUM('PART','GRUPAL','MATRO')    NOT NULL,
    clases_semana       TINYINT UNSIGNED                 NOT NULL DEFAULT 1,
    mensualidad         DECIMAL(8,2)                     NOT NULL,
    fecha_inicio        DATE                             NOT NULL,
    dia_pago            TINYINT UNSIGNED                 NOT NULL,
    ultimo_pago         DATE                             NULL,
    vencimiento         DATE                             NOT NULL,
    inscripcion_pagada  TINYINT(1)                       NOT NULL DEFAULT 0,
    descuento_pct       DECIMAL(5,2)                     NULL,
    descuento_motivo    VARCHAR(120)                     NULL,
    disp_dias           JSON                             NOT NULL,
    disp_hora_inicio    TIME                             NULL,
    disp_hora_fin       TIME                             NULL,
    status              ENUM('activo','baja','prueba')   NOT NULL DEFAULT 'activo',
    sucursal_id         CHAR(36)                         NOT NULL,
    creado_en           TIMESTAMP                        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en      TIMESTAMP                        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (folio),
    KEY idx_alumnos_status      (status),
    KEY idx_alumnos_modalidad   (modalidad),
    KEY idx_alumnos_vencimiento (vencimiento),
    KEY idx_alumnos_sucursal    (sucursal_id),
    KEY idx_alumnos_nombre      (nombre),
    CONSTRAINT fk_alumnos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Contador de folios ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS folio_seq (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservado TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Slots (posiciones en el calendario) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS slots (
    id            CHAR(36)                       NOT NULL,
    sucursal_id   CHAR(36)                       NOT NULL,
    dia           ENUM('L','M','X','J','V','S')  NOT NULL,
    hora_inicio   TIME                           NOT NULL,
    hora_fin      TIME                           NOT NULL,
    instructor_id CHAR(36)                       NOT NULL,
    modalidad     ENUM('PART','GRUPAL','MATRO')  NOT NULL,
    cupo_max      TINYINT UNSIGNED               NOT NULL DEFAULT 1,
    activo        TINYINT(1)                     NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_slots_sucursal   (sucursal_id),
    KEY idx_slots_dia        (dia),
    KEY idx_slots_instructor (instructor_id),
    KEY idx_slots_modalidad  (modalidad),
    CONSTRAINT fk_slots_sucursal   FOREIGN KEY (sucursal_id)   REFERENCES sucursales   (id),
    CONSTRAINT fk_slots_instructor FOREIGN KEY (instructor_id) REFERENCES instructores (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Asignaciones ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS asignaciones (
    id                      CHAR(36)    NOT NULL,
    alumno_folio            VARCHAR(10) NOT NULL,
    slot_id                 CHAR(36)    NOT NULL,
    fecha                   DATE        NOT NULL,
    estado                  ENUM('regular','reposicion','no_asiste','toca_pago','prueba','mensaje') NOT NULL DEFAULT 'regular',
    nota                    TEXT        NULL,
    generada_por_inhabil_id CHAR(36)    NULL,
    registrada_en           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_asignacion (slot_id, fecha, alumno_folio),
    KEY idx_asig_alumno (alumno_folio),
    KEY idx_asig_fecha  (fecha),
    KEY idx_asig_estado (estado),
    CONSTRAINT fk_asig_alumno FOREIGN KEY (alumno_folio) REFERENCES alumnos (folio),
    CONSTRAINT fk_asig_slot   FOREIGN KEY (slot_id)      REFERENCES slots   (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Pagos ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pagos (
    id           CHAR(36)     NOT NULL,
    alumno_folio VARCHAR(10)  NOT NULL,
    concepto     ENUM('mensualidad','inscripcion','reposicion_extra','otro') NOT NULL,
    monto        DECIMAL(8,2) NOT NULL,
    metodo       ENUM('efectivo','transferencia','debito','credito','oxxo') NOT NULL,
    fecha        DATE         NOT NULL,
    ticket       VARCHAR(40)  NOT NULL,
    ciclo_desde  DATE         NULL,
    ciclo_hasta  DATE         NULL,
    usuario_id   CHAR(36)     NOT NULL,
    sucursal_id  CHAR(36)     NOT NULL,
    creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ticket       (ticket),
    KEY idx_pagos_alumno   (alumno_folio),
    KEY idx_pagos_fecha    (fecha),
    KEY idx_pagos_concepto (concepto),
    CONSTRAINT fk_pagos_alumno   FOREIGN KEY (alumno_folio) REFERENCES alumnos    (folio),
    CONSTRAINT fk_pagos_usuario  FOREIGN KEY (usuario_id)   REFERENCES usuarios   (id),
    CONSTRAINT fk_pagos_sucursal FOREIGN KEY (sucursal_id)  REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Días inhábiles ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS inhabiles (
    id                     CHAR(36)     NOT NULL,
    fecha                  DATE         NOT NULL,
    motivo                 VARCHAR(255) NOT NULL,
    sucursal_id            CHAR(36)     NULL,
    estado                 ENUM('pendiente','aplicado') NOT NULL DEFAULT 'pendiente',
    alumnos_afectados      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    reposiciones_generadas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    declarado_por          CHAR(36)     NOT NULL,
    declarado_en           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_inhabiles_fecha (fecha),
    CONSTRAINT fk_inhabiles_sucursal FOREIGN KEY (sucursal_id)  REFERENCES sucursales (id),
    CONSTRAINT fk_inhabiles_usuario  FOREIGN KEY (declarado_por) REFERENCES usuarios  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Bitácora ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bitacora (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id CHAR(36)        NOT NULL,
    accion     VARCHAR(120)    NOT NULL,
    entidad    VARCHAR(60)     NULL,
    entidad_id VARCHAR(40)     NULL,
    detalle    JSON            NULL,
    ip         VARCHAR(45)     NULL,
    creado_en  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bitacora_usuario (usuario_id),
    KEY idx_bitacora_fecha   (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Datos iniciales
-- ============================================================

INSERT IGNORE INTO sucursales (id, nombre, direccion) VALUES
('00000000-0000-0000-0000-000000000001', 'Beluga Principal', 'Morelia, Michoacán');

INSERT IGNORE INTO planes_catalogo (id, modalidad, clases_semana, mensualidad, label) VALUES
('plan-part-1', 'PART',   1, 650.00,  'PART · 1 clase/sem'),
('plan-part-2', 'PART',   2, 860.00,  'PART · 2 clases/sem'),
('plan-part-3', 'PART',   3, 1150.00, 'PART · 3 clases/sem'),
('plan-part-5', 'PART',   5, 2000.00, 'PART · 5 clases/sem'),
('plan-grp-1',  'GRUPAL', 1, 510.00,  'GRUPAL · 1 clase/sem'),
('plan-grp-2',  'GRUPAL', 2, 731.00,  'GRUPAL · 2 clases/sem'),
('plan-mat-1',  'MATRO',  1, 750.00,  'MATRO · 1 clase/sem'),
('plan-mat-2',  'MATRO',  2, 1100.00, 'MATRO · 2 clases/sem');

INSERT IGNORE INTO instructores (id, nombre, iniciales, niveles, sucursales) VALUES
('inst-brisa',   'Brisa',   'BR', '["infantil 1","infantil 2","jovenes"]', '["00000000-0000-0000-0000-000000000001"]'),
('inst-karla',   'Karla',   'KR', '["bebes","infantil 1","matro"]',        '["00000000-0000-0000-0000-000000000001"]'),
('inst-ram',     'Ram',     'RM', '["adultos","jovenes","competencia"]',    '["00000000-0000-0000-0000-000000000001"]'),
('inst-chio',    'Chío',    'CH', '["bebes","infantil 1","matro"]',         '["00000000-0000-0000-0000-000000000001"]'),
('inst-maru',    'Maru',    'MR', '["infantil 2","jovenes","adultos"]',     '["00000000-0000-0000-0000-000000000001"]'),
('inst-edgardo', 'Edgardo', 'ED', '["jovenes","adultos","competencia"]',    '["00000000-0000-0000-0000-000000000001"]'),
('inst-jacob',   'Jacob',   'JC', '["infantil 1","infantil 2","jovenes"]',  '["00000000-0000-0000-0000-000000000001"]');

-- Super admin  (password: Beluga2025!)
INSERT IGNORE INTO usuarios (id, nombre, email, password_hash, rol, sucursales) VALUES
('usr-admin-01', 'Administrador', 'admin@acuaticabeluga.com',
 '$2y$12$u0VsdC2Z8IozI0sDqG8hHeT6c4525RYJB8WUfgMufCt78AzE.bP5S',
 'super_admin', '["*"]');
