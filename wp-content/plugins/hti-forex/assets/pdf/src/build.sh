#!/usr/bin/env bash
# Regenerate the INR lot-size cheat sheet PDF from cheat-sheet.html.
#
# Chromium's print-to-pdf keeps <a href> as real PDF link annotations, which is
# what makes the sheet's links (and the partner link) clickable on a computer.
# Commit both the source and the generated PDF — the plugin ships the PDF.
#
# The partner banner is optional. Drop the creative from the affiliate panel at
# src/xm-600x90.png (600x90, PNG or JPG renamed to .png) and it is injected at
# the <!--XM_BANNER--> marker on page 1; with no file the marker renders
# nothing, so the sheet is never published with a broken image. The banner
# links through /forex/go/cheatsheet-banner/, a separate placement from the
# text block's /forex/go/cheatsheet/, so the two are told apart in the
# affiliate panel and in our own click counts.
#
#   ./build.sh                 # uses the first chromium found
#   CHROMIUM=/path/to/chrome ./build.sh
set -euo pipefail

cd "$(dirname "$0")"

BIN="${CHROMIUM:-}"
if [ -z "$BIN" ]; then
	for candidate in chromium chromium-browser google-chrome /opt/pw-browsers/chromium; do
		if command -v "$candidate" >/dev/null 2>&1; then BIN="$candidate"; break; fi
		if [ -x "$candidate" ]; then BIN="$candidate"; break; fi
	done
fi
if [ -z "$BIN" ]; then
	echo "No chromium found. Set CHROMIUM=/path/to/chrome and re-run." >&2
	exit 1
fi

BANNER="xm-600x90.png"
SRC="cheat-sheet.html"
TMP=".build-cheat-sheet.html"
OUT="../hti-forex-lot-size-cheat-sheet.pdf"

trap 'rm -f "$TMP"' EXIT

# Compose the render source: the banner markup replaces the marker when the
# creative exists, otherwise the marker disappears.
BANNER_FILE="$BANNER" python3 - "$SRC" "$TMP" <<'PY'
import os, sys

src, tmp = sys.argv[1], sys.argv[2]
banner = os.environ['BANNER_FILE']
html = open(src, encoding='utf-8').read()

if os.path.isfile(banner):
    markup = (
        '<div class="adslot">'
        '<span class="adslot__label">Partner &middot; Ad</span>'
        '<a href="https://howtoinvest.pro/forex/go/cheatsheet-banner/">'
        f'<img src="{banner}" width="600" height="90" alt="Advertisement">'
        '</a>'
        '<span class="adslot__risk">Advertising link &mdash; we may be paid if you open an account, '
        'at no cost to you. Forex and CFDs are high-risk leveraged products; most retail accounts lose money.</span>'
        '</div>'
    )
    print(f'Banner: {banner} found — injected on page 1.')
else:
    markup = ''
    print(f'Banner: no {banner} — building without it (text partner block only).')

open(tmp, 'w', encoding='utf-8').write(html.replace('<!--XM_BANNER-->', markup))
PY

"$BIN" --headless --no-sandbox --disable-gpu \
	--print-to-pdf="$OUT" --no-pdf-header-footer \
	"file://$PWD/$TMP"

echo "Wrote $(cd .. && pwd)/hti-forex-lot-size-cheat-sheet.pdf"
