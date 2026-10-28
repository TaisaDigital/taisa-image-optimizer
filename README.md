# Taisa Image Optimizer

## Limpieza por lotes (desde 0.4.0)

La limpieza procesa como máximo 5 adjuntos por petición y espera 3 segundos antes de enviar el siguiente lote. No hay solicitudes solapadas ni una cola del lado del servidor. El progreso y cursor se guardan en opciones de WordPress, por lo que una recarga permite continuar un trabajo que estaba en ejecución. Un trabajo pausado requiere pulsar **Reanudar**; al hacerlo, el plugin valida que el último escaneo de tamaños tenga menos de 24 horas, que la lista de adjuntos coincida con la del escaneo y que no haya cambiado la política de tamaños desactivados. Si falla esa comprobación, hay que escanear y revisar de nuevo, y preparar otra limpieza.

**Pausar** impide que se envíen más lotes; una petición PHP que ya empezó termina primero y queda indicada como en curso mientras responde. **Cancelar y abandonar** descarta el cursor una vez terminada la petición actual; no restaura archivos ya eliminados. Cerrar o abandonar la página evita que el navegador envíe nuevos lotes, pero no puede cancelar una petición PHP que ya empezó. El informe acumula adjuntos procesados, derivados borrados, referencias compartidas conservadas, errores y bytes liberados. El borrado y la actualización de metadatos son seguros ante una repetición: un reintento no borra originales ni archivos ya ausentes.

El límite de 5 adjuntos y la pausa de 3 segundos reducen la cadencia del optimizador, pero no controlan la cuota global de ejecuciones de SiteGround ni la carga provocada por otros procesos de la cuenta. La cuota depende también del número de peticiones AJAX y de las tareas del resto del sitio.

Plugin WordPress de uso puntual para inspeccionar la biblioteca y limpiar derivados de imágenes:

Instálalo solo durante la auditoría y optimización de la biblioteca. Al terminar el proceso, desactívalo y elimínalo. La desinstalación borra sus opciones y metadatos de análisis; las copias de originales guardadas en `uploads/taisa-image-optimizer-backups/` se conservan para que puedas recuperarlas o eliminarlas manualmente cuando ya no las necesites.

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
6. Volver a ejecutar `Escanear tamanos generados` después de guardar la política de tamaños desactivados; la limpieza exige que ese escaneo corresponda a la biblioteca y política actuales.
7. Revisar el filtro de Medios `Usadas: no detectadas` y los tamaños objetivo.
8. Solo cuando esté revisado, ejecutar `Borrar derivados desactivados en Taisa Tools`.

El plugin no borra originales al limpiar tamanos. Solo elimina los archivos derivados guardados en `_wp_attachment_metadata['sizes']` para los tamanos desactivados. Si un fichero derivado tambien esta asociado a un tamano que sigue activo, conserva el fichero y elimina solo la referencia desactivada.

El icono configurado en `site_icon`, los adjuntos marcados como contexto `site-icon` y los archivos adjuntos cuyo nombre contiene `favicon` quedan excluidos de la limpieza individual, la limpieza por lotes y el redimensionado de originales.

La versión 0.3.0 permite ajustar desde el propio optimizador qué tamaños se generan en nuevas subidas, compartiendo la opción con Taisa Tools. También permite borrar una sola variante registrada por ID de medio y nombre de tamaño para comprobar la presentación antes de una limpieza general. Comprueba que no existan enlaces directos a la variante antes de borrarla. El borrado requiere una copia de seguridad recuperable.

El detector de originales puede marcar archivos que exceden solo el limite de peso. Esos casos quedan identificados como pendientes de compresion y no se redimensionan automaticamente si sus dimensiones ya estan dentro del limite.

Cuando esta activada la copia de seguridad, los originales previos al redimensionado se guardan en `wp-content/uploads/taisa-image-optimizer-backups/`.

## Actualizaciones

El plugin consulta las releases de `TaisaDigital/taisa-image-optimizer`. Publica las versiones con etiquetas `vX.Y.Z`; el ZIP de la release o el archivo fuente de GitHub se ofrecerá en la pantalla normal de actualizaciones de WordPress.

## Changelog

### 0.4.1

- Corregidas las traducciones señaladas por Plugin Check.
- Versión de mantenimiento posterior a 0.4.0, compatible con las instalaciones que ya usan esa versión.

### 0.4.0

- La limpieza usa lotes de 5 adjuntos con 3 segundos entre peticiones, estado persistente y controles de pausa, reanudación y abandono.
- La reanudación exige escaneo de tamaños reciente y sin cambios en la biblioteca ni en la política de tamaños desactivados.
- El progreso comunica el cursor y los resultados acumulados; salir de la página no se presenta como cancelación de una petición PHP en curso.

### 0.3.1

- Protegidos el favicon y el icono del sitio frente a la limpieza de variantes y el redimensionado de originales.

### 0.3.0

- Añadido control de generación futura de tamaños sin sustituir instalaciones antiguas de Taisa Tools.
- Añadida limpieza individual de una variante registrada y protección adicional de la imagen original.

### 0.2.0

- Añadido actualizador desde releases de GitHub.
- Retirados los controles propios para desactivar tamaños; el estado se lee desde Taisa Tools.
- La limpieza por lotes usa la lista compartida de tamaños desactivados.
