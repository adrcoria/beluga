-- ============================================================
-- Migración 022 — Ventana válida para registrar asistencia
--
-- Antes se tomaba la clase "más cercana" dentro de 90 minutos y, si no
-- había ninguna, se registraba igual con un aviso. Eso daba el caso raro de
-- decir "registrado, pero no tiene clase a esta hora".
--
-- Ahora el escaneo solo vale si cae DENTRO de la clase, o hasta cierto
-- número de minutos ANTES de que empiece. Fuera de eso no se registra.
-- ============================================================
SET NAMES utf8mb4;

-- Cuánto antes de su clase puede llegar el alumno a registrarse
INSERT INTO configuracion (clave, valor)
VALUES ('asistencia_min_antes', '30')
ON DUPLICATE KEY UPDATE valor = valor;

-- Tolerancia después de que la clase termina (0 = justo al terminar se cierra)
INSERT INTO configuracion (clave, valor)
VALUES ('asistencia_min_despues', '0')
ON DUPLICATE KEY UPDATE valor = valor;

-- El parámetro viejo ya no se usa
DELETE FROM configuracion WHERE clave = 'asistencia_ventana_min';
