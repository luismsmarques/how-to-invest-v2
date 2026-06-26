#!/usr/bin/env bash
# Build the HowToInvest ebook: assemble HTML, then render each language to PDF
# with headless Chromium. Output: ebook-pt.pdf / ebook-en.pdf in this folder.
set -euo pipefail
cd "$(dirname "$0")"

CHROME="${CHROME:-/opt/pw-browsers/chromium-1194/chrome-linux/chrome}"

echo "→ assembling HTML"
node build.mjs

for lang in pt en; do
  echo "→ rendering ebook.$lang.html → ebook-$lang.pdf"
  "$CHROME" --headless --no-sandbox --disable-gpu --hide-scrollbars \
    --no-pdf-header-footer --print-to-pdf="ebook-$lang.pdf" \
    "file://$(pwd)/ebook.$lang.html" 2>/dev/null
done

echo "✓ done"
ls -la ebook-*.pdf
