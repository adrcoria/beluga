-- ============================================================
--  AUDITORÍA DE COBROS — modelo por PROGRAMA (multimodalidad)
--  Solo lectura. Ejecuta cada bloque por separado.
--  Compatible con MySQL 5.7.
-- ============================================================


-- ═══════════════ DUPLICIDADES ═══════════════

-- A. DUPLICADO por programa: el mismo programa con >1 cobro del mismo periodo.
--    (Es el detector exacto: cada programa debe tener un solo cobro por periodo.)
--    NOTA: NULLs de programa_id se agrupan juntos → también caza duplicados sueltos.
SELECT c.programa_id, c.alumno_folio, a.nombre, pr.modalidad,
       c.periodo_desde, COUNT(*) AS veces,
       GROUP_CONCAT(c.estado)     AS estados,
       GROUP_CONCAT(c.id)         AS ids
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
LEFT JOIN alumno_programas pr ON pr.id = c.programa_id
WHERE c.concepto = 'mensualidad'
GROUP BY c.programa_id, c.alumno_folio, a.nombre, pr.modalidad, c.periodo_desde
HAVING COUNT(*) > 1
ORDER BY veces DESC, a.nombre;


-- B. Cobros de mensualidad con programa_id NULL (PELIGROSO).
--    La generación evita duplicados con (programa_id, periodo). Un NULL no matchea,
--    así que ese periodo se puede REGENERAR → duplicado. No deberían existir.
SELECT c.id, c.alumno_folio, a.nombre, c.periodo_desde, c.periodo_hasta,
       c.estado, c.generado_en
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
WHERE c.concepto = 'mensualidad' AND c.programa_id IS NULL
ORDER BY c.generado_en DESC;


-- C. Programas ACTIVOS duplicados: mismo alumno + misma modalidad más de una vez.
--    Cada uno genera su propio cobro → parecen cobros duplicados de esa modalidad.
SELECT alumno_folio, modalidad, COUNT(*) AS programas,
       GROUP_CONCAT(id) AS ids,
       GROUP_CONCAT(vencimiento) AS vencimientos
FROM alumno_programas
WHERE status = 'activo'
GROUP BY alumno_folio, modalidad
HAVING COUNT(*) > 1
ORDER BY programas DESC;


-- ═══════════════ PAGOS NO GENERADOS (faltantes) ═══════════════

-- D. Programas activos ya vencidos SIN su cobro del periodo actual.
--    Estos son los "no se generó el pago".
SELECT pr.id AS programa_id, pr.alumno_folio, a.nombre, pr.modalidad,
       pr.vencimiento, DATEDIFF(CURDATE(), pr.vencimiento) AS dias_vencido,
       pr.mensualidad
FROM alumno_programas pr
JOIN alumnos a ON a.folio = pr.alumno_folio
WHERE pr.status = 'activo' AND a.status = 'activo'
  AND pr.vencimiento <= CURDATE()
  AND NOT EXISTS (
      SELECT 1 FROM cobros c
      WHERE c.programa_id = pr.id
        AND c.concepto = 'mensualidad'
        AND c.periodo_desde = pr.vencimiento
  )
ORDER BY pr.vencimiento;


-- E. HUECO de secuencia: mes pagado cuyo fin ya pasó y no tiene el siguiente cobro.
SELECT c.programa_id, c.alumno_folio, a.nombre, pr.modalidad,
       c.periodo_desde AS mes_pagado_desde,
       c.periodo_hasta AS deberia_continuar_en,
       DATEDIFF(CURDATE(), c.periodo_hasta) AS dias_sin_continuar
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
LEFT JOIN alumno_programas pr ON pr.id = c.programa_id
WHERE c.concepto = 'mensualidad' AND c.estado = 'pagado'
  AND c.programa_id IS NOT NULL
  AND c.periodo_hasta <= CURDATE()
  AND EXISTS (SELECT 1 FROM alumno_programas p2 WHERE p2.id = c.programa_id AND p2.status = 'activo')
  AND NOT EXISTS (
      SELECT 1 FROM cobros c2
      WHERE c2.programa_id = c.programa_id
        AND c2.concepto = 'mensualidad'
        AND c2.periodo_desde = c.periodo_hasta
  )
ORDER BY dias_sin_continuar DESC;


-- ═══════════════ CONSISTENCIA / DESFASES ═══════════════

-- F. Desfase: vencimiento del programa ≠ fin de su último mes pagado.
SELECT pr.id AS programa_id, pr.alumno_folio, a.nombre, pr.modalidad,
       pr.vencimiento,
       (SELECT MAX(c.periodo_hasta) FROM cobros c
         WHERE c.programa_id = pr.id AND c.concepto = 'mensualidad' AND c.estado = 'pagado'
       ) AS ultimo_pagado_hasta
FROM alumno_programas pr
JOIN alumnos a ON a.folio = pr.alumno_folio
WHERE pr.status = 'activo'
HAVING ultimo_pagado_hasta IS NOT NULL AND ultimo_pagado_hasta <> pr.vencimiento
ORDER BY pr.vencimiento;


-- G. Atraso de generación: cobros creados mucho después del inicio de su periodo.
SELECT c.programa_id, c.alumno_folio, a.nombre, pr.modalidad,
       c.periodo_desde, DATE(c.generado_en) AS generado_el,
       DATEDIFF(DATE(c.generado_en), c.periodo_desde) AS dias_de_atraso, c.estado
FROM cobros c
JOIN alumnos a ON a.folio = c.alumno_folio
LEFT JOIN alumno_programas pr ON pr.id = c.programa_id
WHERE c.concepto = 'mensualidad'
  AND DATEDIFF(DATE(c.generado_en), c.periodo_desde) > 7
ORDER BY dias_de_atraso DESC, c.generado_en DESC;


-- ═══════════════ RESUMEN RÁPIDO ═══════════════

-- H. Conteos para dimensionar el problema de un vistazo.
SELECT
  (SELECT COUNT(*) FROM cobros WHERE concepto='mensualidad' AND programa_id IS NULL)                          AS mensualidades_sin_programa,
  (SELECT COUNT(*) FROM (
      SELECT 1 FROM cobros WHERE concepto='mensualidad'
      GROUP BY programa_id, alumno_folio, periodo_desde HAVING COUNT(*)>1
   ) t)                                                                                                        AS grupos_duplicados,
  (SELECT COUNT(*) FROM (
      SELECT 1 FROM alumno_programas WHERE status='activo'
      GROUP BY alumno_folio, modalidad HAVING COUNT(*)>1
   ) t)                                                                                                        AS programas_duplicados,
  (SELECT COUNT(*) FROM alumno_programas pr JOIN alumnos a ON a.folio=pr.alumno_folio
     WHERE pr.status='activo' AND a.status='activo' AND pr.vencimiento <= CURDATE()
       AND NOT EXISTS (SELECT 1 FROM cobros c WHERE c.programa_id=pr.id AND c.concepto='mensualidad' AND c.periodo_desde=pr.vencimiento)
   )                                                                                                           AS programas_sin_cobro;
