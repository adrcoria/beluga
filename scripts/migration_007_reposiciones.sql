-- ============================================================
-- Migración 007 — Reposiciones (comodines) — Fase 2
-- Un comodín = crédito de reposición de un alumno.
--   estado 'pendiente'  → disponible, sin agendar
--   estado 'agendada'   → colocado en una celda + fecha concreta
--   estado 'vencida'    → no se usó dentro de la vigencia
--   estado 'cancelada'  → anulado manualmente
-- La reposición agendada es de UNA sola fecha (no recurrente).
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

-- Vigencia configurable del comodín (días)
INSERT IGNORE INTO configuracion (clave, valor) VALUES
    ('reposicion_dias_vigencia', '30');

CREATE TABLE IF NOT EXISTS reposiciones (
    id                      CHAR(36)     NOT NULL,
    alumno_folio            VARCHAR(10)  NOT NULL,
    sucursal_id             CHAR(36)     NOT NULL,
    motivo                  VARCHAR(255) NULL,
    origen                  ENUM('inhabil','manual') NOT NULL DEFAULT 'manual',
    generada_por_inhabil_id CHAR(36)     NULL,
    estado                  ENUM('pendiente','agendada','vencida','cancelada') NOT NULL DEFAULT 'pendiente',
    vence                   DATE         NULL,
    -- Datos de la colocación (cuando estado = 'agendada')
    fecha                   DATE         NULL,
    instructor_id           CHAR(36)     NULL,
    hora_inicio             TIME         NULL,
    duracion_min            SMALLINT UNSIGNED NULL,
    creada_en               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    agendada_en             TIMESTAMP    NULL,
    PRIMARY KEY (id),
    KEY idx_rep_alumno   (alumno_folio),
    KEY idx_rep_estado   (estado),
    KEY idx_rep_fecha    (fecha),
    KEY idx_rep_sucursal (sucursal_id),
    CONSTRAINT fk_rep_alumno     FOREIGN KEY (alumno_folio)  REFERENCES alumnos       (folio) ON DELETE CASCADE,
    CONSTRAINT fk_rep_sucursal   FOREIGN KEY (sucursal_id)   REFERENCES sucursales    (id),
    CONSTRAINT fk_rep_instructor FOREIGN KEY (instructor_id) REFERENCES instructores  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
