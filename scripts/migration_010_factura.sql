-- ============================================================
-- Migración 010 — Control de facturación en cobros
-- requiere_factura  → se marca al registrar el pago
-- factura_realizada → se marca cuando ya se emitió la factura
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE cobros
    ADD COLUMN requiere_factura  TINYINT(1) NOT NULL DEFAULT 0 AFTER estado,
    ADD COLUMN factura_realizada TINYINT(1) NOT NULL DEFAULT 0 AFTER requiere_factura;
