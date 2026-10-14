-- ============================================================
-- Migración 017 — Horario del instructor por día
--
-- Hasta ahora cada maestro tenía UN rango igual para toda la semana
-- (migración 008) más una lista de días que asiste (migración 012).
-- Eso no alcanza: hay quien entra 8-14 los lunes y 16-20 los miércoles.
--
-- Un bloque por día, que es lo que opera la escuela. Si un maestro no
-- tiene fila para un día, no viene ese día.
--
-- ADITIVA: `disp_hora_inicio`, `disp_hora_fin` y `dias_asiste` se quedan
-- en su lugar y se dejan de leer, igual que se hizo en la 013. Se borran
-- cuando el código nuevo lleve tiempo estable.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS instructor_horario (
    instructor_id CHAR(36)                      NOT NULL,
    dia           ENUM('L','M','X','J','V','S') NOT NULL,
    hora_inicio   TIME                          NOT NULL,
    hora_fin      TIME                          NOT NULL,
    PRIMARY KEY (instructor_id, dia),
    CONSTRAINT fk_ih_instructor FOREIGN KEY (instructor_id)
        REFERENCES instructores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Traspasar lo que ya estaba configurado: una fila por cada día que el
-- maestro asiste, con su rango único. Solo para quienes tenían rango;
-- los que no tenían quedan sin filas = sin restricción, como hoy.
INSERT IGNORE INTO instructor_horario (instructor_id, dia, hora_inicio, hora_fin)
SELECT i.id, d.dia, i.disp_hora_inicio, i.disp_hora_fin
FROM instructores i
CROSS JOIN (
    SELECT 'L' AS dia UNION ALL SELECT 'M' UNION ALL SELECT 'X'
    UNION ALL SELECT 'J' UNION ALL SELECT 'V' UNION ALL SELECT 'S'
) d
WHERE i.disp_hora_inicio IS NOT NULL
  AND i.disp_hora_fin    IS NOT NULL
  -- dias_asiste vacío o nulo significaba "todos los días"
  AND (
        i.dias_asiste IS NULL
     OR JSON_LENGTH(i.dias_asiste) = 0
     OR JSON_CONTAINS(i.dias_asiste, JSON_QUOTE(d.dia))
  );
