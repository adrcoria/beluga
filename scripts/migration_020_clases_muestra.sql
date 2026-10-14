-- ============================================================
-- Migración 020 — Clases muestra para alumnos en prueba
--
-- El cliente quiere agendar clases de prueba en el pizarrón, pero NO
-- quiere el buzón de prospectos: ese es el módulo 11 (Preinscripción QR),
-- que es premium y se cotiza aparte.
--
-- Por eso se reutiliza lo que ya existe: `alumnos.status = 'prueba'`.
-- Un prealumno es un alumno en prueba, sin programa de pago, con sus
-- notas. Así el pizarrón, el calendario y los filtros funcionan tal cual,
-- sin tocar llaves foráneas ni duplicar el catálogo de personas.
--
-- Lo único que falta es dónde anotar de dónde salió y qué se le dijo.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE alumnos
    -- Notas del prospecto: de dónde llegó, qué se le ofreció, seguimiento
    ADD COLUMN notas VARCHAR(500) NULL AFTER email,
    -- Cuándo se le dio la clase muestra, para el reporte de seguimiento
    ADD COLUMN fecha_clase_muestra DATE NULL AFTER notas;

-- Una clase muestra ocupa una celda del pizarrón una sola fecha, igual que
-- una reposición. Se marca en la regla para pintarla distinto y para que no
-- se confunda con una clase de un alumno de paga.
ALTER TABLE horario_recurrente
    ADD COLUMN es_muestra TINYINT(1) NOT NULL DEFAULT 0 AFTER modalidad;

ALTER TABLE alumnos ADD KEY idx_alumnos_status (status);
