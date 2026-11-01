# KutBlog

**KutBlog** es un gestor de contenidos moderno, ligero, modular y sin base de datos, escrito en PHP puro. Almacena todo el contenido y configuraciones en archivos estructurados (JSON y Markdown), no requiere MySQL ni PostgreSQL, e incluye un potente sistema de plugins, analíticas respetuosas con la privacidad y una experiencia editorial fluida y sin fricciones.

<img width="2250" height="1369" alt="kutblog_theme" src="https://github.com/user-attachments/assets/f7979547-cbcb-48ba-bbfb-db9e1827a6de" />

<img width="2250" height="1369" alt="kutblog_stats" src="https://github.com/user-attachments/assets/9b6d6795-b093-47bf-9bf6-185d2fad942a" />

<img width="2250" height="1369" alt="kutblog_editor" src="https://github.com/user-attachments/assets/6868791d-5a8d-4352-b30a-38eb183d702f" />

<img width="2250" height="1369" alt="kutblog_admin" src="https://github.com/user-attachments/assets/5561a532-b4ab-4579-b34c-8d4f7d887d43" />

---

## Índice

1. [Requisitos](#requisitos)
2. [Instalación](#instalación)
3. [Estructura de archivos y directorios](#estructura-de-archivos-y-directorios)
4. [Panel de administración](#panel-de-administración)
5. [Artículos, Páginas y Calendario](#artículos-páginas-y-calendario)
6. [Editor de contenido triple-modo](#editor-de-contenido-triple-modo)
7. [Biblioteca de medios y carpetas virtuales](#biblioteca-de-medios-y-carpetas-virtuales)
8. [Sistema de plugins](#sistema-de-plugins)
   - [Series de artículos](#plugin-series-de-artículos)
   - [Carpetas multimedia](#plugin-carpetas-multimedia)
   - [Newsletter y suscripciones](#plugin-newsletter)
   - [Fediverso y ActivityPub](#plugin-fediverso-activitypub)
   - [KutPod Studio (Podcasts)](#plugin-kutpod-studio)
   - [Búsqueda y reemplazo](#plugin-búsqueda-y-reemplazo)
   - [Importador de WordPress](#plugin-importador-de-wordpress)
9. [Catálogo de temas](#catálogo-de-temas)
10. [Ajustes del sitio y favicon](#ajustes-del-sitio-y-favicon)
11. [Estadísticas integradas (sin cookies)](#estadísticas-integradas-sin-cookies)
12. [Mastodon y comentarios sociales](#mastodon-y-comentarios-sociales)
13. [RSS, Sitemap y SEO](#rss-sitemap-y-seo)
14. [Exportar y respaldar](#exportar-y-respaldar)
15. [Configuración de Nginx](#configuración-de-nginx)
16. [Seguridad](#seguridad)
17. [Crear un tema personalizado](#crear-un-tema-personalizado)
18. [Estructura del contenido JSON](#estructura-del-contenido-json)
19. [Solución de problemas](#solución-de-problemas)

---

## Requisitos

- **PHP 8.0 o superior** (probado y optimizado en PHP 8.1, 8.2 y 8.3)
- **Nginx** (recomendado) o **Apache** con `mod_rewrite`
- Extensiones PHP requeridas: `json`, `SimpleXML`, `fileinfo`, `zip`, `gd` (para procesamiento de imágenes y favicon)
- Acceso de escritura a `content/`, `cache/`, `media/` y al archivo `config.json`

---

## Instalación

### 1. Subir los archivos

Sube los archivos de KutBlog al directorio raíz de tu servidor web:

```bash
/var/www/html/misitio/
```

### 2. Permisos del sistema

```bash
chown -R www-data:www-data /var/www/html/misitio/
chmod -R 755 /var/www/html/misitio/
chmod 775 /var/www/html/misitio/content/
chmod 775 /var/www/html/misitio/cache/
chmod 775 /var/www/html/misitio/media/
touch /var/www/html/misitio/config.json
chown www-data:www-data /var/www/html/misitio/config.json
chmod 664 /var/www/html/misitio/config.json
```

### 3. Configurar Nginx

Utiliza la plantilla `nginx.conf.example` o `systeminside.conf` incluida. Ajusta tu dominio y la ruta al socket de PHP-FPM:

```nginx
server_name tudominio.com www.tudominio.com;
root /var/www/html/misitio;
fastcgi_pass unix:/run/php/php8.3-fpm.sock;
```

Activa el sitio en tu servidor:

```bash
cp nginx.conf.example /etc/nginx/sites-available/tudominio.conf
nano /etc/nginx/sites-available/tudominio.conf
ln -sf /etc/nginx/sites-available/tudominio.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 4. Certificado SSL con Let's Encrypt

```bash
certbot certonly --nginx -d tudominio.com -d www.tudominio.com
```

### 5. Instalador web

Abre `https://tudominio.com/install/` en el navegador y completa:
- **Título del sitio:** Nombre de tu publicación o bitácora.
- **URL base:** Detectada automáticamente (ej. `https://tudominio.com`).
- **Usuario administrador:** Nombre de login.
- **Contraseña:** Mínimo 8 caracteres, mayúscula y número.

El instalador genera `config.json`, la estructura base y crea el archivo `.installed` que bloquea permanentemente el instalador por seguridad.

---

## Estructura de archivos y directorios

```
/
├── index.php                 ← Enrutador público principal (Home, artículos, páginas, series, podcast)
├── config.json               ← Configuración global y plugins activos
├── .installed                ← Bloqueo de seguridad del instalador
├── rss.xml.php               ← Generador del feed RSS 2.0
├── sitemap.xml.php           ← Generador del sitemap XML dinámico
├── favicon.ico / .svg        ← Favicon público con redirección dinámica
│
├── admin/                    ← Panel de control administrativo
│   ├── index.php             ← Dashboard principal con resumen y accesos rápidos
│   ├── editor.php            ← Editor de contenido triple-modo (WYSIWYG / RAW / Markdown)
│   ├── articles.php          ← Listado, filtrado y gestión de artículos
│   ├── pages.php             ← Listado y gestión de páginas estáticas
│   ├── calendar.php          ← Calendario editorial interactivo con programación de posts
│   ├── media.php             ← Biblioteca multimedia con árbol de carpetas virtuales y drag & drop
│   ├── analytics.php         ← Estadísticas de visitas, dispositivos, países y fuentes
│   ├── trash.php             ← Papelera de reciclaje y recuperación de contenidos
│   ├── plugins.php           ← Gestor y configuración de plugins
│   ├── themes.php            ← Selector y personalización de temas
│   ├── settings.php          ← Ajustes generales, apariencia, favicon y perfiles
│   ├── users.php             ← Gestión de credenciales de usuario
│   ├── autosave.php          ← Endpoint de autoguardado en segundo plano
│   └── export.php            ← Copias de seguridad completas en ZIP
│
├── plugins/                  ← Sistema de plugins desacoplados
│   ├── series/               ← Series de artículos, entregas ordenadas y páginas dedicadas
│   ├── media-folders/        ← Carpetas y taxonomía virtual para la biblioteca de medios
│   ├── newsletter/           ← Suscripciones por email con doble opt-in y boletines
│   ├── fediverse/            ← Nodo nativo de ActivityPub para el Fediverso (Mastodon)
│   ├── kutpod/               ← Integración oficial con podcasts de KutPod Studio
│   ├── search-replace/       ← Búsqueda y reemplazo masivo con simulación
│   └── wp-import/            ← Importador de archivos WXR (.xml) de WordPress
│
├── core/                     ← Núcleo del sistema (bloqueado al acceso web)
│   ├── config.php            ← Carga de ajustes, helpers de lenguaje y plugins
│   ├── auth.php              ← Sesiones seguras, CSRF, control de acceso y Argon2id
│   ├── content.php           ← CRUD de artículos, páginas y caché de índices
│   ├── markdown.php          ← Parser Markdown nativo PHP de alta velocidad
│   └── theme.php             ← Motor de plantillas, inyección de estilos y hooks
│
├── content/                  ← TUS DATOS (Directorio prioritario de respaldo)
│   ├── articles/             ← Archivos JSON de cada artículo
│   ├── pages/                ← Archivos JSON de cada página
│   ├── users/                ← Usuarios y contraseñas hasheadas
│   ├── cache/                ← Caché temporal de consultas, índices y Mastodon
│   ├── newsletter/           ← Suscriptores y registros del boletín
│   ├── series.json           ← Metadatos de colecciones y series de artículos
│   ├── media_folders.json    ← Estructura de carpetas virtuales de multimedia
│   └── fediverse.db          ← Base de datos SQLite ligera para seguidores y actores
│
├── media/                    ← Archivos multimedia subidos (/media/YYYY/MM/)
│
├── themes/                   ← Temas visuales listos para usar
│   ├── default/              ← Tema claro editorial en cuadrícula
│   ├── dark/                 ← Tema oscuro moderno en cuadrícula
│   ├── minimal/              ← Minimalista amplio, thumbnail-first con modo claro/oscuro
│   ├── minimal-column/       ← Diseño a una sola columna, tipografía cuidada y lectura inmersiva
│   ├── calendar/             ← 2 columnas con timeline y calendario interactivo al scroll
│   ├── systeminside/         ← Cabecera oscura, barra lateral clásica y tipografía condensada
│   ├── systeminside-dark/    ← Versión 100% oscura de SystemInside para lectura nocturna
│   └── systeminside-light/   ← Versión 100% clara de SystemInside
│
└── api/                      ← Endpoints públicos ligeros
    ├── mastodon.php          ← Proxy de comentarios y respuestas de Mastodon
    ├── track.php             ← Beacon de analíticas privadas sin cookies
    ├── subscribe.php         ← Suscripción a newsletter y confirmación opt-in
    └── img.php               ← Optimización dinámica y caché de imágenes
```

---

## Panel de administración

Acceso: `https://tudominio.com/admin/`

- **Barra lateral responsiva:** Modo colapsado a iconos en escritorio (con tooltips instantáneos) y menú deslizante (*drawer*) en dispositivos móviles.
- **Esquemas de color del panel:** Selector en la barra superior con 4 paletas: **Dark** (predeterminada), **Midnight** (azul noche profundo), **Slate** (gris pizarra) y **Warm** (tonos cálidos marrones).
- **Notificaciones y atajos:** Indicadores de estado de guardado, acceso directo al blog público y perfil.

---

## Artículos, Páginas y Calendario

### Tipos de contenido
- **Artículos:** Publicaciones periódicas organizadas por fecha, categorías, etiquetas, series y comentarios del Fediverso. Alimentan la portada, el feed RSS y el sitemap.
- **Páginas:** Contenido estático permanente (Acerca de, Contacto, Proyectos) integrado de forma automática en el menú de navegación de los temas.

### Rutas URL amigables
| Vista | Formato de URL |
|---|---|
| Portada del blog | `tudominio.com/` o `tudominio.com/blog` |
| Artículo | `tudominio.com/article/el-slug` |
| Página estática | `tudominio.com/page/el-slug` |
| Catálogo de Series | `tudominio.com/series` |
| Serie específica | `tudominio.com/series/nombre-serie` |
| Directorio de Podcast | `tudominio.com/podcasts` (con plugin KutPod) |
| Archivo por Categoría | `tudominio.com/category/nombre` |
| Archivo por Etiqueta | `tudominio.com/tag/nombre` |
| Búsqueda | `tudominio.com/search?q=termino` |
| Feed RSS | `tudominio.com/rss.xml` o `tudominio.com/feed` |
| Sitemap XML | `tudominio.com/sitemap.xml` |

### Programación de publicaciones y Calendario
- **Publicación programada:** Selecciona una fecha y hora futura en el editor para que el artículo pase a estado `scheduled`. Se publicará automáticamente al alcanzarse la fecha.
- **Vista de Calendario (`/admin/calendar.php`):** Cuadrícula mensual con el desglose de artículos publicados, programados y borradores por día, con navegación entre meses y botón rápido `+` para programar un post en una fecha específica.

---

## Editor de contenido triple-modo

El editor de KutBlog combina la sencillez de redacción con herramientas profesionales:

### 1. Tres modos de edición
- **WYSIWYG visual:** Motor enriquecido (basado en TipTap/ProseMirror) con renderizado tipográfico en tiempo real.
- **HTML Raw:** Edición directa del código HTML para control absoluto del marcado.
- **Markdown:** Redacción ágil en Markdown nativo con vista previa en vivo y conversión bidireccional.

### 2. Barra flotante contextual de imagen
Al hacer clic sobre cualquier imagen insertada en el cuerpo del artículo, aparece una barra flotante con:
- **Alineación:** Flotación a la izquierda con texto, centrado, flotación a la derecha o ancho completo (`100%`).
- **Tamaños predefinidos:** `25%`, `50%`, `75%` y `100%`.
- **Tamaño personalizado:** Control numérico para especificar dimensiones exactas (ej. `450px`, `60%`).
- **Texto alternativo (ALT):** Edición rápida del atributo `alt` para accesibilidad y SEO.
- **Eliminación:** Botón directo para retirar la imagen y limpiar el nodo de inmediato.

### 3. Comandos de barra inclinada (`/` Slash Commands)
Escribe `/` en cualquier línea vacía del editor para desplegar el menú de inserción rápida de encabezados (H2, H3), listas con viñetas o numeradas, bloques de código, citas o imágenes.

### 4. Barra flotante de selección de texto
Al seleccionar cualquier fragmento de texto con el cursor o el teclado, se activa un menú flotante con herramientas de formato (negrita, cursiva, subrayado, tachado, código inline, enlaces y resaltado).

### 5. Autoguardado dual
- **Copia local preventiva:** Guarda borradores en `localStorage` cada segundo mientras escribes para prevenir pérdidas por cierre accidental del navegador.
- **Sincronización en servidor:** Envía deltas automáticos a `/admin/autosave.php` manteniendo tu borrador al día sin necesidad de pulsar guardar constantemente.

### 6. Modo sin distracciones
Presiona **F11** o el botón de pantalla completa en la barra de herramientas para ocultar los paneles laterales y concentrarte únicamente en escribir. Pulsa **Escape** para salir.

---

## Biblioteca de medios y carpetas virtuales

La gestión de medios en `/admin/media.php` ofrece una experiencia fluida tipo explorador de archivos:

- **Carpetas y subcarpetas virtuales (Plugin Carpetas Multimedia):** Organiza tus fotografías e ilustraciones en una jerarquía ilimitada de carpetas.
- **URLs 100% estables:** La clasificación se realiza mediante metadatos en `content/media_folders.json`. Mover o reorganizar archivos entre carpetas **nunca rompe las URLs públicas** ni causa errores 404 en posts antiguos.
- **Arrastrar y soltar (Drag & Drop):** Arrastra una o varias imágenes directamente sobre una carpeta del panel lateral para moverlas al instante.
- **Subida directa con destino:** Selector de carpeta para ubicar tus archivos en el momento de la carga.
- **Modal de detalles:** Edición de título, texto alternativo (alt), vista previa, copia de URL al portapapeles y reasignación de carpeta.
- **Formatos soportados:** JPG, PNG, WebP, GIF, SVG y AVIF.

---

## Sistema de plugins

KutBlog incluye una arquitectura modular de plugins desacoplados ubicados en `plugins/`. Cada plugin se puede activar o desactivar desde **Admin → Plugins**.

### Plugin: Series de artículos
- **Propósito:** Agrupa publicaciones relacionadas en cursos, tutoriales o guías secuenciales con un orden de lectura pedagógico.
- **Orden interactivo:** Reordena las entregas fácilmente mediante **Drag & Drop** o botones subir/bajar desde el panel de control.
- **Inyección automática en el frontend:**
  - **Banner superior:** Informa al lector: *«Parte X de Y en la serie [Nombre]»*.
  - **Caja de navegación al pie:** Botones interactivos **«← Entrega anterior»** y **«Siguiente entrega →»**, más un índice desplegable con indicador visual de *«Leyendo ahora»*.
- **Páginas dedicadas:** Catálogo general de series en `/series` y vista detallada de la serie en `/series/{slug}` con estadísticas y tiempo estimado de lectura.

### Plugin: Carpetas multimedia
- Organización avanzada de la biblioteca mediante carpetas virtuales, contadores en tiempo real y asignación durante la subida o desde el editor.

### Plugin: Newsletter
- **Captura de suscriptores:** Widget responsive incrustado automáticamente al final de los artículos.
- **Doble confirmación (Opt-in):** Envío de correo con enlace de verificación para cumplir con normativas de privacidad (GDPR).
- **Gestor en el panel:** Listado de suscriptores activos, estado de verificación, exportación y envío de publicaciones por correo vía SMTP o `mail()`.

### Plugin: Fediverso (ActivityPub)
- Convierte tu blog en un actor nativo del Fediverso.
- Permite que usuarios de **Mastodon, Firefish, Pleroma o Pixelfed** sigan tu blog directamente (`@usuario@tudominio.com`), reciban tus nuevos artículos en su timeline e interactúen con ellos.

### Plugin: KutPod Studio
- Integración oficial con la plataforma de podcasting KutPod.
- Sincronización automática de episodios por webhooks, reproductor de audio embebido, timestamps cliqueables, transcripciones optimizadas para SEO y directorio público en `/podcasts`.

### Plugin: Búsqueda y reemplazo
- Herramienta para actualizar URLs, nombres o cadenas de texto en masa a través de todos los artículos y páginas del blog.
- **Modo simulación (Dry-run):** Muestra previamente cuántos archivos coinciden y qué líneas cambiarán antes de aplicar la modificación.

### Plugin: Importador de WordPress
- Migración integral desde archivos de exportación estándar WXR (`.xml`) de WordPress.
- Descarga automática de imágenes remotas a `/media/`, reescritura de URLs en el contenido, preservación de slugs, fechas originales, categorías y etiquetas.

---

## Catálogo de temas

KutBlog incluye 8 temas optimizados para lectura, velocidad y puntuación perfecta en Core Web Vitals:

| Tema | Estilo y Características | Ideal para |
|---|---|---|
| **Default** | Claro, minimalista, diseño editorial con tarjetas en cuadrícula y tipografía limpia. | Blogs personales, revistas y noticias. |
| **Dark** | Variante oscura del tema Default con alto contraste y tonalidades suaves para la vista. | Lectura nocturna y blogs tecnológicos. |
| **Minimal** | Espacioso, diseño miniatura-first (*thumbnail-first*), selector de modo claro/oscuro y lectura despejada. | Portafolios visuales, fotografía y diseño. |
| **Minimal Column** | Maquetación moderna a una sola columna centrada, mobile-first, con soporte para modo claro y oscuro. | Artículos de opinión, ensayos y narrativa. |
| **Calendar** | Doble columna con línea de tiempo interactiva sincronizada al scroll y widget de calendario. | Bitácoras cronológicas, diarios y bitácoras de viaje. |
| **SystemInside** | Cabecera oscura distintiva, distribución con barra lateral derecha y tipografía Roboto Condensed. | Sitios de tecnología, tutoriales y Linux. |
| **SystemInside Dark** | Edición completamente oscura de SystemInside con paleta charcoal y acentos destacados. | Sitios técnicos y entornos de desarrollo. |
| **SystemInside Light** | Edición completamente clara de SystemInside con fondos blancos puros y excelente legibilidad. | Documentación y lectura diurna. |

Todos los temas admiten personalización del **Color de Acento** mediante CSS variables (`--accent`), soporte nativo para responsive design y favicon personalizado.

---

## Ajustes del sitio y favicon

Desde **Admin → Ajustes**:
- **General:** Título, descripción corta (tagline), URL base, artículos por página, idioma del sitio y texto del pie de página.
- **Apariencia & Favicon personalizado:**
  - Selector de tema activo y color de acento personalizado (10 presets + selector hexadecimal).
  - **Subida de Favicon:** Admite formatos `.ico`, `.png` y `.svg`. KutBlog genera automáticamente versiones optimizadas y las enlaza con las etiquetas `<link rel="icon">` correspondientes, proporcionando un favicon predeterminado en caso de no configurar uno propio.
- **Mastodon / Fediverso:** Configuración de handle `@usuario@instancia` para vincular autoría y enlace `<link rel="me">` de verificación de perfil.
- **Seguridad:** Modificación de contraseña y usuario administrador con verificación de credenciales actuales.

---

## Estadísticas integradas (sin cookies)

KutBlog incluye un motor nativo de estadísticas en `/admin/analytics.php` diseñado para respetar la privacidad del visitante y cumplir con el GDPR sin necesidad de banners de cookies:

- **Sin cookies ni rastreadores:** Utiliza un hash anónimo unidireccional diario generado a partir de la IP y el User-Agent.
- **Métricas registradas:**
  - Páginas vistas totales y visitantes únicos diarios.
  - Artículos y páginas más leídos.
  - Fuentes de tráfico y procedencia (*referrers* directos, buscadores y redes sociales).
  - Tipos de dispositivo (escritorio, móvil, tablet).
  - Sistemas operativos y navegadores más utilizados.
- **Rendimiento:** Registro ultrarrápido vía beacon asíncrono (`/api/track.php`) que no penaliza el tiempo de carga de las páginas.

---

## Mastodon y comentarios sociales

KutBlog permite convertir las respuestas de Mastodon en el sistema de comentarios nativo de tus artículos sin bases de datos adicionales:

1. Publica tu artículo en el blog.
2. Comparte el enlace en tu cuenta de Mastodon.
3. Copia la URL del toot publicado (ej. `https://mastodon.social/@usuario/112233445566`).
4. Pega la URL en el campo **«URL de Mastodon»** en la barra lateral del editor.
5. El artículo cargará automáticamente las respuestas, avatares y respuestas anidadas con caché inteligente de 3 minutos.

---

## RSS, Sitemap y SEO

- **Feed RSS 2.0:** Disponible en `/rss.xml` y `/feed`. Incluye contenido completo o extracto, imágenes destacadas y metadatos del autor.
- **Sitemap XML:** Generado automáticamente en `/sitemap.xml` indexando todos los artículos, páginas y colecciones de series publicados.
- **Robots.txt:** Servido dinámicamente en `/robots.txt` con la URL canónica del sitemap.
- **Open Graph y Twitter Cards:** Etiquetas `og:title`, `og:image`, `og:description`, `og:type` y canonicals generadas de forma automática en todos los artículos.

---

## Exportar y respaldar

### Copia de seguridad en un clic
Desde **Admin → Exportar**: descarga un archivo ZIP con todos tus artículos, páginas, configuración (sin contraseñas) y opcionalmente todo el directorio de medios (`/media/`).

### Respaldo manual rápido
Para migrar o respaldar tu blog manualmente, solo requieres copiar:
```bash
content/      # Artículos, páginas, metadatos y series
media/        # Todas las imágenes y archivos subidos
config.json   # Ajustes del sitio
```

---

## Configuración de Nginx

Configuración de producción recomendada con soporte SSL y protección de directorios:

```nginx
server {
    listen 80;
    server_name tudominio.com www.tudominio.com;
    return 301 https://tudominio.com$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tudominio.com;
    root /var/www/html/misitio;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/tudominio.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tudominio.com/privkey.pem;

    # Enrutamiento principal
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Bloqueo estricto de directorios internos
    location ~ ^/(core|content|cache)/ {
        deny all;
        return 404;
    }

    # Prohibir ejecución de PHP en uploads y media
    location ~* ^/(media|uploads)/.*\.php$ {
        deny all;
        return 403;
    }

    # Caché estática de assets
    location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|avif)$ {
        expires 1y;
        add_header Cache-Control "public, no-transform";
    }

    # Procesamiento de PHP-FPM
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_intercept_errors on;
    }
}
```

---

## Seguridad

| Medida | Implementación |
|---|---|
| **Almacenamiento de contraseñas** | Algoritmo criptográfico **Argon2id**. |
| **Doble Factor de Autenticación (2FA)** | Códigos TOTP compatibles con Google Authenticator, Aegis, Bitwarden y códigos de recuperación de un solo uso. |
| **Sesiones persistentes (Recordarme)** | Tokens criptográficos divididos (selector + validador sha256 con rotación automática), ventana deslizante de inactividad y gestión de dispositivos de confianza. |
| **Protección contra CSRF** | Tokens criptográficos en todos los formularios de acción del panel. |
| **Prevención de fuerza bruta** | Bloqueo temporal por IP tras 5 intentos consecutivos fallidos. |
| **Seguridad en Cookies de sesión** | Flags `HttpOnly`, `SameSite=Lax/Strict`, regeneración periódica y `Secure` en HTTPS. |
| **Protección de subida de archivos** | Validación MIME en servidor y prohibición de ejecución de scripts en `/media/`. |
| **Cabeceras HTTP de seguridad** | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, y saneamiento de esquemas `file:///`. |

---

## Crear un tema personalizado

Para crear un nuevo tema, crea una carpeta en `themes/mi-tema/` con los siguientes archivos:

```
themes/mi-tema/
├── theme.json    ← Metadatos obligatorios (label, description, author, version)
├── header.php    ← Apertura del documento HTML, head y navegación
├── footer.php    ← Pie de página y cierre del body
├── index.php     ← Listado de artículos y archivos
├── single.php    ← Visualización de artículo o página
├── search.php    ← Resultados de búsqueda
└── 404.php       ← Página de error no encontrado
```

### Ejemplo de theme.json
```json
{
  "label": "Mi Tema Personalizado",
  "description": "Diseño editorial adaptado a mi marca",
  "author": "Tu Nombre",
  "version": "1.0.0"
}
```

Al colocar la carpeta, KutBlog la detectará automáticamente en **Admin → Temas**.

---

## Estructura del contenido JSON

Cada artículo se almacena de forma independiente como un archivo `.json` en `content/articles/{slug}.json`:

```json
{
    "title": "Introducción a Docker",
    "slug": "introduccion-a-docker",
    "content": "<p>Contenido completo en HTML o Markdown...</p>",
    "content_format": "html",
    "excerpt": "Aprende los conceptos básicos de contenedores.",
    "status": "published",
    "categories": ["Desarrollo", "DevOps"],
    "tags": ["docker", "contenedores", "linux"],
    "featured_image": "/media/2026/10/docker-banner.jpg",
    "mastodon_url": "https://mastodon.social/@usuario/112233445566",
    "series_id": "ser_docker",
    "created_at": "2026-10-31T18:00:00-05:00",
    "updated_at": "2026-11-01T09:30:00-05:00"
}
```

---

## Solución de problemas

### Error 500 al instalar o abrir el sitio
Comprueba los permisos de escritura en `content/`, `cache/` y `config.json`. Verifica el registro de errores de tu servidor web:
```bash
tail -n 50 /var/log/nginx/error.log
```

### Las imágenes importadas no cargan
Si realizaste una importación desde WordPress sin descargar imágenes, dirígete a **Admin → Herramientas → Importar imágenes** para bajarlas al servidor local y reescribir sus referencias.

### Redirección incorrecta tras cambiar de dominio
Si modificaste el dominio o el protocolo (HTTP a HTTPS) y no puedes iniciar sesión en el panel, edita directamente `config.json` en la raíz de tu instalación y actualiza la clave `"base_url"`.

---

*KutBlog — Creado para escribir, diseñado para durar.*
