# KutBlog en Docker

## Inicio rápido

Desde la carpeta `Docker/`:

```bash
docker compose up -d --build
```

Abre en tu navegador: `http://localhost:8080` (o `http://localhost:8080/install` si aún no está instalado).

## Puerto personalizado

```bash
KUTBLOG_PORT=8081 docker compose up -d --build
```

## Persistencia

Todo el contenido (`content/`, `media/`, `cache/`, `plugins/`, `themes/` y `config.json`) se persiste en el volumen `kutblog_data` montado en `/data`.
