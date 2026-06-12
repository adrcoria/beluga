-- ============================================================
-- Migración 011 — Control de Asistencia (simple)
-- Registra si el alumno se presentó en una fecha.
-- Un registro por alumno por día (UNIQUE).
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS asistencias (
    id             CHAR(36)    NOT NULL,
    alumno_folio   VARCHAR(10) NOT NULL,
    fecha          DATE        NOT NULL,
    hora           TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sucursal_id    CHAR(36)    NULL,
    registrado_por CHAR(36)    NULL,
    metodo         ENUM('qr','manual') NOT NULL DEFAULT 'qr',
    PRIMARY KEY (id),
    UNIQUE KEY uk_asistencia (alumno_folio, fecha),
    KEY idx_asis_fecha    (fecha),
    KEY idx_asis_sucursal (sucursal_id),
    CONSTRAINT fk_asis_alumno FOREIGN KEY (alumno_folio) REFERENCES alumnos (folio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
