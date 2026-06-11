-- ============================================================
-- Acuatica Beluga — fix_mensualidad.sql
-- Corrige alumnos.mensualidad: aplica el descuento una vez
-- para que el campo refleje lo que el alumno realmente paga.
-- Ejecutar ANTES de seed_cobros.sql.
-- ============================================================
SET NAMES utf8mb4;

-- Verificar cuántos alumnos se van a actualizar y los montos
SELECT
    folio,
    nombre,
    mensualidad        AS mensualidad_actual,
    descuento_pct,
    ROUND(mensualidad * (1 - descuento_pct / 100), 2) AS mensualidad_correcta
FROM alumnos
WHERE descuento_pct IS NOT NULL
  AND descuento_pct > 0
  AND status = 'activo'
ORDER BY folio;

-- Aplicar la corrección
UPDATE alumnos
SET mensualidad = ROUND(mensualidad * (1 - descuento_pct / 100), 2)
WHERE descuento_pct IS NOT NULL
  AND descuento_pct > 0;

-- Confirmar resultado
SELECT COUNT(*) AS alumnos_actualizados
FROM alumnos
WHERE descuento_pct IS NOT NULL AND descuento_pct > 0;
