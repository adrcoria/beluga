-- ============================================================
-- Migración 006 — Rediseño de Horarios (Fase 1)
-- Modelo: asignaciones recurrentes con vigencia + grupos
-- persistentes + parámetros globales de configuración.
--
-- NO toca las tablas viejas (slots, asignaciones); quedan
-- inactivas y se podrán eliminar una vez migrado el frontend.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

-- ── Configuración global (key-value) ──────────────────────────────
CREATE TABLE IF NOT EXISTS configuracion (
    clave VARCHAR(60)  NOT NULL,
    valor VARCHAR(255) NOT NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO configuracion (clave, valor) VALUES
    ('horario_grid_inicio', '08:00'),
    ('horario_grid_fin',    '21:00'),
    ('horario_cupo_grupal', '6');

-- ── Grupos GRUPAL persistentes ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS grupos (
    id          CHAR(36)     NOT NULL,
    nombre      VARCHAR(120) NOT NULL,
    sucursal_id CHAR(36)     NOT NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    creado_en   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_grupos_sucursal (sucursal_id),
    CONSTRAINT fk_grupos_sucursal FOREIGN KEY (sucursal_id) REFERENCES sucursales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Miembros de cada grupo ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS grupo_alumnos (
    grupo_id     CHAR(36)    NOT NULL,
    alumno_folio VARCHAR(10) NOT NULL,
    PRIMARY KEY (grupo_id, alumno_folio),
    KEY idx_ga_alumno (alumno_folio),
    CONSTRAINT fk_ga_grupo  FOREIGN KEY (grupo_id)     REFERENCES grupos  (id)    ON DELETE CASCADE,
    CONSTRAINT fk_ga_alumno FOREIGN KEY (alumno_folio) REFERENCES alumnos (folio) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Asignación recurrente (la "regla" semanal) ─────────────────────
-- Una fila = un ocupante de una celda (instructor × día × hora) que
-- se repite cada semana mientras esté vigente.
-- alumno_folio  → PART / MATRO (1 a 1)
-- grupo_id      → GRUPAL (un grupo ocupa la celda)
-- Exactamente uno de los dos va lleno.
CREATE TABLE IF NOT EXISTS horario_recurrente (
    id            CHAR(36)                      NOT NULL,
    sucursal_id   CHAR(36)                      NOT NULL,
    instructor_id CHAR(36)                      NOT NULL,
    dia           ENUM('L','M','X','J','V','S') NOT NULL,
    hora_inicio   TIME                          NOT NULL,
    duracion_min  SMALLINT UNSIGNED             NOT NULL DEFAULT 30,
    modalidad     VARCHAR(20)                   NOT NULL,
    alumno_folio  VARCHAR(10)                   NULL,
    grupo_id      CHAR(36)                      NULL,
    vigente_desde DATE                          NOT NULL,
    vigente_hasta DATE                          NULL,   -- NULL = indefinido
    creado_en     TIMESTAMP                     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hr_suc_dia  (sucursal_id, dia),
    KEY idx_hr_instr    (instructor_id),
    KEY idx_hr_alumno   (alumno_folio),
    KEY idx_hr_grupo    (grupo_id),
    KEY idx_hr_vigencia (vigente_desde, vigente_hasta),
    CONSTRAINT fk_hr_sucursal   FOREIGN KEY (sucursal_id)   REFERENCES sucursales   (id),
    CONSTRAINT fk_hr_instructor FOREIGN KEY (instructor_id) REFERENCES instructores (id),
    CONSTRAINT fk_hr_alumno     FOREIGN KEY (alumno_folio)  REFERENCES alumnos (folio) ON DELETE CASCADE,
    CONSTRAINT fk_hr_grupo      FOREIGN KEY (grupo_id)      REFERENCES grupos  (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
