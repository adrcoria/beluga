-- ============================================================
--  AUDITORÍA DE CONSISTENCIA — Generación de cobros
--  Solo lectura. Ejecuta cada bloque por separado.
--  Compatible con MySQL 5.7 (sin window functions ni CTE).
-- ============================================================


-- ────────────────────────────────────────────────────────────
-- A. ¿CUÁNDO se generan los cobros?  La generación SOLO se dispara
--    al abrir el Dashboard (no hay evento ni cron). Esta consulta
--    muestra cuántos cobros se crearon por día: los HUECOS entre
--    fechas = días en que nadie abrió el Dashboard => nada se generó.
-- ────────────────────────────────────────────────────────────
SELECT DATE(generado_en) AS dia, COUNT(*) AS cobros_generados
FROM cobros
WHERE concepto = 'mensualidad'
GROUP BY DATE(generado_en)
ORDER BY dia DESC
LIMIT 60;


-- ────────────────────────────────────────────────────────────
-- B. SÍNTOMA PRINCIPAL: alumnos activos ya vencidos SIN el cobro
--    de su periodo actual. Estos son los que "no se generaron".
-- ────────────────────────────────────────────────────────────
SELECT a.folio, a.nombre, a.vencimiento,
       DATEDIFF(CURDATE(), a.vencimiento) AS dias_vencido,
       a.ultimo_pago, a.mensualidad, a.saldo_favor
FROM alumnos a
WHERE a.status = 'activo'
  AND a.vencimiento <= CURDATE()
  AND NOT EXISTS (
      SELECT 1 FROM cobros c
      WHERE c.alumno_folio = a.folio
        AND c.concepto = 'mensualidad'
        AND c.periodo_desde = a.vencimiento
  )
ORDER BY a.vencimiento ASC;


-- ────────────────────────────────────────────────────────────
-- C. ATRASO DE GENERACIÓN: cobros cuyo periodo arrancó hace mucho
--    pero se insertaron tarde (el caso 22-may -> generado 12-jun).
--    dias_de_atraso alto = el evento no lo tomó a tiempo.
-- ────────────────────────────────────────────────────────────
SELECT c.alumno_folio, a.nombre,
       c.periodo_desde, DATE(c.generado_en) AS generado_el,
       DATEDIFF(DATE(c.generado_en), c.periodo_desde) AS dias_de_atraso,
       c.estado
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
WHERE c.concepto = 'mensualidad'
  AND DATEDIFF(DATE(c.generado_en), c.periodo_desde) > 7
ORDER BY dias_de_atraso DESC, c.generado_en DESC;


-- ────────────────────────────────────────────────────────────
-- D. DUPLICADOS: el mismo alumno con más de un cobro para el
--    mismo periodo (doble generación).
-- ────────────────────────────────────────────────────────────
SELECT alumno_folio, periodo_desde, COUNT(*) AS veces,
       GROUP_CONCAT(estado) AS estados,
       GROUP_CONCAT(id) AS ids
FROM cobros
WHERE concepto = 'mensualidad'
GROUP BY alumno_folio, periodo_desde
HAVING COUNT(*) > 1
ORDER BY veces DESC;


-- ────────────────────────────────────────────────────────────
-- E. HUECOS EN LA SECUENCIA: un mes pagado cuyo fin (periodo_hasta)
--    ya pasó y NO tiene el siguiente cobro encadenado. Significa
--    que se "saltó" un mes de cobro para ese alumno.
-- ────────────────────────────────────────────────────────────
SELECT c.alumno_folio, a.nombre,
       c.periodo_desde AS mes_pagado_desde,
       c.periodo_hasta AS deberia_continuar_en,
       DATEDIFF(CURDATE(), c.periodo_hasta) AS dias_sin_continuar
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio AND a.status = 'activo'
WHERE c.concepto = 'mensualidad'
  AND c.estado = 'pagado'
  AND c.periodo_hasta <= CURDATE()
  AND NOT EXISTS (
      SELECT 1 FROM cobros c2
      WHERE c2.alumno_folio = c.alumno_folio
        AND c2.concepto = 'mensualidad'
        AND c2.periodo_desde = c.periodo_hasta
  )
ORDER BY dias_sin_continuar DESC;


-- ────────────────────────────────────────────────────────────
-- F. DESFASE alumno.vencimiento vs sus cobros: el vencimiento del
--    alumno debería coincidir con el fin del último mes pagado.
--    Filas donde NO coincide = el contador del alumno se desincronizó.
-- ────────────────────────────────────────────────────────────
SELECT a.folio, a.nombre, a.vencimiento, a.ultimo_pago,
       (SELECT MAX(c.periodo_hasta)
          FROM cobros c
         WHERE c.alumno_folio = a.folio
           AND c.concepto = 'mensualidad'
           AND c.estado = 'pagado') AS ultimo_pagado_hasta
FROM alumnos a
WHERE a.status = 'activo'
HAVING ultimo_pagado_hasta IS NOT NULL
   AND ultimo_pagado_hasta <> a.vencimiento
ORDER BY a.vencimiento;


-- ────────────────────────────────────────────────────────────
-- G. MONTO INCONSISTENTE: cobros cuyo monto_base no es la
--    mensualidad actual del alumno (descuento doble histórico,
--    cambios de plan, o el cálculo viejo del evento).
-- ────────────────────────────────────────────────────────────
SELECT c.alumno_folio, a.nombre, c.periodo_desde,
       c.monto_base AS cobrado_base, a.mensualidad AS mensualidad_actual,
       ROUND(c.monto_base - a.mensualidad, 2) AS diferencia,
       c.estado
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
WHERE c.concepto = 'mensualidad'
  AND c.monto_base <> a.mensualidad
ORDER BY ABS(c.monto_base - a.mensualidad) DESC, c.generado_en DESC;


-- ────────────────────────────────────────────────────────────
-- H. LÍNEA DE TIEMPO DE UN ALUMNO (caso reportado).
--    Cambia '041' por el folio a investigar. Mezcla cobros y pagos
--    en orden cronológico para ver dónde se desfasó.
-- ────────────────────────────────────────────────────────────
SELECT folio, nombre, status, fecha_inicio, dia_pago,
       ultimo_pago, vencimiento, mensualidad, saldo_favor
FROM alumnos
WHERE folio = '041';

SELECT 'COBRO' AS tipo,
       c.periodo_desde      AS desde,
       c.periodo_hasta      AS hasta,
       c.monto_neto         AS monto,
       c.estado             AS detalle,
       c.generado_en        AS fecha_registro
FROM cobros c
WHERE c.alumno_folio = '041' AND c.concepto = 'mensualidad'
UNION ALL
SELECT 'PAGO' AS tipo,
       p.ciclo_desde, p.ciclo_hasta, p.monto, p.metodo, p.fecha
FROM pagos p
WHERE p.alumno_folio = '041'
ORDER BY fecha_registro;
