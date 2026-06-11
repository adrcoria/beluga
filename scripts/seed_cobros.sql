-- ============================================================
-- Acuatica Beluga — seed_cobros.sql
-- Genera cobros iniciales a partir de los datos del Excel.
-- Ejecutar UNA SOLA VEZ después de import_data.sql e import_fix.sql.
--
-- Lógica:
--   - Para cada alumno activo con deuda, genera UN cobro por
--     cada mes vencido desde (ultimo_pago + 1 mes) hasta hoy,
--     usando una tabla de offsets 0-23 (cubre hasta 2 años de mora).
--   - Los alumnos al corriente (siguiente vencimiento > hoy) no
--     generan ningún cobro aquí; el evento MySQL los atenderá.
--   - Alumnos sin ultimo_pago (nunca pagaron) usan fecha_inicio
--     como punto de partida.
--   - El saldo_favor solo se aplica al primer cobro (el más antiguo).
--   - Inscripciones sin pagar generan su propio cobro.
-- ============================================================
SET NAMES utf8mb4;

-- ── 1. Cobros de mensualidad — un cobro por cada mes vencido ──────
INSERT INTO cobros (
    id,
    alumno_folio,
    concepto,
    monto_base,
    saldo_favor_aplicado,
    monto_neto,
    monto_pagado,
    estado,
    periodo_desde,
    periodo_hasta
)
SELECT
    UUID(),
    a.folio,
    'mensualidad',

    -- mensualidad ya tiene el descuento incorporado (ver fix_mensualidad.sql)
    a.mensualidad AS monto_base,

    -- Saldo a favor: solo al primer cobro (offset 0), resto = 0
    CASE WHEN n.offset_meses = 0
         THEN LEAST(a.saldo_favor, a.mensualidad)
         ELSE 0
    END AS saldo_favor_aplicado,

    -- Monto neto
    CASE WHEN n.offset_meses = 0
         THEN GREATEST(0, a.mensualidad - a.saldo_favor)
         ELSE a.mensualidad
    END AS monto_neto,

    0 AS monto_pagado,

    -- Estado
    CASE
        WHEN n.offset_meses = 0
         AND a.saldo_favor >= a.mensualidad
        THEN 'pagado'
        ELSE 'pendiente'
    END AS estado,

    -- periodo_desde: punto de partida + N meses
    -- Usa ultimo_pago como referencia directa; si nunca pagó, usa fecha_inicio
    DATE_ADD(
        COALESCE(a.ultimo_pago, DATE_SUB(a.fecha_inicio, INTERVAL 0 DAY)),
        INTERVAL (1 + n.offset_meses) MONTH
    ) AS periodo_desde,

    DATE_ADD(
        COALESCE(a.ultimo_pago, DATE_SUB(a.fecha_inicio, INTERVAL 0 DAY)),
        INTERVAL (2 + n.offset_meses) MONTH
    ) AS periodo_hasta

FROM alumnos a

-- Tabla de offsets: cubre hasta 24 meses de mora
JOIN (
    SELECT 0 AS offset_meses UNION ALL SELECT 1  UNION ALL SELECT 2  UNION ALL
    SELECT 3  UNION ALL SELECT 4  UNION ALL SELECT 5  UNION ALL SELECT 6  UNION ALL
    SELECT 7  UNION ALL SELECT 8  UNION ALL SELECT 9  UNION ALL SELECT 10 UNION ALL
    SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL
    SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL
    SELECT 19 UNION ALL SELECT 20 UNION ALL SELECT 21 UNION ALL SELECT 22 UNION ALL
    SELECT 23
) n

WHERE a.status = 'activo'

  -- El período calculado ya venció (es pasado o es hoy)
  AND DATE_ADD(
        COALESCE(a.ultimo_pago, DATE_SUB(a.fecha_inicio, INTERVAL 0 DAY)),
        INTERVAL (1 + n.offset_meses) MONTH
      ) <= CURDATE()

  -- No existe ya un cobro de mensualidad para ese período exacto
  AND NOT EXISTS (
      SELECT 1 FROM cobros c
      WHERE c.alumno_folio = a.folio
        AND c.concepto = 'mensualidad'
        AND c.periodo_desde = DATE_ADD(
              COALESCE(a.ultimo_pago, DATE_SUB(a.fecha_inicio, INTERVAL 0 DAY)),
              INTERVAL (1 + n.offset_meses) MONTH
            )
  );

-- ── 2. Cobros de inscripción pendientes ───────────────────────────
INSERT INTO cobros (
    id,
    alumno_folio,
    concepto,
    monto_base,
    saldo_favor_aplicado,
    monto_neto,
    monto_pagado,
    estado,
    periodo_desde,
    periodo_hasta
)
SELECT
    UUID(),
    a.folio,
    'inscripcion',
    300.00,
    0.00,
    300.00,
    0.00,
    'pendiente',
    a.fecha_inicio,
    a.fecha_inicio
FROM alumnos a
WHERE a.inscripcion_pagada = 0
  AND a.status = 'activo'
  AND NOT EXISTS (
      SELECT 1 FROM cobros c
      WHERE c.alumno_folio = a.folio
        AND c.concepto = 'inscripcion'
  );

-- ── Resumen ───────────────────────────────────────────────────────
SELECT
    concepto,
    estado,
    COUNT(*)                    AS cobros,
    SUM(monto_neto - monto_pagado) AS por_cobrar_MXN
FROM cobros
GROUP BY concepto, estado
ORDER BY concepto, estado;
