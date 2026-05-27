-- ============================================================
-- Migración 001 — Soporte OTP y cambio de contraseña obligatorio
-- Ejecutar una sola vez sobre la BD existente
-- ============================================================

-- 1. Columna en usuarios (ejecutar solo si no existe)
ALTER TABLE usuarios
  ADD COLUMN debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 0;

-- 2. Tabla de códigos OTP temporales
CREATE TABLE IF NOT EXISTS otp_tokens (
    id         CHAR(36)   NOT NULL,
    usuario_id CHAR(36)   NOT NULL,
    codigo     CHAR(6)    NOT NULL,
    expira_en  DATETIME   NOT NULL,
    usado      TINYINT(1) NOT NULL DEFAULT 0,
    creado_en  TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_otp_usuario (usuario_id),
    CONSTRAINT fk_otp_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
