# KutBlog

**KutBlog** es un gestor de contenidos ligero, rápido y sin base de datos, escrito en PHP puro. Almacena todo el contenido en archivos JSON, no requiere MySQL ni PostgreSQL, y es fácil de instalar en cualquier servidor con PHP 8.0+.

<img width="2250" height="1369" alt="kutblog_theme" src="https://github.com/user-attachments/assets/f7979547-cbcb-48ba-bbfb-db9e1827a6de" />

<img width="2250" height="1369" alt="kutblog_stats" src="https://github.com/user-attachments/assets/9b6d6795-b093-47bf-9bf6-185d2fad942a" />

<img width="2250" height="1369" alt="kutblog_editor" src="https://github.com/user-attachments/assets/6868791d-5a8d-4352-b30a-38eb183d702f" />

<img width="2250" height="1369" alt="kutblog_admin" src="https://github.com/user-attachments/assets/5561a532-b4ab-4579-b34c-8d4f7d887d43" />

---

## Índice

1. [Requisitos](#requisitos)
2. [Instalación](#instalación)
3. [Estructura de archivos](#estructura-de-archivos)
4. [Panel de administración](#panel-de-administración)
5. [Artículos y Páginas](#artículos-y-páginas)
6. [Editor de contenido](#editor-de-contenido)
7. [Media](#media)
8. [Importar desde WordPress](#importar-desde-wordpress)
9. [Temas](#temas)
10. [Ajustes del sitio](#ajustes-del-sitio)
11. [Mastodon y redes sociales](#mastodon-y-redes-sociales)
12. [RSS, Sitemap y SEO](#rss-sitemap-y-seo)
13. [Herramientas](#herramientas)
14. [Exportar y respaldar](#exportar-y-respaldar)
15. [Nginx](#nginx)
16. [Seguridad](#seguridad)
17. [Crear un tema personalizado](#crear-un-tema-personalizado)
18. [Estructura del contenido JSON](#estructura-del-contenido-json)
19. [Solución de problemas](#solución-de-problemas)
20. [Estadísticas: opciones disponibles](#estadísticas-opciones-disponibles)

---

## Requisitos

- **PHP 8.0 o superior**
- **Nginx** (recomendado) o **Apache** con mod_rewrite
- Extensiones PHP: `json`, `SimpleXML`, `fileinfo`, `zip`
- Acceso de escritura a `content/`, `cache/`, `media/` y al archivo `config.json`

---

## Instalación

### 1. Subir los archivos

Sube todos los archivos de KutBlog al directorio raíz de tu servidor web:

```
/var/www/html/misitio/
```

### 2. Permisos

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

El archivo `nginx.conf.example` incluido en el ZIP es una plantilla lista para usar. Cópialo y ajusta los valores marcados con `<CAMBIAR>`:

```nginx
server_name tudominio.com www.tudominio.com;
root /var/www/html/misitio;
fastcgi_pass unix:/run/php/php8.3-fpm.sock;  # ajusta la versión de PHP
```

Activa el sitio:

```bash
# Copiar y renombrar la plantilla
cp nginx.conf.example /etc/nginx/sites-available/tudominio.conf

# Editar y cambiar todos los valores marcados <CAMBIAR>
nano /etc/nginx/sites-available/tudominio.conf

# Activar el sitio
ln -sf /etc/nginx/sites-available/tudominio.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

### 4. Certificado SSL

```bash
certbot certonly --nginx -d tudominio.com -d www.tudominio.com
```

Actualiza las líneas `ssl_certificate` en el vhost para apuntar al nuevo certificado y recarga Nginx.

### 5. Instalador web

Abre `https://tudominio.com/install/` en el navegador y rellena:

- **Título del sitio** — nombre de tu blog
- **URL base** — se detecta automáticamente
- **Usuario administrador** — nombre de login
- **Contraseña** — mínimo 8 caracteres, una mayúscula y un número

El instalador crea `config.json`, las carpetas necesarias y contenido de ejemplo. El archivo `.installed` en la raíz desactiva el instalador permanentemente.

---

## Estructura de archivos

```
/
├── index.php               ← Router principal
├── config.json             ← Configuración (generado al instalar)
├── .installed              ← Marca que la instalación está completa
├── rss.xml.php             ← Generador del feed RSS
├── sitemap.xml.php         ← Generador del sitemap XML
│
├── admin/                  ← Panel de administración
│   ├── index.php           ← Dashboard
│   ├── editor.php          ← Editor de artículos y páginas
│   ├── articles.php        ← Listado de artículos
│   ├── pages.php           ← Listado de páginas
│   ├── media.php           ← Gestor de imágenes
│   ├── import.php          ← Importar desde WordPress
│   ├── import_images.php   ← Descargar imágenes externas
│   ├── export.php          ← Exportar contenido
│   ├── search_replace.php  ← Buscar y reemplazar en contenido
│   ├── settings.php        ← Ajustes del sitio
│   └── themes.php          ← Gestión de temas
│
├── plugins/                ← Sistema de plugins
│   ├── fediverse/          ← Integración con Fediverso / ActivityPub
│   └── wp-import/          ← Importador de WordPress (WXR) y gestor de imágenes externas
│
├── core/                   ← Lógica interna (no accesible públicamente)
│   ├── config.php          ← Constantes y funciones de configuración
│   ├── auth.php            ← Autenticación y seguridad
│   ├── content.php         ← CRUD de artículos y páginas
│   ├── markdown.php        ← Parser Markdown puro PHP
│   └── theme.php           ← Motor de temas
│
├── content/                ← TODO TU CONTENIDO (respaldar esto)
│   ├── articles/           ← Artículos en formato JSON
│   └── pages/              ← Páginas en formato JSON
│
├── media/                  ← Imágenes subidas e importadas (respaldar esto)
├── cache/                  ← Caché temporal (se puede borrar sin problema)
│
├── themes/                 ← Temas disponibles
│   ├── systeminside/       ← Header oscuro, fondo claro, sidebar
│   ├── systeminside-dark/  ← Completamente oscuro
│   ├── systeminside-light/ ← Completamente claro
│   ├── default/            ← Tema claro minimalista con tarjetas
│   └── dark/               ← Tema oscuro minimalista con tarjetas
│
└── api/
    └── mastodon.php        ← Proxy para comentarios de Mastodon
```

---

## Panel de administración

Accede en: `https://tudominio.com/admin/`

### Barra lateral

- **Colapsar (escritorio):** clic en el botón ☰ del header del sidebar. Solo iconos en modo colapsado, tooltip al pasar el cursor. Estado guardado en localStorage.
- **Móvil:** la barra se oculta. Botón ☰ en la topbar la abre como panel deslizante.

### Esquemas de color del panel

Cuatro puntos de colores en la topbar derecha:

| Esquema | Descripción |
|---------|-------------|
| Dark | Oscuro estándar (predeterminado) |
| Midnight | Azul noche profundo |
| Slate | Gris azulado |
| Warm | Tonos cálidos marrones |

El esquema se recuerda durante la sesión activa (cookie de sesión PHP).

---

## Artículos y Páginas

### Diferencia

- **Artículos** — entradas del blog con fecha, categorías, etiquetas y comentarios Mastodon. Aparecen en el listado principal y el RSS.
- **Páginas** — contenido estático (Sobre mí, Contacto, etc.). Aparecen automáticamente en el menú de navegación del sitio y en el footer.

### Rutas URL

| Tipo | URL |
|------|-----|
| Home / blog | `tudominio.com/` |
| Artículo | `tudominio.com/article/el-slug` |
| Página | `tudominio.com/page/el-slug` |
| Categoría | `tudominio.com/category/linux` |
| Etiqueta | `tudominio.com/tag/php` |
| Búsqueda | `tudominio.com/search?q=termino` |
| RSS | `tudominio.com/rss.xml` |
| Sitemap | `tudominio.com/sitemap.xml` |

---

## Editor de contenido

### Modos

El editor tiene tres modos seleccionables con los botones **HTML** / **MD** en la toolbar:

- **HTML (WYSIWYG)** — editor visual con formato en tiempo real
- **HTML raw** — código HTML directo (botón `</> HTML` en la toolbar)
- **Markdown** — escritura en Markdown con preview en vivo

### Toolbar

| Grupo | Herramientas |
|-------|-------------|
| Encabezados | H2, H3, párrafo |
| Formato | Negrita, Cursiva, Subrayado, Tachado |
| Listas | Lista, Lista numerada, Cita, Bloque de código |
| Insertar | Enlace, Imagen (URL o archivo), Divisor |
| Historial | Deshacer, Rehacer |
| Extras | HTML raw, Toolbar flotante, Modo sin distracciones |

### Toolbar flotante

Al activar el icono de toolbar flotante, al **seleccionar texto** aparece una mini-barra encima con: negrita, cursiva, subrayado, tachado, H2, H3, enlace. Funciona con selección de ratón y de teclado (Shift + flechas). Se desactiva con el mismo botón; preferencia guardada en localStorage.

### Modo sin distracciones

Botón ⤢ en la toolbar o tecla **F11**. Oculta la barra lateral, topbar y panel de opciones. Solo el editor a pantalla completa. **Escape** para salir.

### Subir imágenes

1. **En el cuerpo:** botón 🖼 en la toolbar → elige entre subir archivo o pegar URL
2. **Imagen destacada:** panel lateral derecho → sección "Imagen Destacada" → botón "📁 Subir imagen" o campo URL

Las imágenes subidas se guardan en `/media/YYYY/MM/nombre.jpg`.

### Panel lateral derecho del editor

| Campo | Descripción |
|-------|-------------|
| Estado | Borrador / Publicado |
| URL Slug | Auto-generado del título, personalizable |
| Resumen | Descripción corta para listados y SEO |
| Categorías | Sugerencias de categorías existentes (clic para añadir) |
| Etiquetas | Escribe para buscar entre las existentes o añade con Enter |
| Imagen Destacada | URL o subida directa |
| URL de Mastodon | URL del toot para activar comentarios |

### Botones

- **Guardar borrador** — guarda sin publicar
- **🚀 Publicar** — publica inmediatamente
- **✓ Actualizar** — actualiza un artículo publicado

### Markdown soportado

Encabezados, negrita, cursiva, tachado, listas, listas numeradas, código inline y bloques, tablas con alineación, citas, imágenes, enlaces, reglas horizontales, HTML inline.

---

## Media

**Admin → Media**

Muestra todas las imágenes en `/media/` organizadas por fecha de subida.

- **Subir:** botón "Subir imagen" o arrastrar directamente sobre la página
- **Copiar URL:** hover sobre la imagen → icono de copia
- **Eliminar:** hover → icono de papelera

Formatos aceptados: JPG, PNG, GIF, WebP, SVG, AVIF. Máximo 10 MB.

---

## Importar desde WordPress

Disponible a través del plugin oficial **Importador de WordPress** (`plugins/wp-import/`), administrable desde **Admin → Plugins** o en la barra lateral en **Admin → Herramientas → Importar WordPress**.

### Cómo exportar desde WordPress

En tu WordPress: **Herramientas → Exportar → Todo el contenido → Descargar exportación**. Se descarga un `.xml`.

### Qué se importa

| Se importa | No se importa |
|-----------|---------------|
| Posts → Artículos | Comentarios |
| Pages → Páginas | Usuarios |
| Categorías y etiquetas | Plugins |
| Fechas y estado pub/borrador | Menús personalizados |
| Imágenes incrustadas → `/media/` | Archivos no-imagen |
| Imagen destacada | |

### Opción "Descargar imágenes automáticamente"

Con esta opción activa, KutBlog durante la importación:
1. Detecta todas las `<img src="...">` del contenido
2. Descarga cada imagen preservando la estructura de carpetas de WP (`/media/2025/02/imagen.jpg`)
3. Reescribe las URLs en el contenido para apuntar a tu dominio

Los bloques Gutenberg (`<!-- wp:paragraph -->` etc.) se eliminan automáticamente.

### Importar imágenes después

Si ya importaste sin imágenes: **Admin → Herramientas → Importar imágenes** escanea todos los artículos y descarga las imágenes que aún apuntan a dominios externos.

---

## Temas

**Admin → Temas**

Cinco temas incluidos:

| Tema | Descripción |
|------|-------------|
| SystemInside | Header oscuro, fondo gris claro, sidebar derecho con categorías |
| SystemInside Dark | Completamente oscuro |
| SystemInside Light | Completamente claro |
| Default | Claro minimalista, grid de tarjetas |
| Dark | Oscuro minimalista, grid de tarjetas |

Para activar: clic en **Activar** junto al tema, o desde **Ajustes → Apariencia → Tema**.

Todos los temas usan la tipografía **Roboto Condensed** (títulos y navegación) y **Roboto** (cuerpo de texto).

---

## Ajustes del sitio

**Admin → Ajustes**

### General

| Campo | Descripción |
|-------|-------------|
| Título del sitio | Nombre del blog |
| Tagline | Descripción corta |
| URL base | URL completa sin barra final. Ej: `https://www.tudominio.com` |
| Artículos por página | Artículos en el listado (predeterminado: 8) |
| Texto del footer | Texto libre en el pie de página |

### Apariencia

| Campo | Descripción |
|-------|-------------|
| Tema | Tema activo del sitio público |
| Color de acento | Color principal. 10 presets + selector libre |

### Mastodon / ActivityPub

| Campo | Descripción |
|-------|-------------|
| Handle de Mastodon | Formato `@usuario@instancia.social`. Activa `fediverse:creator` |
| URL de perfil | URL completa del perfil. Activa `<link rel="me">` para verificación |

### Seguridad

Permite cambiar usuario y contraseña. Requiere la contraseña actual.

---

## Mastodon y redes sociales

### Verificación de perfil

1. En **Admin → Ajustes → Mastodon**, rellena handle y URL de perfil
2. En tu perfil de Mastodon → **Editar perfil → Campos adicionales**, añade un campo con tu URL del sitio
3. Mastodon detecta el `<link rel="me">` y marca el campo con ✓

### Comentarios de Mastodon

Flujo completo:

1. Publica el artículo en tu blog
2. Comparte la URL del artículo en Mastodon
3. Copia la URL de **ese toot** (ej: `https://mastodon.social/@user/114123456789`)
4. En el editor del artículo, pega esa URL en el campo **"URL de Mastodon"** del panel lateral
5. Guarda

Los comentarios son las respuestas al toot. KutBlog carga el hilo completo (incluyendo respuestas a respuestas) con indentación visual. Caché de 3 minutos.

Para borrar la caché y forzar recarga:

```bash
rm -f /var/www/html/misitio/cache/mastodon_*.json
```

---

## RSS, Sitemap y SEO

### Rutas

| Recurso | URL |
|---------|-----|
| RSS | `tudominio.com/rss.xml` o `/feed` |
| Sitemap | `tudominio.com/sitemap.xml` |
| robots.txt | `tudominio.com/robots.txt` |

### Metadatos automáticos en artículos

- `og:title`, `og:description`, `og:image`, `og:url`, `og:type`
- `<meta name="description">`
- `<link rel="canonical">`
- `<link rel="alternate">` para RSS
- `<meta name="fediverse:creator">` (si está configurado Mastodon)
- `<link rel="me">` (si está configurado Mastodon)

---

## Herramientas

### Buscar y reemplazar

**Admin → Herramientas → Buscar y reemplazar**

Busca y reemplaza texto en contenido, resumen e imagen destacada de todos los artículos y páginas. Tiene un modo **Simular** que muestra qué se vería afectado sin modificar nada.

Útil tras cambiar de dominio: botón de acceso rápido "Dominio antiguo → nuevo" rellena los campos automáticamente con los valores de configuración actuales.

> ⚠️ Modifica archivos directamente. Sin deshacer. Haz una copia de `content/` antes si tienes dudas.

---

## Exportar y respaldar

**Admin → Herramientas → Exportar contenido**

Descarga un ZIP con:
- Artículos (`content/articles/`)
- Páginas (`content/pages/`)
- Configuración del sitio (sin contraseña)
- Imágenes de `/media/` (opcional)

### Respaldo manual

Para mover el blog a otro servidor, solo necesitas:

```
content/      ← artículos y páginas
media/        ← imágenes
config.json   ← configuración y credenciales
```

Los archivos PHP del CMS se reinstalan desde cero descargando KutBlog de nuevo.

---

## Nginx

El archivo `systeminside.conf` incluido configura:

- Redirección HTTP → HTTPS
- Redirección non-www → www (o al revés)
- SSL con Let's Encrypt
- Bloqueo de `core/`, `cache/`, `content/`
- Caché larga para assets estáticos (1 año)
- Sin ejecución PHP en `/media/` y `/uploads/`
- PHP-FPM 8.3

### Aplicar cambios

```bash
nginx -t                    # verificar sintaxis
systemctl reload nginx      # aplicar
```

### Renovación automática SSL

Certbot instala un timer de systemd que renueva el certificado automáticamente. Verifica:

```bash
systemctl status certbot.timer
certbot renew --dry-run
```

---

## Seguridad

| Medida | Detalle |
|--------|---------|
| Hash de contraseña | Argon2id |
| CSRF | Token en todos los formularios del panel |
| Rate limiting | 5 intentos fallidos → bloqueo 15 min por IP |
| Regeneración de sesión | Cada 5 minutos |
| Cookies | HttpOnly, SameSite=Strict, Secure |
| Timeout de sesión | 4 horas de inactividad |
| Bloqueo de directorios | `core/`, `cache/`, `content/` inaccesibles |
| Sin PHP en uploads | Bloqueo en `/media/` y `/uploads/` |
| Headers HTTP | X-Frame-Options, X-Content-Type-Options, CSP, HSTS |

---

## Crear un tema personalizado

Un tema es una carpeta en `themes/` con estos archivos:

```
themes/mi-tema/
├── theme.json    ← Metadatos (obligatorio)
├── header.php    ← <head>, header del sitio, apertura del <main>
├── footer.php    ← Footer, cierre del </body>
├── index.php     ← Listado de artículos
├── single.php    ← Vista de artículo o página
├── search.php    ← Resultados de búsqueda
└── 404.php       ← Página de error 404
```

### theme.json

```json
{
  "label": "Mi Tema",
  "description": "Descripción breve",
  "author": "Tu Nombre",
  "version": "1.0.0"
}
```

### Variables disponibles en todos los templates

| Variable | Tipo | Descripción |
|----------|------|-------------|
| `$site_title` | string | Título del sitio |
| `$theme_color` | string | Color de acento, ej: `#e05c1a` |
| `$base` | string | URL base sin barra final |
| `$config` | array | Configuración completa |

### Variables específicas

**index.php:**

| Variable | Descripción |
|----------|-------------|
| `$posts` | Array: `items`, `total`, `pages`, `page` |
| `$category` | Nombre de categoría (si es archivo) |
| `$tag` | Nombre de etiqueta (si es archivo) |

**single.php:**

| Variable | Descripción |
|----------|-------------|
| `$post` | Array completo del artículo/página |
| `$type` | `'articles'` o `'pages'` |

**search.php:**

| Variable | Descripción |
|----------|-------------|
| `$results` | Array de resultados |
| `$query` | Término buscado |

### Estructura del array `$post`

```php
$post = [
    'title'          => 'Título del artículo',
    'slug'           => 'titulo-del-articulo',
    'content'        => '<p>Contenido HTML...</p>',
    'excerpt'        => 'Resumen breve',
    'status'         => 'published',
    'categories'     => ['Linux', 'Servidores'],
    'tags'           => ['nginx', 'php'],
    'featured_image' => 'https://tudominio.com/media/2025/04/portada.jpg',
    'mastodon_url'   => 'https://mastodon.social/@user/123',
    'content_format' => 'html',
    'created_at'     => '2025-04-12T10:30:00+00:00',
    'updated_at'     => '2025-04-12T14:00:00+00:00',
];
```

### Variables CSS automáticas

Llama a `get_theme_css_vars()` en el `<head>` de `header.php`:

```php
<style>
<?= get_theme_css_vars() ?>
/* Tu CSS aquí, usa var(--accent), var(--accent-light), etc. */
</style>
```

Esto inyecta:

```css
:root {
    --accent:        #e05c1a;
    --accent-rgb:    224,92,26;
    --accent-light:  rgba(224,92,26,0.1);
    --accent-medium: rgba(224,92,26,0.3);
}
```

### header.php mínimo

```php
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($post) ? htmlspecialchars($post['title']).' — ' : '' ?><?= htmlspecialchars($site_title) ?></title>
<style>
<?= get_theme_css_vars() ?>
body { font-family: sans-serif; }
a { color: var(--accent); }
</style>
</head>
<body>
<header>
  <a href="<?= $base ?>/"><?= htmlspecialchars($site_title) ?></a>
</header>
<main>
```

### footer.php mínimo

```php
</main>
<footer>
  <p><?= htmlspecialchars($config['footer_text'] ?? '© '.date('Y').' '.$site_title) ?></p>
</footer>
</body>
</html>
```

### Auto-embed de YouTube

Para convertir URLs de YouTube en iframes, añade al inicio de `single.php`:

```php
<?php
$content = $post['content'] ?? '';
$content = preg_replace_callback(
    '#(?:<p>)?\s*(https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([\w\-]{11})[^\s<]*)\s*(?:</p>)?#i',
    function($m) {
        return '<div class="yt-embed"><iframe src="https://www.youtube-nocookie.com/embed/'.$m[2].'" allowfullscreen loading="lazy"></iframe></div>';
    },
    $content
);
?>
```

Y el CSS necesario:

```css
.yt-embed { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; margin: 1.5rem 0; }
.yt-embed iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border: none; }
```

### Activar el tema

Una vez creada la carpeta, aparece automáticamente en **Admin → Temas**. Clic en **Activar**.

---

## Estructura del contenido JSON

Cada artículo se guarda en `content/articles/el-slug.json`. Puedes editar los archivos directamente con cualquier editor de texto.

```json
{
    "title": "Mi primer artículo",
    "slug": "mi-primer-articulo",
    "content": "<p>Contenido HTML aquí.</p>",
    "excerpt": "Un resumen del artículo.",
    "status": "published",
    "categories": ["General"],
    "tags": ["kutblog", "blog"],
    "featured_image": "https://tudominio.com/media/2025/04/portada.jpg",
    "mastodon_url": "",
    "content_format": "html",
    "created_at": "2025-04-12T10:00:00+00:00",
    "updated_at": "2025-04-12T10:00:00+00:00"
}
```

---

## Solución de problemas

### Error 500 al cargar el sitio

```bash
tail -50 /var/log/nginx/tudominio.error.log
```

Causa más común: permisos incorrectos en `content/`, `cache/` o `config.json`.

### El panel redirige a URL incorrecta

Comprueba la **URL base** en **Admin → Ajustes → General**. Debe coincidir exactamente con la URL que usas para acceder (con o sin www, con https).

### Las imágenes no se ven tras importar WordPress

Ve a **Admin → Herramientas → Importar imágenes** para descargar las imágenes a `/media/` y reescribir las URLs en el contenido.

### Los comentarios de Mastodon no aparecen

1. Verifica que la URL del toot sea correcta: `https://instancia.social/@usuario/NUMEROID`
2. Borra la caché:
   ```bash
   rm -f /var/www/html/misitio/cache/mastodon_*.json
   ```
3. Recarga el artículo

### Error `Primary script unknown` en Nginx

El `root` del vhost no apunta a la carpeta correcta. Verifica la línea `root` en el archivo de configuración de Nginx.

### No puedo acceder al panel después de cambiar la URL base

Si accidentalmente pusiste una URL incorrecta y no puedes entrar al panel, edita `config.json` directamente en el servidor:

```bash
nano /var/www/html/misitio/config.json
# Cambia el valor de "base_url" a la URL correcta
```

### Error `Cannot bind to port 443` al iniciar Nginx

```bash
pkill nginx
sleep 2
systemctl start nginx
```

---

## Estadísticas: opciones disponibles

> Esta sección documenta opciones para el futuro, **no implementadas actualmente**.

### Opción 1 — Contador en archivos (nativo)
Guarda un contador en JSON por artículo. Sin dependencias, sin base de datos, mínimo impacto en rendimiento. Solo cuenta visitas, no da detalles de dispositivo o procedencia.

### Opción 2 — Umami (recomendada)
Analíticas ligeras y respetuosas con la privacidad. Script de ~2 KB, sin cookies, cumple GDPR. Autoalojado en el mismo servidor. Da páginas vistas, referrers, dispositivos, países y tiempo en página.
- Web: https://umami.is

### Opción 3 — GoatCounter
Extremadamente ligero (< 1 KB de script), open source, diseñado para blogs pequeños. Da páginas vistas y referrers. Versión gratuita en goatcounter.com o autoalojado.
- Web: https://www.goatcounter.com

### Opción 4 — Plausible
Similar a Umami con interfaz más pulida. Versión cloud de pago, versión autoalojada gratuita.
- Web: https://plausible.io

### Opción 5 — GoAccess (logs de Nginx)
Sin ningún script adicional. Procesa los logs de Nginx existentes y genera informes HTML completos con páginas más visitadas, referrers, dispositivos, países y más.

```bash
# Instalar
apt install goaccess

# Generar informe HTML
goaccess /var/log/nginx/tudominio.access.log \
  --log-format=COMBINED \
  -o /var/www/html/misitio/stats.html

# Proteger el acceso con contraseña en Nginx si lo haces público
```

### Comparativa

| Opción | Instalación | Datos | Impacto en rendimiento |
|--------|-------------|-------|----------------------|
| Contador en archivos | Ninguna | Solo visitas | Mínimo |
| GoAccess | Mínima | Completos | Ninguno |
| GoatCounter | Simple | Buenos | Mínimo |
| Umami | Media | Completos | Mínimo |
| Plausible | Media/SaaS | Completos | Mínimo |

---

*KutBlog — Ligero, rápido y sin base de datos.*
