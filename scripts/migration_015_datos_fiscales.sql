-- ============================================================
-- Migración 015 — Datos de facturación
--
-- Dos lugares a propósito:
--   alumno_datos_fiscales → los datos vigentes del alumno, se capturan
--                           una vez y se reusan en cada factura.
--   cobros.fact_*         → copia congelada al momento de pedir la
--                           factura. Si mañana corrigen el RFC, la
--                           factura vieja conserva con qué se emitió.
--
-- Los catálogos de uso_cfdi y regimen_fiscal son claves del SAT
-- (G03, 601, …); viven como constantes en el frontend, no en tabla.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS alumno_datos_fiscales (
    alumno_folio   VARCHAR(10)  NOT NULL,
    razon_social   VARCHAR(200) NOT NULL,
    rfc            VARCHAR(13)  NOT NULL,
    regimen_fiscal VARCHAR(10)  NULL,
    uso_cfdi       VARCHAR(10)  NULL,
    codigo_postal  VARCHAR(5)   NULL,
    email          VARCHAR(160) NULL,
    actualizado_en TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (alumno_folio),
    KEY idx_fiscal_rfc (rfc),
    CONSTRAINT fk_fiscal_alumno FOREIGN KEY (alumno_folio)
        REFERENCES alumnos (folio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE cobros
    ADD COLUMN fact_razon_social   VARCHAR(200) NULL AFTER factura_realizada,
    ADD COLUMN fact_rfc            VARCHAR(13)  NULL AFTER fact_razon_social,
    ADD COLUMN fact_regimen_fiscal VARCHAR(10)  NULL AFTER fact_rfc,
    ADD COLUMN fact_uso_cfdi       VARCHAR(10)  NULL AFTER fact_regimen_fiscal,
    ADD COLUMN fact_codigo_postal  VARCHAR(5)   NULL AFTER fact_uso_cfdi,
    ADD COLUMN fact_email          VARCHAR(160) NULL AFTER fact_codigo_postal;
