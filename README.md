# KutBlog

<p align="center">
  <strong>🇬🇧 <a href="#english">English</a></strong> · <strong>🇪🇸 <a href="#español">Español</a></strong>
</p>

<p align="center">
  <em>A modern, lightweight, modular, and database-free (flat-file) content management system written in pure PHP with JSON + Markdown storage.</em>
</p>

<img width="2250" height="1369" alt="kutblog_theme" src="https://github.com/user-attachments/assets/f7979547-cbcb-48ba-bbfb-db9e1827a6de" />

<img width="2250" height="1369" alt="kutblog_stats" src="https://github.com/user-attachments/assets/9b6d6795-b093-47bf-9bf6-185d2fad942a" />

<img width="2250" height="1369" alt="kutblog_editor" src="https://github.com/user-attachments/assets/6868791d-5a8d-4352-b30a-38eb183d702f" />

<img width="2250" height="1369" alt="kutblog_admin" src="https://github.com/user-attachments/assets/5561a532-b4ab-4579-b34c-8d4f7d887d43" />

---

<a name="english"></a>
## 🇬🇧 English

**KutBlog** is a modern, modular, flat-file content management system written in pure PHP. It stores all articles, pages, and configurations in structured files (JSON and Markdown) without requiring MySQL, MariaDB, or PostgreSQL. It features a powerful plugin ecosystem, cookie-free privacy-respecting analytics, and a frictionless editorial writing desk.

### Table of Contents

