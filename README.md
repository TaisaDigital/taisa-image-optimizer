# Taisa Image Optimizer

Plugin WordPress de uso puntual para inspeccionar la biblioteca y limpiar derivados de imágenes:

- Inventario de tamanos de imagen registrados y derivados existentes.
- Lectura del estado de generación configurado en Taisa Tools.
- Borrado por lotes de derivados pertenecientes a tamaños desactivados allí.
- Escaneo de uso en base de datos y URLs publicas.
- Columna y filtro "Uso detectado" en la biblioteca de medios.
- Deteccion de originales grandes y redimensionado por lotes con copia opcional.

## Flujo recomendado

1. Activar Taisa Tools para gestionar los tamaños que WordPress deja de generar y activar este plugin para la auditoría/limpieza.
2. Ir a `Medios > Optimizador de imagenes`.
3. Ejecutar `Escanear tamanos generados`.
4. Ejecutar `Escanear uso de imagenes`.
5. En `Ajustes > Taisa Tools`, desactivar los tamaños que no quieras generar.
6. Revisar el filtro de Medios `Usadas: no detectadas`.
7. Solo cuando esté revisado, ejecutar `Borrar derivados desactivados en Taisa Tools`.

El plugin no borra originales al limpiar tamanos. Solo elimina los archivos derivados guardados en `_wp_attachment_metadata['sizes']` para los tamanos desactivados. Si un fichero derivado tambien esta asociado a un tamano que sigue activo, conserva el fichero y elimina solo la referencia desactivada.

El detector de originales puede marcar archivos que exceden solo el limite de peso. Esos casos quedan identificados como pendientes de compresion y no se redimensionan automaticamente si sus dimensiones ya estan dentro del limite.

Cuando esta activada la copia de seguridad, los originales previos al redimensionado se guardan en `wp-content/uploads/taisa-image-optimizer-backups/`.

## Actualizaciones

El plugin consulta las releases de `TaisaDigital/taisa-image-optimizer`. Publica las versiones con etiquetas `vX.Y.Z`; el ZIP de la release o el archivo fuente de GitHub se ofrecerá en la pantalla normal de actualizaciones de WordPress.

## Changelog

### 0.2.0

- Añadido actualizador desde releases de GitHub.
- Retirados los controles propios para desactivar tamaños; el estado se lee desde Taisa Tools.
- La limpieza por lotes usa la lista compartida de tamaños desactivados.

