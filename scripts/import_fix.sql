-- ============================================================
-- Acuatica Beluga — Parche import_fix.sql
-- Corrige 14 registros que fallaron por NULL en fecha_inicio
-- o telefono. Ejecutar DESPUES de import_data.sql.
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── 8 registros: fecha_inicio NULL → usar ultimo_pago ─────────────
-- dia_pago recalculado desde la fecha de fallback

-- 060 JESUS SANTIAGO AMEZCUA ASCENCIO (ultimo_pago 2026-01-15 → dia 15)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('060','JESUS SANTIAGO AMEZCUA ASCENCIO','2022-11-16','MARIA TERESA ASCENCIO','4521060233',NULL,
   'PART',1,650.00,'2026-01-15',15,'2026-01-15','2026-02-15',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 061 DARYL GERMAN ARRIAGA GUITIERREZ (ultimo_pago 2026-01-07 → dia 7)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('061','DARYL GERMAN ARRIAGA GUITIERREZ',NULL,NULL,'4434890263',NULL,
   'PART',1,650.00,'2026-01-07',7,'2026-01-07','2026-02-07',1,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 070 LUCIA XIMENA LOPEZ GUZMAN (ultimo_pago 2026-01-06 → dia 6)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('070','LUCIA XIMENA LOPEZ GUZMAN',NULL,NULL,'4433962000',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 071 EMMA CHAVES (ultimo_pago 2026-01-06 → dia 6)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('071','EMMA CHAVES',NULL,NULL,'4431310063',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 096 BASTIAN FERNANDO FRANCO CEDEÑO (ultimo_pago 2026-01-06 → dia 6)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('096','BASTIAN FERNANDO FRANCO CEDEÑO',NULL,NULL,'5574321021',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 121 MATEO HURTADO VERGARA (ultimo_pago 2026-01-06 → dia 6)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('121','MATEO HURTADO VERGARA',NULL,NULL,'4432273551',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',1,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 165 GUILLERMO GAEL GONZALEZ AVALOS (ultimo_pago 2026-01-06 → dia 6)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('165','GUILLERMO GAEL GONZALEZ AVALOS','2020-07-18','GUILLERMO GONZALEZ','4591134135',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 166 IVANNA MAGDALENA GONZALEZ AVALOS (ultimo_pago 2026-01-06 → dia 6, desc 15% Hermanos)
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('166','IVANNA MAGDALENA GONZALEZ AVALOS','2017-03-17','GUILLERMO GONZALEZ','4591134135',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,15.00,'Hermanos','[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  fecha_inicio=VALUES(fecha_inicio), dia_pago=VALUES(dia_pago),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), telefono=VALUES(telefono),
  modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- ── 6 registros: telefono NULL → '' ──────────────────────────────

-- 105 LEONARDO GONZALEZ OROZCO
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('105','LEONARDO GONZALEZ OROZCO',NULL,NULL,'',NULL,
   'PART',1,650.00,'2026-01-07',7,'2026-02-07','2026-03-07',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 108 IAN LEON ROMERO MEXICANO
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('108','IAN LEON ROMERO MEXICANO',NULL,NULL,'',NULL,
   'GRUPAL',1,600.00,'2026-01-11',11,'2026-05-11','2026-06-11',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 116 MARIANA ALBOR MACIAS
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('116','MARIANA ALBOR MACIAS',NULL,NULL,'',NULL,
   'PART',1,650.00,'2026-02-28',28,'2026-02-28','2026-03-28',1,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 122 JUAN CARLOS LUNA
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('122','JUAN CARLOS LUNA',NULL,NULL,'',NULL,
   'PART',1,650.00,'2026-01-24',24,'2026-05-24','2026-06-24',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 129 LUNA SOFIA
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('129','LUNA SOFIA',NULL,NULL,'',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

-- 130 OSMAR SEBASTIAN
INSERT INTO alumnos
  (folio,nombre,fecha_nacimiento,tutor,telefono,email,modalidad,clases_semana,mensualidad,
   fecha_inicio,dia_pago,ultimo_pago,vencimiento,inscripcion_pagada,
   descuento_pct,descuento_motivo,disp_dias,status,sucursal_id)
VALUES
  ('130','OSMAR SEBASTIAN',NULL,NULL,'',NULL,
   'PART',1,650.00,'2026-01-06',6,'2026-01-06','2026-02-06',0,NULL,NULL,'[]','activo',
   '00000000-0000-0000-0000-000000000001')
ON DUPLICATE KEY UPDATE
  telefono=VALUES(telefono),
  nombre=VALUES(nombre), fecha_nacimiento=VALUES(fecha_nacimiento),
  tutor=VALUES(tutor), modalidad=VALUES(modalidad), clases_semana=VALUES(clases_semana),
  mensualidad=VALUES(mensualidad), fecha_inicio=VALUES(fecha_inicio),
  dia_pago=VALUES(dia_pago), ultimo_pago=VALUES(ultimo_pago),
  vencimiento=VALUES(vencimiento), inscripcion_pagada=VALUES(inscripcion_pagada),
  descuento_pct=VALUES(descuento_pct), descuento_motivo=VALUES(descuento_motivo);

SET FOREIGN_KEY_CHECKS = 1;

-- Verificacion final
SELECT COUNT(*) AS total_alumnos FROM alumnos;
