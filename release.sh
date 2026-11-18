#!/usr/bin/env bash
# ============================================================================
# KutBlog · Release Script (Compatible con Submódulos y Repositorios Standalone)
# ============================================================================
# Uso:
#   ./release.sh 2.1.12              → release con notas vacías
#   ./release.sh 2.1.12 "Changelog"  → release con notas
#   ./release.sh patch               → auto-bump patch (2.1.11 → 2.1.12)
#   ./release.sh minor               → auto-bump minor (2.1.11 → 2.2.0)
#   ./release.sh major               → auto-bump major (2.1.11 → 3.0.0)
#
# Requisitos: git, gh (GitHub CLI autenticado), python3
# ============================================================================

set -euo pipefail

CMS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$CMS_DIR"

VERSION_FILE="$CMS_DIR/core/config.php"

# Verificar que estamos en un repositorio Git válido
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "❌ Error: No se detectó un repositorio git en $CMS_DIR."
  exit 1
fi

# --- Leer versión actual ---
CURRENT=$(grep -oP "define\('CMS_VERSION',\s*'\K[0-9.]+" "$VERSION_FILE")
echo "📦 Versión actual de KutBlog: v$CURRENT"

# --- Calcular nueva versión ---
if [[ -z "${1:-}" ]]; then
  echo "Uso: $0 <version|patch|minor|major> [notas]"
  echo "  Ejemplos:"
  echo "    $0 patch              → $(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}')"
  echo "    $0 minor              → $(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}')"
  echo "    $0 major              → $(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}')"
  echo "    $0 2.1.12 \"Mi changelog\""
  exit 1
fi

INPUT="$1"
NOTES="${2:-}"

case "$INPUT" in
  patch) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}') ;;
  minor) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}') ;;
  major) NEW=$(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}') ;;
  *)     NEW="$INPUT" ;;
esac

# --- Validar formato de versión ---
if [[ ! "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Error: La versión '$NEW' no es válida. Debe tener formato X.Y.Z o usar: patch, minor, major."
  exit 1
fi

echo "🚀 Nueva versión: v$NEW"
echo ""

# --- Confirmar ---
read -rp "¿Continuar? (s/N) " confirm
if [[ "$confirm" != "s" && "$confirm" != "S" ]]; then
  echo "Cancelado."
  exit 0
fi

# --- 1. Actualizar core/config.php ---
sed -i "s/define('CMS_VERSION', '$CURRENT')/define('CMS_VERSION', '$NEW')/" "$VERSION_FILE"
echo "✅ core/config.php actualizado a $NEW"

# --- 2. Git: stage, commit + tag ---
cd "$CMS_DIR"

# Verificar si hay cambios staged/unstaged
UNSTAGED=$(git status --porcelain | grep -v 'core/config.php' | wc -l || true)

if [[ "$UNSTAGED" -gt 0 ]]; then
  echo "📋 Hay $UNSTAGED archivo(s) modificados sin stage."
  read -rp "¿Incluirlos en el release? (s/N) " inc
  if [[ "$inc" == "s" || "$inc" == "S" ]]; then
    git add -A
  fi
fi

# Siempre incluir core/config.php
git add core/config.php

# Commit
git commit -m "Release KutBlog v$NEW"
git tag -a "v$NEW" -m "KutBlog v$NEW"
echo "✅ Commit y tag v$NEW creados"

# --- 3. Crear el ZIP (solo ficheros de KutBlog, sin datos de usuario ni configs personales) ---
ZIP_NAME="kutblog-v${NEW}.zip"
ZIP_PATH="/tmp/$ZIP_NAME"

STAGE_DIR=$(mktemp -d)
cd "$CMS_DIR"

# Copiar sólo ficheros tracked por git (excluyendo carpetas dinámicas del usuario y la desactualizada cms/)
git ls-files -z \
  | grep -zZv -e '^content/' -e '^media/' -e '^uploads/' -e '^cache/' -e '^config.json' \
    -e '^\.installed' -e '^\.htaccess' -e '^release.*\.sh$' -e '^cms/' -e '^\.git' \
  | xargs -0 -I{} install -D "{}" "${STAGE_DIR}/{}"

# Crear ZIP usando python3 para máxima compatibilidad
cd "$STAGE_DIR"
python3 -c "
import zipfile, os
with zipfile.ZipFile('$ZIP_PATH', 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('.'):
        for file in files:
            filepath = os.path.join(root, file)
            arcname = os.path.relpath(filepath, '.')
            z.write(filepath, arcname)
"

# Regresar SIEMPRE al directorio del proyecto antes de borrar STAGE_DIR
cd "$CMS_DIR"
rm -rf "$STAGE_DIR"

ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
echo "✅ ZIP creado: $ZIP_NAME ($ZIP_SIZE)"

# --- 4. Push al repositorio de KutBlog ---
BRANCH=$(git branch --show-current || git rev-parse --abbrev-ref HEAD || echo "main")
git push origin "$BRANCH"
git push origin "v$NEW"
echo "✅ Pushed to GitHub (rama: $BRANCH)"

# --- 5. Crear GitHub Release con el ZIP adjunto ---
if [[ -z "$NOTES" ]]; then
  PREV_TAG=$(git tag -l 'v*' --sort=-v:refname | grep -v "v$NEW" | head -1 || true)
  if [[ -n "$PREV_TAG" ]]; then
    NOTES=$(git log "${PREV_TAG}..v${NEW}" --oneline --no-merges 2>/dev/null | head -20 || true)
    if [[ -z "$NOTES" ]]; then
      NOTES="Release v$NEW"
    fi
  else
    NOTES="Release v$NEW"
  fi
fi

gh release create "v$NEW" "$ZIP_PATH" \
  --repo "ernestoacostame/kutblog" \
  --title "KutBlog v$NEW" \
  --notes "$NOTES"

# Invalida la caché en el servidor central de actualizaciones inmediatamente
curl -s "https://kutstudio.org/updates/?app=kutblog&action=check&nocache=1" >/dev/null 2>&1 || true

echo ""
echo "✅ Release v$NEW publicada con $ZIP_NAME adjunto"
echo "   https://github.com/ernestoacostame/kutblog/releases/tag/v$NEW"

# --- Limpiar ---
rm -f "$ZIP_PATH"
