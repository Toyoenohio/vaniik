#!/usr/bin/env bash
# Crea un release de GitHub con el ZIP del plugin para auto-update.
#
# Uso: ./release.sh <version>   (ej: ./release.sh 1.0.1)
#
# Requisito: `gh` instalado y autenticado una sola vez con `gh auth login`.
# El repo debe ser público (ya lo es) para que la API de GitHub no pida token
# en los sitios de clientes.

set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Uso: $0 <version>   (ej: $0 1.0.1)"
  exit 1
fi

ROOT="$(cd "$(dirname "$0")" && pwd)"        # raíz del repo (vaniik)
PLUGIN_DIR="$ROOT/plugin"
MAIN_FILE="$PLUGIN_DIR/wp-webp-worker.php"

# 1. Bump de versión (header + constante)
sed -i "s/^ \* Version:.*/ \* Version:           $VERSION/" "$MAIN_FILE"
sed -i "s/define( 'WPWEBP_VERSION',.*/define( 'WPWEBP_VERSION', '$VERSION' );/" "$MAIN_FILE"

# 2. Build del ZIP (archivos del plugin en la raíz del zip)
ZIP="/tmp/wp-webp-worker-$VERSION.zip"
rm -f "$ZIP"
( cd "$PLUGIN_DIR" && zip -r "$ZIP" . -x '*.git*' ) >/dev/null

# 3. Commit + tag + push
git -C "$ROOT" add -A
git -C "$ROOT" commit -m "chore(plugin): bump a $VERSION" || true
git -C "$ROOT" push origin main
git -C "$ROOT" tag "v$VERSION"
git -C "$ROOT" push origin "v$VERSION"

# 4. Release + subir el ZIP como asset
gh release create "v$VERSION" "$ZIP" \
  --title "v$VERSION" \
  --notes "Plugin WP WebP Worker $VERSION. Auto-update vía releases de GitHub."

echo ""
echo "✅ Release v$VERSION creado. Los sitios con el plugin verán la actualización."
