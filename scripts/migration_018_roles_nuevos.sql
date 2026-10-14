-- ============================================================
-- Migración 018 — Roles Instructor y Coordinador
--
-- El cliente separó dos perfiles que hasta ahora caían en 'operativo':
--   instructor  → consulta el pizarrón y a los alumnos, no cobra
--   coordinador → además agenda reposiciones y administra maestros
--
-- ADITIVA: no se reasigna a nadie. Los usuarios que hoy son 'operativo'
-- siguen siéndolo; se les cambia el rol a mano desde Usuarios del Sistema
-- cuando la escuela decida quién es quién.
--
-- Los permisos de cada rol NO viven aquí: salen de la matriz que llenó el
-- cliente, en backend/config/permisos.php.
-- Compatible con MySQL 5.7+.
-- ============================================================
SET NAMES utf8mb4;

ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('administrador','gerencia','operativo','instructor','coordinador')
        NOT NULL;

-- Verificación: debe listar los cinco valores
-- SHOW COLUMNS FROM usuarios LIKE 'rol';
