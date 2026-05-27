-- ============================================================
-- Migración 003 — Módulo de Cobranza
-- ============================================================

-- 1. Tabla de cobros (deuda generada por ciclo)
CREATE TABLE IF NOT EXISTS cobros (
    id                   CHAR(36)                               NOT NULL,
    alumno_folio         VARCHAR(10)                            NOT NULL,
    monto_base           DECIMAL(8,2)                           NOT NULL,
    saldo_favor_aplicado DECIMAL(8,2)                           NOT NULL DEFAULT 0,
    monto_neto           DECIMAL(8,2)                           NOT NULL,
    monto_pagado         DECIMAL(8,2)                           NOT NULL DEFAULT 0,
    estado               ENUM('pendiente','parcial','pagado')   NOT NULL DEFAULT 'pendiente',
    periodo_desde        DATE                                   NOT NULL,
    periodo_hasta        DATE                                   NOT NULL,
    generado_en          TIMESTAMP                              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cobros_alumno (alumno_folio),
    KEY idx_cobros_estado (estado),
    KEY idx_cobros_periodo (periodo_desde),
    CONSTRAINT fk_cobros_alumno FOREIGN KEY (alumno_folio)
        REFERENCES alumnos (folio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Saldo a favor en alumnos
ALTER TABLE alumnos
    ADD COLUMN saldo_favor DECIMAL(8,2) NOT NULL DEFAULT 0;

-- 3. Vincular pagos a cobros
ALTER TABLE pagos
    ADD COLUMN cobro_id CHAR(36) NULL,
    ADD CONSTRAINT fk_pagos_cobro FOREIGN KEY (cobro_id)
        REFERENCES cobros (id);

-- 4. Evento MySQL: genera cobros cada día a medianoche
DELIMITER $$

CREATE EVENT IF NOT EXISTS evt_generar_cobros
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE(), ' 00:01:00')
DO
BEGIN
    -- Insertar cobros para alumnos cuyo vencimiento es hoy
    INSERT INTO cobros (
        id, alumno_folio,
        monto_base, saldo_favor_aplicado, monto_neto,
        estado, periodo_desde, periodo_hasta
    )
    SELECT
        UUID(),
        a.folio,
        ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2),
        LEAST(a.saldo_favor, ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2)),
        GREATEST(0, ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2) - a.saldo_favor),
        CASE
            WHEN a.saldo_favor >= ROUND(a.mensualidad * (1 - COALESCE(a.descuento_pct, 0) / 100), 2)
            THEN 'pagado' ELSE 'pendiente'
        END,
        a.vencimiento,
        DATE_ADD(a.vencimiento, INTERVAL 1 MONTH)
    FROM alumnos a
    WHERE a.status = 'activo'
      AND a.vencimiento = CURDATE()
      AND NOT EXISTS (
          SELECT 1 FROM cobros c
          WHERE c.alumno_folio = a.folio
            AND c.periodo_desde = a.vencimiento
      );

    -- Cobros pagados automáticamente por saldo a favor: avanzar alumno
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
