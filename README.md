# AVF Broken Link Manager 🔗

[![WordPress Version](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777BB4.svg)](https://php.net)
[![Plugin Version](https://img.shields.io/badge/Version-1.5.0-green.svg)](#historial-de-cambios-changelog)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

**AVF Broken Link Manager** es un plugin profesional y de alto rendimiento para WordPress diseñado para auditar, detectar y solucionar enlaces rotos (404, 500, timeouts) a lo largo de todo el sitio mediante procesamiento por lotes (*batch processing*) con soporte para **WPML, Polylang, WP-Cron, exportación CSV, Widget para el Escritorio y redirecciones 301** con auditoría en archivo plano.

---

## 🚀 Características Principales

### 🔍 Motor de Escaneo Asíncrono por Lotes (Batch Engine)
* **Consumo de Memoria Constante $O(1)$:** Utiliza paginación por cursor de ID (`avf_blm_last_id`) para procesar las entradas en lotes de 10 en 10. Evita *timeouts* de PHP y saturación de RAM incluso en sitios con +50,000 publicaciones.
* **Soporte para Maquetadores Visuales y Shortcodes:** Expande shortcodes nativos (`do_shortcode`) de maquetadores como Elementor, Divi o WPBakery antes de analizar el HTML, detectando enlaces ocultos en botones y componentes visuales.
* **Extracción HTML Segura:** Procesa el contenido usando PHP `DOMDocument` wrapped en `libxml_use_internal_errors(true)` con inyección de meta etiquetas UTF-8, garantizando compatibilidad con PHP 8.2+ y soporte para caracteres especiales (acentos, eñes, emojis).
* **Filtrado de Protocolos Especiales:** Omite automáticamente esquemas no-web (`mailto:`, `tel:`, `javascript:`, `#`, `whatsapp:`, `skype:`, `data:`).

### 🌐 Verificación HTTP con Doble Etapa
* **API HTTP Nativa de WordPress:** Utiliza `wp_remote_head()` para verificaciones ultra rápidas y realiza un *fallback* automático a `wp_remote_get()` si el servidor remoto no soporta el método HEAD.
* **Timeout Estricto:** Configurado a 5 segundos de espera máxima para no ralentizar el proceso.
* **Doble User-Agent Anti-WAF:** Envía una cabecera de navegador real compatible con reglas de cortafuegos (Cloudflare, ModSecurity) para evitar falsos positivos `403 Forbidden`.

### 🌍 Soporte Multilingüe Integrado (WPML & Polylang)
* **Detección Automática de Idioma:** Identifica el idioma asociado a cada entrada mediante las APIs nativas de **WPML** (`wpml_post_language_details`) y **Polylang** (`pll_get_post_language`).
* **Etiquetado Visual:** Muestra insignias de idioma (`[ES]`, `[EN]`, `[FR]`, etc.) en las tablas del panel de administración y en los reportes CSV exportados.
* **Rutas Base Multilingües:** Resuelve URLs relativas utilizando `pll_home_url()` o `wpml_home_url`.

### 🔀 Sistema de Redirecciones 301 Inteligente
* **Redirección Automática Frontend:** Intercepta solicitudes mediante el hook `template_redirect` aplicando redirecciones `301 Moved Permanently`.
* **Coincidencia Agnóstica de Protocolo:** Coincide con reglas creadas en `http://` aunque el sitio se navegue en `https://`.
* **Preservación de Parámetros de Analítica:** Mantiene todos los *query strings* entrantes (`UTMs`, `gclid`, `fbclid`) y los concatena a la URL de destino.
* **Prevención de Bucles Infinitos y Cadenas:** Impide crear redirecciones circulares o hacia la misma URL de origen (`source_url === target_url`).
* **Mapeo Multilingüe:** Descompone prefijos de idioma (`/es/`, `/en/`) y resuelve rutas relativas respetando la estructura del sitio.

### ⏰ Escaneo Automático en Segundo Plano (WP-Cron)
* **Programación Personalizable:** Elige frecuencias de escaneo **Diario**, **Semanal**, **Mensual** o mantenlo en modo **Manual**.
* **Protección contra Condiciones de Carrera (*Race Lock*):** Bloqueo mediante transitorios temporales que evita ejecuciones concurrentes simultáneas.
* **Alertas por Correo Electrónico:** Envía un informe automático al correo del administrador (`admin_email`) cuando se detecten nuevos enlaces caídos.

### 📊 Dashboard Widget y Panel de Control Interactivo
* **Widget de Escritorio (`wp-admin`):** Tarjeta de resumen en la pantalla principal de WordPress que muestra contadores en vivo de enlaces rotos, redirecciones activas, fecha del último escaneo y un acceso directo de un solo clic.
* **Pestañas Interactivas AJAX:** Gestión completa de enlaces rotos e historial de redirecciones con actualización de contadores en tiempo real sin recargar la página.

### 📥 Exportador CSV Seguro y por Lotes
* **Descarga Streaming por Lotes:** Genera reportes CSV en bloques de 500 registros para evitar agotar la memoria del servidor en bases de datos masivas.
* **BOM UTF-8 para Excel:** Incluye marca de orden de bytes binarios (`\xEF\xBB\xBF`) para una correcta visualización de caracteres en Microsoft Excel.
* **Protección contra Inyección de Fórmulas:** Neutraliza celdas que inicien con `=`, `+`, `-` o `@` (`sanitize_csv_field`) para evitar ejecución de comandos en hojas de cálculo.

### 📝 Auditoría de Logs de Redirección en Archivo Plano
* **Registro en Tiempo Real:** Almacena fecha, hora, URL origen, URL destino, dirección IP y User-Agent en `wp-content/uploads/avf-blm-logs/redirects.log`.
* **Blindaje de Seguridad:** Protegido contra acceso directo desde el navegador mediante `.htaccess` (`Deny from all`) e `index.php`.
* **Rotación Automática:** Archiva el log automáticamente al alcanzar un tamaño de **5 MB** (`.log.bak`).
* **Lectura Inversa Instantánea ($O(1)$):** Utiliza lectura por puntero `fseek` desde el final del archivo (*Chunked Tail Reader*) para renderizar las últimas 50 líneas en el visor del panel de control de forma inmediata.

---

## 🛠️ Requisitos Técnicos

* **WordPress:** 5.8 o superior
* **PHP:** 7.4 u 8.0+ (100% compatible con PHP 8.2+)
* **Extensiones PHP:** `libxml`, `dom`, `mbstring`, `json`, `cURL` (vía WP HTTP API)
* **Base de Datos:** MySQL 5.7+ / MariaDB 10.2+

---

## 📦 Instalación

1. Descarga el archivo fuente del plugin o clona este repositorio:
   ```bash
   git clone https://github.com/tu-usuario/avf-broken-link-manager.git
   ```
2. Copia la carpeta `avf-broken-link-manager` dentro del directorio de plugins de WordPress:
   ```text
   wp-content/plugins/avf-broken-link-manager/
   ```
3. Accede al panel de administración de WordPress > **Plugins** y haz clic en **Activar**.
4. Ve a **Herramientas > AVF Link Manager** para iniciar tu primer escaneo o configurar las tareas automáticas.

---

## 📂 Estructura del Proyecto

```text
avf-broken-link-manager/
├── avf-broken-link-manager.php  # Código fuente principal e íntegro del plugin (v1.5.0)
├── README.md                    # Documentación pública del repositorio
└── LICENSE                      # Licencia MIT
```

---

## 🔄 Historial de Cambios (Changelog)

### Version 1.5.0
* **Nuevo:** Integración nativa con plugins multilingües **WPML** y **Polylang**.
* **Nuevo:** Inclusión de insignias de idioma (`[ES]`, `[EN]`) en las tablas del panel y exportaciones CSV.
* **Mejora:** Extracción de URLs base multilingües mediante `pll_home_url()` y `wpml_home_url`.
* **Mejora:** Búsqueda avanzada de rutas relativas con o sin prefijos de idioma en el motor de redirecciones 301.
* **Corrección:** Inyección de marca de orden de bytes binarios UTF-8 (`ï»¿`) en exportación CSV.

### Version 1.4.0
* **Nuevo:** Sistema de auditoría y logs en archivo plano (`redirects.log`).
* **Nuevo:** Protección de directorio de logs con `.htaccess` e `index.php`.
* **Nuevo:** Rotación automática de logs al alcanzar los 5 MB (`.bak`).
* **Nuevo:** Visor de logs en tiempo real con lectura inversa por bloques `fseek` ($O(1)$ RAM).
* **Seguridad:** Sanitización de campos CSV contra inyección de fórmulas de hojas de cálculo (`sanitize_csv_field`).

### Version 1.3.0
* **Nuevo:** Expansión de shortcodes de maquetadores visuales (`do_shortcode`) antes del análisis DOM (Elementor, Divi, WPBakery).
* **Nuevo:** Bloqueo de condición de carrera (*Race Lock*) en ejecuciones paralelas de WP-Cron.
* **Mejora:** Compatibilidad con entornos hosting con usuarios MySQL restringidos (reemplazo de `TRUNCATE` por `DELETE FROM`).
* **Mejora:** User-Agent dual para evitar bloqueos por cortafuegos y reglas WAF de Cloudflare.

### Version 1.2.1
* **Corrección:** Paginación por cursor de ID en la tarea programada `run_cron_scan()` para evitar desbordamiento de memoria.
* **Corrección:** Limpieza automática del hook programado en WP-Cron al desactivar el plugin (`wp_clear_scheduled_hook`).
* **Corrección:** Formateo dinámico de placeholders `%s` en consultas SQL preparadas.
* **Mejora:** Recorte estricto por número de caracteres (`mb_strimwidth`) en el Widget del Escritorio.

### Version 1.2.0
* **Nuevo:** Widget de resumen para el Escritorio de WordPress (`wp_dashboard_setup`).
* **Mejora:** Vista previa de los últimos enlaces rotos detectados desde el Dashboard.

### Version 1.1.0
* **Nuevo:** Automatización de escaneos periódicos mediante **WP-Cron** (Diario, Semanal, Mensual).
* **Nuevo:** Envío de correos electrónicos de alerta al detectar nuevos enlaces caídos.
* **Nuevo:** Módulo de exportación de registros a formato **CSV** con cabecera BOM UTF-8.

### Version 1.0.2
* **Corrección:** Migración de la cola de escaneo AJAX a paginación por cursor de ID (`WHERE ID > %d ORDER BY ID ASC LIMIT 10`).
* **Corrección:** Adición de índice compuesto `KEY post_url (post_id, url(191))` en la base de datos.
* **Corrección:** Reemplazo de `mb_convert_encoding('HTML-ENTITIES')` para compatibilidad completa con PHP 8.2+.
* **Corrección:** Prevención de bucles infinitos de redirección 301 hacia la misma URL de origen.

### Version 1.0.0
* Lanzamiento inicial con motor de escaneo por lotes AJAX, tablas personalizadas en MySQL y sistema de redirección 301 mediante `template_redirect`.

---

## 📄 Licencia

Este proyecto está bajo la Licencia [MIT](LICENSE). Puedes usarlo, modificarlo, redistribuirlo y sublicenciarlo libremente tanto en proyectos personales como comerciales.

---

**Desarrollado por AVFDigital**
