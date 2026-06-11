-- ============================================================
-- Migración 009 — Folio consecutivo de recibos de pago
-- Consecutivo numérico por sucursal. 'siguiente' = próximo folio
-- a asignar. El admin puede establecer el punto de partida
-- (solo hacia arriba). Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS folio_pago_seq (
    sucursal_id CHAR(36)     NOT NULL,
    siguiente   INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (sucursal_id),
    CONSTRAINT fk_fps_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inicializa el consecutivo en 1 para las sucursales existentes
INSERT IGNORE INTO folio_pago_seq (sucursal_id, siguiente)
SELECT id, 1 FROM sucursales;
