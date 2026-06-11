-- ============================================================
-- Acuatica Beluga — fix_cobros_montos.sql
-- Recalcula monto_base y monto_neto en cobros pendientes/parciales
-- usando la mensualidad ya corregida de la tabla alumnos.
-- Ejecutar DESPUÉS de fix_mensualidad.sql.
-- ============================================================
SET NAMES utf8mb4;

-- Ver cuántos cobros se van a corregir y los montos antes/después
SELECT
    c.id,
    a.folio,
    a.nombre,
    c.monto_base       AS monto_base_actual,
    a.mensualidad      AS monto_base_correcto,
    c.monto_neto       AS monto_neto_actual,
    GREATEST(0, a.mensualidad - c.saldo_favor_aplicado) AS monto_neto_correcto
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
WHERE c.concepto = 'mensualidad'
  AND c.estado IN ('pendiente', 'parcial')
  AND c.monto_base <> a.mensualidad;

-- Aplicar corrección
UPDATE cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
SET
    c.monto_base = a.mensualidad,
    c.monto_neto = GREATEST(0, a.mensualidad - c.saldo_favor_aplicado)
WHERE c.concepto = 'mensualidad'
  AND c.estado IN ('pendiente', 'parcial');

-- Confirmar
SELECT COUNT(*) AS cobros_corregidos
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
WHERE c.concepto = 'mensualidad'
  AND c.estado IN ('pendiente', 'parcial')
  AND c.monto_base = a.mensualidad;