1. [Requirements](#requirements-en)
2. [Installation](#installation-en)
3. [File and Directory Structure](#directory-structure-en)
4. [Admin Dashboard](#admin-dashboard-en)
5. [Articles, Pages, and Calendar](#articles-pages-and-calendar-en)
6. [Triple-Mode Writing Desk](#triple-mode-writing-desk-en)
7. [Media Library & Virtual Folders](#media-library-en)
8. [Plugin System](#plugin-system-en)
9. [Theme Catalog](#theme-catalog-en)
10. [Site Settings & Favicon](#site-settings-en)
11. [Built-in Analytics (No Cookies)](#analytics-en)
12. [Mastodon & Social Comments](#mastodon-comments-en)
13. [RSS, Sitemap & SEO](#seo-en)
14. [Export & Backups](#export-en)
15. [Nginx Configuration](#nginx-en)
16. [Security](#security-en)
17. [Creating a Custom Theme](#custom-theme-en)
18. [Troubleshooting](#troubleshooting-en)

---

<a name="requirements-en"></a>
### Requirements

- **PHP 8.1 or higher** (tested and optimized on PHP 8.1, 8.2, and 8.3)
- **Nginx** (recommended) or **Apache** with `mod_rewrite` enabled
- Required PHP extensions: `json`, `SimpleXML`, `fileinfo`, `zip`, `gd` (for image and favicon handling)
- Write permissions on `content/`, `cache/`, `media/`, and `config.json`

---

<a name="installation-en"></a>
### Installation

#### 1. Upload files
Upload KutBlog files to your web server document root (e.g. `/var/www/html/mysite`).

#### 2. Set Permissions
```bash
sudo chown -R www-data:www-data /var/www/html/mysite/
sudo chmod -R 755 /var/www/html/mysite/
sudo chmod 775 /var/www/html/mysite/content/
sudo chmod 775 /var/www/html/mysite/cache/
sudo chmod 775 /var/www/html/mysite/media/
sudo touch /var/www/html/mysite/config.json
sudo chown www-data:www-data /var/www/html/mysite/config.json
sudo chmod 664 /var/www/html/mysite/config.json
```

#### 3. Web Setup Wizard
Open `https://yourdomain.com/install/` in your browser and complete the fields:
- **Site Title**: The name of your blog or publication.
- **Admin Username**: Your administrator username.
- **Password**: Secure access password (minimum 8 characters with numbers and capitals).

The installer generates `config.json`, builds the initial folder structure, and writes a `.installed` lock file to secure your server.

---

<a name="directory-structure-en"></a>
### File and Directory Structure

```
/
├── index.php                 ← Main public router (Home, articles, pages, series, podcasts)
├── config.json               ← Global configuration and active plugins
├── .installed                ← Security lock file created after installation
├── rss.xml.php               ← Dynamic RSS 2.0 feed generator
├── sitemap.xml.php           ← Dynamic XML Sitemap generator
│
├── admin/                    ← Control panel
│   ├── editor.php            ← Triple-mode writing desk (WYSIWYG / RAW / Markdown)
│   ├── articles.php          ← Article manager and filtering
│   ├── pages.php             ← Static page manager
│   ├── calendar.php          ← Interactive editorial calendar
│   ├── media.php             ← Media library with virtual folders and drag & drop
│   ├── analytics.php         ← Cookie-free audience analytics
│   ├── plugins.php           ← Plugin manager
│   └── themes.php            ← Theme switcher and customizer
│
├── plugins/                  ← Decoupled plugin modules
│   ├── series/               ← Ordered article series and course curriculums
│   ├── media-folders/        ← Virtual folder taxonomy for media assets
│   ├── newsletter/           ← Email subscriber capture and double opt-in
│   ├── fediverse/            ← Native ActivityPub node for Mastodon & Fediverse
│   ├── kutpod/               ← Official integration with KutPod podcast studio
│   ├── search-replace/       ← Batch search and replace with dry-run simulation
│   └── wp-import/            ← Full WordPress WXR (.xml) content importer
│
├── core/                     ← Core engine (blocked from public web access)
├── content/                  ← USER DATA (Articles, pages, series, users, media metadata)
├── media/                    ← Uploaded media files (/media/YYYY/MM/)
└── themes/                   ← Responsive themes ready out of the box
```

---

<a name="admin-dashboard-en"></a>
### Admin Dashboard

Access via: `https://yourdomain.com/admin/`

- **Responsive Sidebar**: Collapsible icon rail on desktop with instant tooltips, and a touch-friendly slide-over drawer on mobile devices.
- **Theme Color Schemes**: 4 distinct admin color palettes (**Dark**, **Midnight**, **Slate**, and **Warm**).
- **Fast Navigation & At-a-Glance Stats**: Word counters, reading time estimates, quick links to public articles, and draft status pills.

---

<a name="articles-pages-and-calendar-en"></a>
### Articles, Pages, and Calendar

- **Articles**: Time-stamped posts with categories, tags, series associations, featured images, and Fediverse comments.
- **Pages**: Permanent static pages (About, Contact, Projects) automatically integrated into navigation menus.
- **Editorial Calendar (`/admin/calendar.php`)**: Visual monthly grid showing scheduled, published, and draft posts by day, with month navigation and quick-add buttons.

---

<a name="triple-mode-writing-desk-en"></a>
### Triple-Mode Writing Desk

1. **Three Editing Modes**:
   - **Visual WYSIWYG**: TipTap/ProseMirror engine with typography rendering.
   - **Raw HTML**: Direct source code editing for custom layouts and embeds.
   - **Markdown**: Clean Markdown authoring with live preview.
2. **Contextual Floating Image Toolbar**: Click any inline image to adjust alignment (left float, center, right float, full width), preset sizes (25%, 50%, 75%, 100%), custom pixel/percent dimensions, and edit ALT attributes.
3. **Slash Commands (`/`)**: Type `/` on any blank line to insert headings (H2, H3), blockquotes, callout notes, code blocks, lists, or tables.
4. **Dual Autosave Engine**: Instant local saving via `localStorage` paired with periodic server synchronization to prevent any data loss.
5. **Distraction-Free Mode**: Press **F11** or the full-screen toolbar button to hide all chrome and write in complete focus. Press **Escape** to exit.

---

<a name="media-library-en"></a>
### Media Library & Virtual Folders

- **Virtual Folder Tree**: Organize assets into unlimited nested folders using metadata in `content/media_folders.json`.
- **100% Stable URLs**: Moving an image between virtual folders **never changes its public URL** and never breaks images in older published articles.
- **Drag & Drop**: Drag files directly onto folder nodes in the sidebar.
- **Supported Formats**: JPG, PNG, WebP, GIF, SVG, and AVIF.

---

<a name="plugin-system-en"></a>
### Plugin System

KutBlog features a lightweight, hook-based plugin architecture. Plugins can be enabled or configured with a single click from **Admin → Plugins**:

- **Series**: Group articles into courses, tutorials, or multi-part guides with forward/backward navigation and dedicated pages at `/series/{slug}`.
- **Media Folders**: Adds virtual folder hierarchies and drag & drop sorting to the media library.
- **Newsletter**: GDPR-compliant double opt-in subscription box and subscriber export.
- **Fediverse (ActivityPub)**: Turns your blog into a native Fediverse actor so users on Mastodon, Misskey, or Pixelfed can follow your blog directly (`@user@yourdomain.com`).
- **KutPod Studio**: Official bridge for embedding podcasts hosted on KutPod, featuring custom players, timestamps, and episode directories.
- **Search & Replace**: Global search and replace tool across all posts with a dry-run preview before applying changes.
- **WordPress Importer**: Full migration tool for importing WordPress WXR (`.xml`) export files, downloading images locally, and preserving slugs, tags, and categories.

---

<a name="theme-catalog-en"></a>
### Theme Catalog

KutBlog includes 8 lightweight, SEO-optimized responsive themes:

| Theme | Style & Features | Best For |
|---|---|---|
| **Default** | Clean editorial grid cards with crisp typography. | Personal blogs, magazines, and news. |
| **Dark** | High-contrast dark mode counterpart to Default. | Tech blogs and night-time reading. |
| **Minimal** | Generous spacing, thumbnail-first layout, and light/dark toggle. | Visual portfolios and photography. |
| **Minimal Column** | Single centered reading column with immaculate typography. | Essays, opinion pieces, and storytelling. |
| **Calendar** | Two-column layout with a scroll-synced interactive timeline. | Chronological journals and travel logs. |
| **SystemInside** | Dark header bar, classic right sidebar, and condensed font. | Linux tutorials, software, and tech publications. |
| **SystemInside Dark** | Full charcoal dark variant of SystemInside. | Developer blogs and dark mode enthusiasts. |
| **SystemInside Light** | Pure white clean variant of SystemInside. | Technical documentation and daytime reading. |

---

<a name="analytics-en"></a>
### Built-in Analytics (No Cookies)

- **GDPR & Privacy Compliant**: Zero cookies, zero local storage trackers, and zero fingerprinting.
- **Metrics**: Total views, daily unique visitors, top articles, referrers (search engines, direct, social), and operating system / browser breakdowns.
- **Asynchronous Beacon**: Tracks page visits asynchronously via `/api/track.php` with zero impact on page load times.

---

<a name="nginx-en"></a>
### Nginx Production Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    return 301 https://yourdomain.com$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /var/www/html/mysite;
    index index.php index.html;

    ssl_certificate /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    # Primary routing
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Block direct access to internal storage and core files
    location ~ ^/(core|content|cache)/ {
        deny all;
        return 404;
    }

    # Prevent PHP execution inside uploads/media
    location ~* ^/(media|uploads)/.*\.php$ {
        deny all;
        return 403;
    }

    # Static asset caching
    location ~* \.(jpg|jpeg|png|gif|webp|svg|ico|css|js|woff2?|avif)$ {
        expires 1y;
        add_header Cache-Control "public, no-transform";
    }

    # PHP-FPM execution
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_intercept_errors on;
    }
}
```

---

<a name="security-en"></a>
### Security Highlights

- **Argon2id** password hashing.
- **Two-Factor Authentication (2FA / TOTP)** compatible with Google Authenticator, Bitwarden, and Aegis.
- **CSRF Tokens** enforced on all state-changing actions.
- **Brute-Force Rate Limiting** by IP address.
- **Split-Token Persistent Login ("Remember Me")** with automatic token rotation.
- **Strict HTTP Security Headers**: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and strict URI sanitization.

---

<a name="español"></a>
## 🇪🇸 Español

**KutBlog** es un gestor de contenidos moderno, ligero, modular y sin base de datos (flat-file), escrito en PHP puro. Almacena todo el contenido y configuraciones en archivos estructurados (JSON y Markdown), no requiere MySQL ni PostgreSQL, e incluye un potente sistema de plugins, analíticas respetuosas con la privacidad y una experiencia editorial fluida y sin fricciones.

---

### Índice

1. [Requisitos](#requisitos-es)
2. [Instalación](#instalación-es)
3. [Estructura de archivos y directorios](#estructura-es)
4. [Panel de administración](#panel-es)
5. [Artículos, Páginas y Calendario](#articulos-es)
6. [Editor de contenido triple-modo](#editor-es)
7. [Biblioteca de medios y carpetas virtuales](#medios-es)
8. [Sistema de plugins](#plugins-es)
9. [Catálogo de temas](#temas-es)
10. [Ajustes del sitio y favicon](#ajustes-es)
11. [Estadísticas integradas (sin cookies)](#estadisticas-es)
12. [Mastodon y comentarios sociales](#mastodon-es)
13. [RSS, Sitemap y SEO](#seo-es)
14. [Exportar y respaldar](#exportar-es)
15. [Configuración de Nginx](#nginx-es)
16. [Seguridad](#seguridad-es)
17. [Crear un tema personalizado](#tema-personalizado-es)
18. [Estructura del contenido JSON](#json-es)
19. [Solución de problemas](#troubleshooting-es)

---

<a name="requisitos-es"></a>
### Requisitos

- **PHP 8.0 o superior** (probado y optimizado en PHP 8.1, 8.2 y 8.3)
- **Nginx** (recomendado) o **Apache** con `mod_rewrite`
- Extensiones PHP requeridas: `json`, `SimpleXML`, `fileinfo`, `zip`, `gd` (para procesamiento de imágenes y favicon)
- Acceso de escritura a `content/`, `cache/`, `media/` y al archivo `config.json`

---

<a name="instalación-es"></a>
### Instalación

#### 1. Subir los archivos
Sube los archivos de KutBlog al directorio raíz de tu servidor web:
```bash
/var/www/html/misitio/
```

#### 2. Permisos del sistema
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

#### 3. Instalador web
Abre `https://tudominio.com/install/` en el navegador y completa:
- **Título del sitio:** Nombre de tu publicación o bitácora.
- **Usuario administrador:** Nombre de login.
- **Contraseña:** Mínimo 8 caracteres, mayúscula y número.

El instalador genera `config.json`, la estructura base y crea el archivo `.installed` que bloquea permanentemente el instalador por seguridad.

---

<a name="estructura-es"></a>
### Estructura de archivos y directorios

```
/
├── index.php                 ← Enrutador público principal (Home, artículos, páginas, series, podcast)
├── config.json               ← Configuración global y plugins activos
├── .installed                ← Bloqueo de seguridad del instalador
├── rss.xml.php               ← Generador del feed RSS 2.0
├── sitemap.xml.php           ← Generador del sitemap XML dinámico
│
├── admin/                    ← Panel de control administrativo
│   ├── index.php             ← Dashboard principal con resumen y accesos rápidos
│   ├── editor.php            ← Editor de contenido triple-modo (WYSIWYG / RAW / Markdown)
│   ├── articles.php          ← Listado, filtrado y gestión de artículos
│   ├── pages.php             ← Listado y gestión de páginas estáticas
│   ├── calendar.php          ← Calendario editorial interactivo con programación de posts
│   ├── media.php             ← Biblioteca multimedia con árbol de carpetas virtuales y drag & drop
│   ├── analytics.php         ← Estadísticas de visitas, dispositivos, países y fuentes
│   ├── plugins.php           ← Gestor y configuración de plugins
│   └── themes.php            ← Selector y personalización de temas
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
├── content/                  ← TUS DATOS (Directorio prioritario de respaldo)
├── media/                    ← Archivos multimedia subidos (/media/YYYY/MM/)
└── themes/                   ← Temas visuales listos para usar
```

---

<a name="panel-es"></a>
### Panel de administración

Acceso: `https://tudominio.com/admin/`

- **Barra lateral responsiva:** Modo colapsado a iconos en escritorio (con tooltips instantáneos) y menú deslizante (*drawer*) en dispositivos móviles.
- **Esquemas de color del panel:** Selector en la barra superior con 4 paletas: **Dark** (predeterminada), **Midnight** (azul noche profundo), **Slate** (gris pizarra) y **Warm** (tonos cálidos marrones).
- **Notificaciones y atajos:** Indicadores de estado de guardado, acceso directo al blog público y perfil.

---

<a name="articulos-es"></a>
### Artículos, Páginas y Calendario

- **Artículos:** Publicaciones periódicas organizadas por fecha, categorías, etiquetas, series y comentarios del Fediverso. Alimentan la portada, el feed RSS y el sitemap.
- **Páginas:** Contenido estático permanente (Acerca de, Contacto, Proyectos) integrado de forma automática en el menú de navegación de los temas.
- **Calendario (`/admin/calendar.php`):** Cuadrícula mensual con el desglose de artículos publicados, programados y borradores por día, con navegación entre meses y botón rápido `+` para programar un post en una fecha específica.

---

<a name="editor-es"></a>
### Editor de contenido triple-modo

1. **Tres modos de edición:**
   - **WYSIWYG visual:** Motor enriquecido (basado en TipTap/ProseMirror) con renderizado tipográfico en tiempo real.
   - **HTML Raw:** Edición directa del código HTML para control absoluto del marcado.
   - **Markdown:** Redacción ágil en Markdown nativo con vista previa en vivo y conversión bidireccional.
2. **Barra flotante contextual de imagen:** Al hacer clic sobre cualquier imagen en el cuerpo del post, ajusta alineación (izquierda, centro, derecha, ancho completo), dimensiones predefinidas o personalizadas y texto ALT.
3. **Comandos Slash (`/`):** Escribe `/` en cualquier línea vacía para insertar encabezados, listas, bloques de código, notas callout o tablas.
4. **Autoguardado dual:** Guardado en `localStorage` cada segundo y sincronización periódica en el servidor a través de `/admin/autosave.php`.
5. **Modo sin distracciones:** Presiona **F11** para escribir a pantalla completa sin distracciones.

---

<a name="medios-es"></a>
### Biblioteca de medios y carpetas virtuales

- **Carpetas y subcarpetas virtuales:** Organiza tus fotografías e ilustraciones en una jerarquía ilimitada de carpetas.
- **URLs 100% estables:** La clasificación se realiza mediante metadatos en `content/media_folders.json`. Mover archivos entre carpetas virtuales **nunca rompe las URLs públicas**.
- **Arrastrar y soltar (Drag & Drop):** Arrastra imágenes directamente sobre las carpetas del panel lateral.
- **Formatos soportados:** JPG, PNG, WebP, GIF, SVG y AVIF.

---

<a name="plugins-es"></a>
### Sistema de plugins

- **Series:** Agrupa publicaciones relacionadas en cursos o entregas secuenciales con índices y navegación interactiva anterior/siguiente.
- **Carpetas multimedia:** Organización avanzada de la biblioteca mediante carpetas virtuales y asignación durante la subida.
- **Newsletter:** Captura de suscriptores con doble confirmación (Opt-in) y cumplimiento de normativas de privacidad (GDPR).
- **Fediverso (ActivityPub):** Convierte tu blog en un actor nativo del Fediverso para que usuarios de Mastodon sigan tus publicaciones (`@usuario@tudominio.com`).
- **KutPod Studio:** Integración oficial con podcasts de KutPod: reproductor embebido, timestamps cliqueables y transcripciones optimizadas para SEO.
- **Búsqueda y reemplazo:** Herramienta para actualizar URLs o cadenas de texto en masa con vista previa previa (Dry-run).
- **Importador de WordPress:** Migración integral desde archivos WXR (`.xml`) descargando imágenes y preservando slugs, categorías y fechas.

---

<a name="temas-es"></a>
### Catálogo de temas

KutBlog incluye 8 temas optimizados para lectura y velocidad:

| Tema | Estilo y Características | Ideal para |
|---|---|---|
| **Default** | Claro, minimalista, diseño editorial con tarjetas en cuadrícula y tipografía limpia. | Blogs personales, revistas y noticias. |
| **Dark** | Variante oscura del tema Default con alto contraste y tonalidades suaves para la vista. | Lectura nocturna y blogs tecnológicos. |
| **Minimal** | Espacioso, diseño miniatura-first (*thumbnail-first*), selector de modo claro/oscuro. | Portafolios visuales, fotografía y diseño. |
| **Minimal Column** | Maquetación moderna a una sola columna centrada, mobile-first, modo claro y oscuro. | Artículos de opinión, ensayos y narrativa. |
| **Calendar** | Doble columna con línea de tiempo interactiva sincronizada al scroll y widget de calendario. | Bitácoras cronológicas, diarios y viajes. |
| **SystemInside** | Cabecera oscura distintiva, distribución con barra lateral derecha y tipografía condensada. | Sitios de tecnología, tutoriales y Linux. |
| **SystemInside Dark** | Edición completamente oscura de SystemInside con paleta charcoal. | Sitios técnicos y desarrollo. |
| **SystemInside Light** | Edición completamente clara de SystemInside con fondos blancos y gran legibilidad. | Documentación y lectura diurna. |

---

<a name="estadisticas-es"></a>
### Estadísticas integradas (sin cookies)

- **Sin cookies ni rastreadores:** Utiliza un hash anónimo unidireccional diario generado a partir de la IP y el User-Agent.
- **Métricas:** Páginas vistas, visitantes únicos diarios, artículos más leídos, fuentes de tráfico, dispositivos, navegadores y sistemas operativos.
- **Beacon asíncrono:** Registro ultrarrápido vía `/api/track.php` sin impacto en la carga de página.

---

<a name="seguridad-es"></a>
### Seguridad

| Medida | Implementación |
|---|---|
| **Almacenamiento de contraseñas** | Algoritmo criptográfico **Argon2id**. |
| **Doble Factor de Autenticación (2FA)** | Códigos TOTP compatibles con Google Authenticator, Aegis, Bitwarden. |
| **Sesiones persistentes (Recordarme)** | Tokens divididos (selector + validador sha256 con rotación automática). |
| **Protección contra CSRF** | Tokens criptográficos en todos los formularios de acción. |
| **Prevención de fuerza bruta** | Bloqueo temporal por IP tras 5 intentos consecutivos fallidos. |
| **Cookies seguras** | Flags `HttpOnly`, `SameSite=Lax/Strict` y `Secure`. |

---

## 💖 Support & Donations / Soporte y Donaciones

If you find this project useful and want to support its ongoing development, consider making a donation. Every contribution helps keep the project active, independent, and constantly improving!

Si este proyecto te resulta útil y deseas apoyar su desarrollo continuo, considera hacer una donación. ¡Cada aporte ayuda a mantener el proyecto activo, independiente y en constante evolución!

* ☕ **Buy Me a Coffee**: [buymeacoffee.com/ernestoacostame](https://www.buymeacoffee.com/ernestoacostame)
* 💖 **Ko-fi**: [ko-fi.com/ernestoacostame](https://ko-fi.com/ernestoacostame)
* 🅿️ **PayPal**: [paypal.com/paypalme/elav](https://www.paypal.com/paypalme/elav)

<p align="center">
  <a href="https://www.buymeacoffee.com/ernestoacostame" target="_blank">
    <img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-Invítame%20un%20café-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee">
  </a>
  &nbsp;&nbsp;
  <a href="https://ko-fi.com/ernestoacostame" target="_blank">
    <img src="https://img.shields.io/badge/Ko--fi-Apóyame-FF5E5B?style=for-the-badge&logo=ko-fi&logoColor=white" alt="Ko-fi">
  </a>
  &nbsp;&nbsp;
  <a href="https://www.paypal.com/paypalme/elav" target="_blank">
    <img src="https://img.shields.io/badge/PayPal-Donar%20%2F%20Donate-00457C?style=for-the-badge&logo=paypal&logoColor=white" alt="PayPal">
  </a>
</p>
