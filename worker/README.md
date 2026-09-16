# wp-webp-worker

Worker de Cloudflare que convierte imágenes a **WebP** (backed del plugin WordPress de optimización). Usa `@jsquash/*` (WASM nativo de edge, sin dependencias de servidor ni vulnerabilidades de libvips).

## API

- `POST /?quality=80` con body binario (`image/jpeg`, `image/png`, `image/webp`) → devuelve `image/webp`.
- `GET /?url=<imagen>&quality=80` → descarga la imagen, la convierte y la devuelve.
- `GET /health` → `{"ok":true}`.

Formato detectado por **magic bytes** (no por el Content-Type). Soporta entrada JPEG / PNG / WebP. Salida: WebP con `quality` (1-100, default 80).

## Auth

Opcional. Si se define el secreto `AUTH_TOKEN`, todas las peticiones deben llevar `Authorization: Bearer <token>` (o `?token=<token>`). Sin `AUTH_TOKEN`, el Worker queda abierto (solo para dev).

## Deploy

```bash
npm install
npm run deploy            # sube a tu cuenta de Cloudflare
```

Para protegerlo:

```bash
npx wrangler secret put AUTH_TOKEN
```

El plugin de WordPress debe configurarse con:
- **Endpoint**: la URL del Worker (`https://wp-webp-worker.<tu-subdominio>.workers.dev`).
- **Token**: el valor de `AUTH_TOKEN` (si se configuró).

## Nota técnica (importante)

Los módulos `.wasm` de `@jsquash/*` se importan directamente en `src/index.js` (import ESM de `.wasm`) porque **`[wasm_modules]` de wrangler no funciona con workers ESM**. La inicialización es manual (`init()` de cada codec) porque el glue de emscripten no puede fetchear los `.wasm` por URL dentro de un Worker.

Si en el futuro quieres AVIF, añade `@jsquash/avif` y sigue el mismo patrón (import del `.wasm` + `init()` manual + rama en `detectFormat`/`decodeImage`).
