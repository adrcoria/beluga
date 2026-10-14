-- ============================================================
-- Migración 013 — Multimodalidad (programas por alumno)
-- ADITIVA: crea la tabla de programas y enlaza cobros/horarios.
-- NO elimina columnas de `alumnos` todavía (eso será al final,
-- cuando el código nuevo ya lea desde alumno_programas).
-- Cada alumno actual genera 1 programa con su plan vigente.
-- ============================================================

-- 1. Programas: una inscripción a una modalidad (con su propia fecha de pago)
CREATE TABLE IF NOT EXISTS alumno_programas (
    id               CHAR(36)              NOT NULL,
    alumno_folio     VARCHAR(10)           NOT NULL,
    modalidad        VARCHAR(20)           NOT NULL,
    clases_semana    TINYINT UNSIGNED      NOT NULL DEFAULT 1,
    mensualidad      DECIMAL(8,2)          NOT NULL,
    descuento_pct    DECIMAL(5,2)          NULL,
    descuento_motivo VARCHAR(120)          NULL,
    fecha_inicio     DATE                  NOT NULL,
    dia_pago         TINYINT UNSIGNED      NOT NULL,
    ultimo_pago      DATE                  NULL,
    vencimiento      DATE                  NOT NULL,
    status           ENUM('activo','baja') NOT NULL DEFAULT 'activo',
    creado_en        TIMESTAMP             NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_prog_alumno    (alumno_folio),
    KEY idx_prog_venc      (vencimiento),
    KEY idx_prog_modalidad (modalidad),
    KEY idx_prog_status    (status),
    CONSTRAINT fk_prog_alumno FOREIGN KEY (alumno_folio)
        REFERENCES alumnos (folio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Migrar el plan actual de cada alumno a un programa
INSERT INTO alumno_programas
    (id, alumno_folio, modalidad, clases_semana, mensualidad,
     descuento_pct, descuento_motivo, fecha_inicio, dia_pago,
     ultimo_pago, vencimiento, status)
SELECT UUID(), a.folio, a.modalidad, a.clases_semana, a.mensualidad,
       a.descuento_pct, a.descuento_motivo, a.fecha_inicio, a.dia_pago,
       a.ultimo_pago, a.vencimiento,
       CASE WHEN a.status = 'baja' THEN 'baja' ELSE 'activo' END
FROM alumnos a
WHERE NOT EXISTS (SELECT 1 FROM alumno_programas p WHERE p.alumno_folio = a.folio);

-- 3. Enlazar cobros existentes a su programa (1:1 tras la migración)
ALTER TABLE cobros ADD COLUMN programa_id CHAR(36) NULL AFTER alumno_folio;

UPDATE cobros c
JOIN alumno_programas p ON p.alumno_folio = c.alumno_folio
SET c.programa_id = p.id
WHERE c.programa_id IS NULL;

-- 4. Enlazar asignaciones individuales del calendario a su programa
--    (las clases de grupo usan grupo_id y no llevan programa_id)
ALTER TABLE horario_recurrente ADD COLUMN programa_id CHAR(36) NULL AFTER alumno_folio;

UPDATE horario_recurrente hr
JOIN alumno_programas p
  ON p.alumno_folio = hr.alumno_folio
 AND p.modalidad    = hr.modalidad
SET hr.programa_id = p.id
WHERE hr.alumno_folio IS NOT NULL
  AND hr.programa_id IS NULL;
