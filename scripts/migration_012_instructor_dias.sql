-- ============================================================
-- Migración 012 — Días que asiste cada instructor
-- dias_asiste: JSON array de claves de día (['L','M','X','J','V','S']).
-- NULL o vacío = asiste todos los días (compatibilidad hacia atrás).
-- ============================================================

ALTER TABLE instructores
    ADD COLUMN dias_asiste JSON NULL AFTER disp_hora_fin;
