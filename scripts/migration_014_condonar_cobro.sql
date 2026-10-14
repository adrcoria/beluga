-- ============================================================
-- Migración 014 — Condonar un cobro ("no pedir cobro")
--
-- Un cobro condonado se da por CUBIERTO: el alumno no debe ese mes
-- y su vencimiento avanza al siguiente, igual que si hubiera pagado.
-- Se guarda el motivo porque siempre hay que poder explicar por qué
-- no se cobró (beca, cortesía, error de captura, etc.).
--
-- OJO al aplicar: todas las consultas que trataban `estado <> 'pagado'`
-- como "pendiente" pasan a `estado NOT IN ('pagado','condonado')`,
-- si no un mes condonado bloquea el FIFO y ancla el vencimiento.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE cobros
    MODIFY COLUMN estado ENUM('pendiente','parcial','pagado','condonado')
        NOT NULL DEFAULT 'pendiente',
    ADD COLUMN motivo_no_cobro VARCHAR(255) NULL AFTER estado,
    ADD COLUMN condonado_por   CHAR(36)     NULL AFTER motivo_no_cobro,
    ADD COLUMN condonado_en    TIMESTAMP    NULL AFTER condonado_por;
