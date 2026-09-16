# Vaniik

Conversión de imágenes a **WebP** para WordPress, sin depender de Imagick/GD en el servidor. Un **Worker de Cloudflare** hace la conversión (WASM, gratis, ilimitado) y un **plugin de WordPress** lo consume para convertir la librería y servir los `.webp`.

## Componentes

```
vaniik/
├── worker/   # Cloudflare Worker: convierte JPEG/PNG/WebP → WebP (via @jsquash WASM)
└── plugin/   # Plugin de WordPress: on-upload + bulk por cron + servido server-agnóstico
```

### `worker/`

- `POST /?quality=80` con body binario → devuelve `image/webp`.
- `GET /?url=<imagen>&quality=80` → descarga, convierte y devuelve.
- Auth opcional por `AUTH_TOKEN` (header `Authorization: Bearer <token>`).

**Deploy** (ver `worker/README.md`):

```bash
cd worker
npm install
npx wrangler login          # una vez, abre el navegador
npx wrangler deploy         # sube el Worker
npx wrangler secret put AUTH_TOKEN   # opcional, para protegerlo
```

### `plugin/`

Plugin WordPress (`wp-webp-worker`). Configura endpoint + token en **WebP Worker** (menú admin), convierte al subir y en lote (por WP-Cron, en lotes de 20), y sirve los `.webp` de forma **server-agnóstica**: reescribe la URL de la imagen a `.webp` en el HTML cuando el navegador lo acepta y el archivo existe (funciona en Apache, Nginx y LiteSpeed). En Apache además escribe reglas `.htaccess` como respaldo para imágenes servidas fuera del HTML.

**Convierte todos los tamaños**: el original + los thumbnails registrados (`-300x`, `-1024x`, etc.), que son los que cargan los carruseles y el `srcset`.

**Auto-update**: el plugin consulta los releases de GitHub (`Toyoenohio/vaniik`) y muestra "actualización disponible" en el admin. Para publicar una versión nueva:

```bash
GH_TOKEN=<tu_pat> ./release.sh 1.0.2
```

## Flujo

1. Despliega el Worker y anota su URL.
2. Instala y activa el plugin en el sitio del cliente.
3. Pega endpoint + token → "Convertir toda la librería".

## Stack

- Worker: Cloudflare Workers + `@jsquash/webp` / `@jsquash/jpeg` / `@jsquash/png` (WASM).
- Plugin: PHP vanilla (compatible con WordPress 6.0+ / PHP 7.4+), Settings API, WP-Cron.
