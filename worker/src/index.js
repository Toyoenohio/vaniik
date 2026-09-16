import decodeJpeg, { init as initJpegDec } from '@jsquash/jpeg/decode';
import decodePng, { init as initPngDec } from '@jsquash/png/decode';
import decodeWebp, { init as initWebpDec } from '@jsquash/webp/decode';
import encodeWebp, { init as initWebpEnc } from '@jsquash/webp/encode';
import { simd } from 'wasm-feature-detect';

// Import directo del .wasm (ESM worker): wrangler compila cada .wasm a WebAssembly.Module.
import WEBP_DEC from '../node_modules/@jsquash/webp/codec/dec/webp_dec.wasm';
import WEBP_ENC from '../node_modules/@jsquash/webp/codec/enc/webp_enc.wasm';
import WEBP_ENC_SIMD from '../node_modules/@jsquash/webp/codec/enc/webp_enc_simd.wasm';
import JPEG_DEC from '../node_modules/@jsquash/jpeg/codec/dec/mozjpeg_dec.wasm';
import PNG_DEC from '../node_modules/@jsquash/png/codec/pkg/squoosh_png_bg.wasm';

// Inicialización manual de los módulos WASM (obligatoria en Workers: el glue de
// emscripten/wasm-bindgen no puede fetchear los .wasm por URL).
let readyPromise;
function ensureInit() {
  if (!readyPromise) {
    readyPromise = (async () => {
      await initJpegDec(JPEG_DEC);
      await initPngDec(PNG_DEC);
      await initWebpDec(WEBP_DEC);
      const useSimd = await simd();
      await initWebpEnc(useSimd ? WEBP_ENC_SIMD : WEBP_ENC);
    })();
  }
  return readyPromise;
}

// Detección por magic bytes (no confiar en el Content-Type del cliente).
function detectFormat(bytes) {
  if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) return 'jpeg';
  if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) return 'png';
  if (
    bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46 &&
    bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50
  ) return 'webp';
  return null;
}

async function decodeImage(buf, format) {
  switch (format) {
    case 'jpeg': return decodeJpeg(buf);
    case 'png': return decodePng(buf);
    case 'webp': return decodeWebp(buf);
    default: throw new Error('Formato de imagen no soportado');
  }
}

function checkAuth(request, env) {
  const token = env.AUTH_TOKEN;
  if (!token) return true; // sin token configurado = abierto (solo dev)
  const auth = request.headers.get('Authorization') || '';
  if (auth === `Bearer ${token}`) return true;
  return new URL(request.url).searchParams.get('token') === token;
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (url.pathname === '/health') {
      return new Response(JSON.stringify({ ok: true }), {
        headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' },
      });
    }

    if (!checkAuth(request, env)) {
      return new Response('Unauthorized', { status: 401 });
    }

    try {
      let buf;

      if (request.method === 'POST') {
        const ct = request.headers.get('Content-Type') || '';
        if (!ct.startsWith('image/') && !ct.startsWith('application/octet-stream')) {
          return new Response('POST: Content-Type debe ser image/* o application/octet-stream', { status: 400 });
        }
        buf = await request.arrayBuffer();
      } else if (request.method === 'GET' && url.searchParams.get('url')) {
        const r = await fetch(url.searchParams.get('url'), { headers: { 'User-Agent': 'wp-webp-worker/1.0' } });
        if (!r.ok) return new Response(`No se pudo obtener la imagen: HTTP ${r.status}`, { status: 502 });
        buf = await r.arrayBuffer();
      } else {
        return new Response('Uso: POST con body binario (JPEG/PNG/WebP) o GET ?url=<imagen>', { status: 400 });
      }

      if (!buf || buf.byteLength === 0) {
        return new Response('Body vacío', { status: 400 });
      }

      const bytes = new Uint8Array(buf);
      const format = detectFormat(bytes);
      if (!format) {
        return new Response('Formato no soportado (solo JPEG/PNG/WebP)', { status: 415 });
      }

      await ensureInit();
      const decoded = await decodeImage(buf, format);
      const rawQ = parseInt(url.searchParams.get('quality') || '80', 10);
      const quality = Number.isFinite(rawQ) ? Math.min(100, Math.max(1, rawQ)) : 80;

      const out = await encodeWebp(decoded, { quality });

      return new Response(out, {
        headers: {
          'Content-Type': 'image/webp',
          'Cache-Control': 'public, max-age=31536000, immutable',
          'X-Input-Format': format,
        },
      });
    } catch (err) {
      const msg = err && err.message ? err.message : String(err);
      return new Response(`Conversión fallida: ${msg}`, { status: 500 });
    }
  },
};
