-- ============================================================
-- Migración 019 — Ausencias con derecho a reposición
--
-- CAMBIO DE REGLA DE NEGOCIO, autorizado por el cliente (oct-2026).
-- Hasta hoy una ausencia individual NO generaba reposición: el alumno
-- perdía la clase y solo los días inhábiles declarados por la escuela
-- repartían comodines.
--
-- A partir de ahora, si el alumno avisa con suficiente anticipación la
-- falta DA DERECHO a reposición y el comodín se genera solo. Si avisa
-- tarde, la falta se registra igual pero sin derecho: queda para el
-- reporte y para poder reclamar.
--
-- El umbral es configurable (`ausencia_horas_aviso`). `con_derecho` se
-- guarda resuelto y no se recalcula: si mañana se sube el parámetro, las
-- faltas viejas conservan el veredicto con el que se registraron.
--
-- El pizarrón son reglas semanales recurrentes; esta tabla es la que
-- permite marcar algo en una FECHA concreta.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ausencias (
    id             CHAR(36)     NOT NULL,
    alumno_folio   VARCHAR(10)  NOT NULL,
    -- La regla del pizarrón que el alumno no va a cubrir. Se conserva el
    -- id para poder ubicar la celda aunque la regla cambie después.
    horario_id     CHAR(36)     NULL,
    sucursal_id    CHAR(36)     NOT NULL,
    fecha          DATE         NOT NULL,
    hora_clase     TIME         NOT NULL,
    -- Cuánto antes avisó y qué se resolvió con eso
    horas_aviso    DECIMAL(6,2) NOT NULL,
    con_derecho    TINYINT(1)   NOT NULL DEFAULT 0,
    umbral_horas   DECIMAL(6,2) NOT NULL,
    motivo         VARCHAR(255) NULL,
    -- Comodín generado, cuando hubo derecho
    reposicion_id  CHAR(36)     NULL,
    registrado_por CHAR(36)     NULL,
    creado_en      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Una sola ausencia por alumno, fecha y hora de clase
    UNIQUE KEY uk_ausencia (alumno_folio, fecha, hora_clase),
    KEY idx_aus_fecha    (fecha),
    KEY idx_aus_sucursal (sucursal_id),
    KEY idx_aus_derecho  (con_derecho),
    CONSTRAINT fk_aus_alumno   FOREIGN KEY (alumno_folio) REFERENCES alumnos    (folio) ON DELETE CASCADE,
    CONSTRAINT fk_aus_sucursal FOREIGN KEY (sucursal_id)  REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una falta avisada también es un origen de reposición
ALTER TABLE reposiciones
    MODIFY COLUMN origen ENUM('inhabil','manual','ausencia') NOT NULL DEFAULT 'manual';

-- Horas de anticipación con las que una falta da derecho a reposición.
-- El cliente pidió un mínimo de 2.
INSERT IGNORE INTO configuracion (clave, valor) VALUES
    ('ausencia_horas_aviso', '2');
