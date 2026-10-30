-- ============================================================
-- Migración 021 — Asistencia por clase, con estado explícito
--
-- Hasta ahora `asistencias` solo sabía registrar PRESENCIAS: un renglón
-- por alumno por día, y la ausencia se deducía de que no hubiera renglón.
-- Eso no alcanza para:
--   · marcar explícitamente "no asistió"
--   · distinguir a cuál clase faltó quien tiene dos el mismo día
--     (su clase normal y una reposición)
--
-- Ahora cada renglón es UNA CLASE de un alumno en una fecha, con estado.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

-- ── 1. La clase a la que corresponde el registro ────────────────────────────
-- hora_clase es la llave natural de la clase dentro del día. Se guarda el
-- valor, no solo el id de la regla, para que el histórico sobreviva a que
-- después borren o muevan el horario.
ALTER TABLE asistencias
    ADD COLUMN hora_clase    TIME     NOT NULL DEFAULT '00:00:00' AFTER fecha,
    ADD COLUMN horario_id    CHAR(36) NULL AFTER hora_clase,
    ADD COLUMN reposicion_id CHAR(36) NULL AFTER horario_id;

-- ── 2. Estado explícito ─────────────────────────────────────────────────────
ALTER TABLE asistencias
    ADD COLUMN estado ENUM('asistio','no_asistio') NOT NULL DEFAULT 'asistio' AFTER reposicion_id;

-- 'auto' = lo puso el cierre del día, no una persona
ALTER TABLE asistencias
    MODIFY COLUMN metodo ENUM('qr','manual','auto') NOT NULL DEFAULT 'qr';

-- ── 3. Un registro por alumno POR CLASE, ya no por día ──────────────────────
-- La llave foránea hacia alumnos se apoya en la primera columna de
-- uk_asistencia, así que no se puede borrar mientras sea el único índice que
-- empieza por alumno_folio. Primero se le da otro donde apoyarse.
ALTER TABLE asistencias ADD KEY idx_asis_alumno (alumno_folio);
ALTER TABLE asistencias DROP INDEX uk_asistencia;
ALTER TABLE asistencias ADD UNIQUE KEY uk_asistencia (alumno_folio, fecha, hora_clase);

ALTER TABLE asistencias ADD KEY idx_asis_estado (fecha, estado);

-- ── 4. Días ya cerrados ─────────────────────────────────────────────────────
-- El cierre es perezoso: ocurre la primera vez que alguien consulta un día
-- que ya pasó. Esta tabla evita volver a cerrarlo y deja constancia de
-- cuándo se congeló, que es lo que vuelve auditable el "no asistió"
-- automático.
CREATE TABLE IF NOT EXISTS asistencia_cierres (
    sucursal_id CHAR(36)  NOT NULL,
    fecha       DATE      NOT NULL,
    cerrado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    clases      INT       NOT NULL DEFAULT 0,
    asistieron  INT       NOT NULL DEFAULT 0,
    faltaron    INT       NOT NULL DEFAULT 0,
    PRIMARY KEY (sucursal_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. Ventana para resolver a qué clase pertenece un escaneo ───────────────
-- El alumno escanea al llegar; el sistema le asigna la clase más cercana en
-- el tiempo dentro de esta ventana (minutos antes o después de su hora).
INSERT INTO configuracion (clave, valor)
VALUES ('asistencia_ventana_min', '90')
ON DUPLICATE KEY UPDATE valor = valor;
