-- ============================================================
-- Migración 008 — Disponibilidad del instructor
-- Rango horario general (igual todos los días) por instructor.
-- NULL = sin restricción (disponible en toda la cuadrícula).
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE instructores
    ADD COLUMN disp_hora_inicio TIME NULL AFTER activo,
    ADD COLUMN disp_hora_fin    TIME NULL AFTER disp_hora_inicio;
