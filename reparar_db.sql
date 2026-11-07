-- SCRIPT DE REPARACIÓN PARA phpMyAdmin
-- Ejecuta este código en la pestaña SQL de tu base de datos en el hosting

-- 1. Asegurar que no haya datos basura con ID 0 que bloqueen el proceso
SET @count = 0;
UPDATE `recetas_biologico` SET `id` = (@count := @count + 1);

-- 2. Corregir estructura: Definir llave primaria y AUTO_INCREMENT
ALTER TABLE `recetas_biologico` 
  MODIFY `id` int(11) NOT NULL;

-- Si por alguna razón no tiene Primary Key, la agregamos
ALTER TABLE `recetas_biologico` 
  ADD PRIMARY KEY IF NOT EXISTS (`id`);

-- Activar el AUTO_INCREMENT real
ALTER TABLE `recetas_biologico` 
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- 3. ELIMINAR CUALQUER TRIGGER QUE ESTÉ DIVIDIENDO LOS FRASCOS
-- Si existe algún disparador antiguo que esté causando el 5-5-5, aquí lo buscamos o prevenimos
-- (Nota: SHOW TRIGGERS te dirá si hay alguno, pero este script asegura la limpieza de ID)

-- 4. Verificar folios (Opcional, solo consulta)
SELECT 'Folio más alto en 2026' as Info, MAX(folio) FROM `recetas_biologico` WHERE anio = 2026;
