-- ============================================================
-- Migración 023 — Retardo máximo y piso del control de asistencia
--
-- Dos ajustes, ambos solo de parámetros (no toca estructura ni datos):
--
-- 1. La tolerancia se mide desde que EMPIEZA la clase, no desde que
--    termina. Las clases duran 30 minutos: llegar 20 minutos tarde
--    todavía cuenta, llegar después ya no.
--
-- 2. `asistencia_desde` es el piso del cierre automático. Sin él, abrir
--    el reporte de un mes anterior cerraría esos días y marcaría como
--    faltistas a todos los alumnos, inventando historia que nunca se
--    registró.
-- ============================================================
SET NAMES utf8mb4;

-- Hasta cuántos minutos después de que empieza la clase sigue contando
INSERT INTO configuracion (clave, valor)
VALUES ('asistencia_retardo_max', '20')
ON DUPLICATE KEY UPDATE valor = valor;

-- Desde cuándo la escuela lleva control de asistencia
INSERT INTO configuracion (clave, valor)
VALUES ('asistencia_desde', '2026-11-01')
ON DUPLICATE KEY UPDATE valor = valor;

-- Reemplazado por asistencia_retardo_max
DELETE FROM configuracion WHERE clave = 'asistencia_min_despues';
