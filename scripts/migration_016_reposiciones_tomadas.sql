-- ============================================================
-- Migración 016 — Reposiciones: estado 'tomada' y fecha de la falta
--
-- 'tomada': la clase de reposición ya pasó. Sirve para que salga del
-- pizarrón sola, sin que nadie tenga que ir a limpiarla a mano.
-- Se marca sin intervención en cuanto la fecha queda atrás.
--
-- fecha_falta: qué día faltó el alumno. Con eso el pizarrón puede
-- pintar esa celda y se ve de un vistazo qué clase se está reponiendo.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE reposiciones
    MODIFY COLUMN estado ENUM('pendiente','agendada','tomada','vencida','cancelada')
        NOT NULL DEFAULT 'pendiente',
    ADD COLUMN fecha_falta DATE NULL AFTER motivo;

-- La falta que originó cada comodín no se registraba. Para los que ya
-- existen se deja en NULL: no hay forma de deducirla hacia atrás.
ALTER TABLE reposiciones
    ADD KEY idx_rep_fecha_falta (fecha_falta);
