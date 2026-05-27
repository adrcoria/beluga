-- ============================================================
-- Migración 002 — Simplificación de roles
-- Ejecutar una sola vez sobre la BD existente
-- ============================================================

-- 1. Actualizar roles existentes al nuevo esquema
UPDATE usuarios SET rol = 'administrador' WHERE rol IN ('super_admin');
UPDATE usuarios SET rol = 'operativo'     WHERE rol IN ('caja', 'coordinacion', 'secretaria');
-- gerencia queda igual

-- 2. Cambiar el ENUM de la columna
ALTER TABLE usuarios
  MODIFY COLUMN rol ENUM('administrador','gerencia','operativo') NOT NULL;
