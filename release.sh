#!/usr/bin/env bash
# Crea un release de GitHub con el ZIP del plugin para auto-update.
#
# Uso: ./release.sh <version>          (ej: ./release.sh 1.0.2)
#
# Requisito: un PAT de GitHub con Contents: RW sobre Toyoenohio/vaniik,
# exportado como GH_TOKEN (o GITHUB_TOKEN). No requiere `gh` ni `zip` nativo
# de más (usa python3 para el ZIP si no hay `zip`).

set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Uso: $0 <version>   (ej: $0 1.0.2)"
  exit 1
fi

TOKEN="${GH_TOKEN:-${GITHUB_TOKEN:-}}"
if [ -z "$TOKEN" ]; then
  echo "Falta GH_TOKEN (o GITHUB_TOKEN). Expórtalo antes de ejecutar."
  exit 1
fi

ROOT="$(cd "$(dirname "$0")" && pwd)"        # raíz del repo (vaniik)
PLUGIN_DIR="$ROOT/plugin"
MAIN_FILE="$PLUGIN_DIR/wp-webp-worker.php"
REPO="Toyoenohio/vaniik"

# 1. Bump de versión (header + constante)
sed -i "s/^ \* Version:.*/ \* Version:           $VERSION/" "$MAIN_FILE"
sed -i "s/define( 'WPWEBP_VERSION',.*/define( 'WPWEBP_VERSION', '$VERSION' );/" "$MAIN_FILE"

# 2. Build del ZIP (archivos del plugin en la raíz del zip)
ZIP="/tmp/wp-webp-worker-$VERSION.zip"
rm -f "$ZIP"
if command -v zip >/dev/null 2>&1; then
  ( cd "$PLUGIN_DIR" && zip -r "$ZIP" . -x '*.git*' ) >/dev/null
else
  ( cd "$PLUGIN_DIR" && python3 -c "
import zipfile, os
with zipfile.ZipFile('$ZIP', 'w', zipfile.ZIP_DEFLATED) as z:
    for dp, _, fs in os.walk('.'):
        for f in fs:
            p = os.path.join(dp, f)
            z.write(p, os.path.relpath(p, '.'))
" )
fi

# 3. Commit + tag + push
git -C "$ROOT" add -A
git -C "$ROOT" commit -m "chore(plugin): bump a $VERSION" || true
git -C "$ROOT" push origin main
git -C "$ROOT" tag "v$VERSION"
git -C "$ROOT" push origin "v$VERSION"

# 4. Release vía API de GitHub
RELEASE_JSON=$(curl -s -X POST "https://api.github.com/repos/$REPO/releases" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/vnd.github+json" \
  -d "{\"tag_name\":\"v$VERSION\",\"name\":\"v$VERSION\",\"body\":\"Plugin WP WebP Worker $VERSION. Auto-update vía releases de GitHub.\"}")

UPLOAD_URL=$(echo "$RELEASE_JSON" | python3 -c "import json,sys; print(json.load(sys.stdin).get('upload_url','').split('{')[0])" 2>/dev/null || true)

if [ -z "$UPLOAD_URL" ]; then
  echo "No se pudo crear el release. Respuesta de GitHub:"
  echo "$RELEASE_JSON"
  exit 1
fi

# 5. Subir el ZIP como asset
curl -s -X POST "$UPLOAD_URL?name=wp-webp-worker-$VERSION.zip" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/zip" \
  --data-binary "@$ZIP" \
  -o /dev/null

echo ""
echo "✅ Release v$VERSION creado con el ZIP. Los sitios con el plugin verán la actualización."
