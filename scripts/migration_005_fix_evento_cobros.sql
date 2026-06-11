-- ============================================================
-- Migración 005 — Corregir evento de cobros
-- mensualidad ya trae el descuento incorporado; eliminar el
-- cálculo duplicado del evento automático.
-- ============================================================

DROP EVENT IF EXISTS evt_generar_cobros;

DELIMITER $$

CREATE EVENT evt_generar_cobros
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE(), ' 00:01:00')
DO
BEGIN
    -- Generar cobros para alumnos cuyo vencimiento es hoy
    INSERT INTO cobros (
        id, alumno_folio, concepto,
        monto_base, saldo_favor_aplicado, monto_neto,
        estado, periodo_desde, periodo_hasta
    )
    SELECT
        UUID(),
        a.folio,
        'mensualidad',
        a.mensualidad,
        LEAST(a.saldo_favor, a.mensualidad),
        GREATEST(0, a.mensualidad - a.saldo_favor),
        CASE
            WHEN a.saldo_favor >= a.mensualidad THEN 'pagado'
            ELSE 'pendiente'
        END,
        a.vencimiento,
        DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
    FROM alumnos a
    WHERE a.status = 'activo'
      AND a.vencimiento = CURDATE()
      AND NOT EXISTS (
          SELECT 1 FROM cobros c
          WHERE c.alumno_folio = a.folio
            AND c.concepto = 'mensualidad'
            AND c.periodo_desde = a.vencimiento
      );

    -- Avanzar alumnos que quedaron pagados automáticamente por saldo a favor
    UPDATE alumnos a
    INNER JOIN cobros c
        ON c.alumno_folio = a.folio
       AND c.periodo_desde = CURDATE()
       AND c.estado = 'pagado'
       AND c.saldo_favor_aplicado > 0
    SET
        a.saldo_favor    = GREATEST(0, a.saldo_favor - c.monto_base),
        a.ultimo_pago    = CURDATE(),
        a.vencimiento    = DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
    WHERE a.status = 'activo';
END$$

DELIMITER ;
