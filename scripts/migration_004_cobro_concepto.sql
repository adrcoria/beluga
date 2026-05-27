-- ============================================================
-- Migración 004 — Agregar concepto a cobros
-- ============================================================

ALTER TABLE cobros
    ADD COLUMN concepto ENUM('mensualidad','inscripcion') NOT NULL DEFAULT 'mensualidad'
    AFTER alumno_folio;
