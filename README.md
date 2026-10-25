# Taisa Image Optimizer

Plugin WordPress de uso puntual para inspeccionar la biblioteca y limpiar derivados de imágenes:

- Inventario de tamanos de imagen registrados y derivados existentes.
- Lectura del estado de generación configurado en Taisa Tools.
- Borrado por lotes de derivados pertenecientes a tamaños desactivados allí.
- Escaneo de uso en base de datos y URLs publicas.
- Columna y filtro "Uso detectado" en la biblioteca de medios.
- Deteccion de originales grandes y redimensionado por lotes con copia opcional.

## Flujo recomendado

1. Activar este plugin para la auditoría y limpieza. Su opción de tamaños es compartida con Taisa Tools cuando está disponible.
2. Ir a `Medios > Optimizador de imagenes`.
3. Ejecutar `Escanear tamanos generados`.
4. Ejecutar `Escanear uso de imagenes`.
5. En `Medios > Optimizador de imagenes`, desactivar los tamaños que no quieras generar y conservar los necesarios para el catálogo.
6. Revisar el filtro de Medios `Usadas: no detectadas`.
7. Solo cuando esté revisado, ejecutar `Borrar derivados desactivados en Taisa Tools`.

El plugin no borra originales al limpiar tamanos. Solo elimina los archivos derivados guardados en `_wp_attachment_metadata['sizes']` para los tamanos desactivados. Si un fichero derivado tambien esta asociado a un tamano que sigue activo, conserva el fichero y elimina solo la referencia desactivada.

El icono configurado en `site_icon`, los adjuntos marcados como contexto `site-icon` y los archivos adjuntos cuyo nombre contiene `favicon` quedan excluidos de la limpieza individual, la limpieza por lotes y el redimensionado de originales.

La versión 0.3.0 permite ajustar desde el propio optimizador qué tamaños se generan en nuevas subidas, compartiendo la opción con Taisa Tools. También permite borrar una sola variante registrada por ID de medio y nombre de tamaño para comprobar la presentación antes de una limpieza general. Comprueba que no existan enlaces directos a la variante antes de borrarla. El borrado requiere una copia de seguridad recuperable.

El detector de originales puede marcar archivos que exceden solo el limite de peso. Esos casos quedan identificados como pendientes de compresion y no se redimensionan automaticamente si sus dimensiones ya estan dentro del limite.

Cuando esta activada la copia de seguridad, los originales previos al redimensionado se guardan en `wp-content/uploads/taisa-image-optimizer-backups/`.

## Actualizaciones

El plugin consulta las releases de `TaisaDigital/taisa-image-optimizer`. Publica las versiones con etiquetas `vX.Y.Z`; el ZIP de la release o el archivo fuente de GitHub se ofrecerá en la pantalla normal de actualizaciones de WordPress.

## Changelog

### 0.3.1

- Protegidos el favicon y el icono del sitio frente a la limpieza de variantes y el redimensionado de originales.

### 0.3.0

- Añadido control de generación futura de tamaños sin sustituir instalaciones antiguas de Taisa Tools.
- Añadida limpieza individual de una variante registrada y protección adicional de la imagen original.

### 0.2.0

- Añadido actualizador desde releases de GitHub.
- Retirados los controles propios para desactivar tamaños; el estado se lee desde Taisa Tools.
- La limpieza por lotes usa la lista compartida de tamaños desactivados.

