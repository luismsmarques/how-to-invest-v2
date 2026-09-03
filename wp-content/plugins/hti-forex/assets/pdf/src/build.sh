#!/usr/bin/env bash
# Regenerate the INR lot-size cheat sheet PDF from cheat-sheet.html.
#
# Chromium's print-to-pdf keeps <a href> as real PDF link annotations, which is
# what makes the sheet's links (and the partner link) clickable on a computer.
# Commit both the source and the generated PDF — the plugin ships the PDF.
#
# Two optional partner images, both fail-safe — with no file the marker renders
# nothing, so the sheet is never published with a broken image:
#
#   src/xm-600x90.png  the 600x90 creative from the affiliate panel, injected
#                      at the <!--XM_BANNER--> marker on page 1. It links
#                      through /forex/go/cheatsheet-banner/, a separate
#                      placement from the text block's /forex/go/cheatsheet/,
#                      so the two are told apart in the affiliate panel and in
#                      our own click counts.
#   src/xm-logo.png    the partner wordmark, ~7mm tall when printed (240px wide
#                      is plenty), injected beside the "Partner - Ad" label in
#                      the page-2 block. Without it the block stays unnamed,
#                      which is the honest fallback if the deal changes and
#                      nobody rebuilds the sheet.
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
LOGO="xm-logo.png"
SRC="cheat-sheet.html"
TMP=".build-cheat-sheet.html"
CHECK=".build-check.html"
OUT="../hti-forex-lot-size-cheat-sheet.pdf"

trap 'rm -f "$TMP" "$CHECK"' EXIT

# Compose the render source: each image's markup replaces its marker when the
# file exists, otherwise the marker disappears.
BANNER_FILE="$BANNER" LOGO_FILE="$LOGO" python3 compose.py "$SRC" "$TMP"

"$BIN" --headless --no-sandbox --disable-gpu \
	--print-to-pdf="$OUT" --no-pdf-header-footer \
	"file://$PWD/$TMP"

# --- The check this script exists for -----------------------------------------
# The footer is absolutely positioned, so content that grows does not push it:
# it runs UNDERNEATH it, and the result is a two-page PDF whose second page is
# an unreadable overlap. That is invisible in the page count and invisible in
# the HTML. The only way to catch it is to measure, in a browser, the gap
# between the last block and the footer — every build, not when someone
# remembers.
python3 probe.py "$TMP" "$CHECK"

GAPS=$("$BIN" --headless --no-sandbox --disable-gpu --window-size=794,1123 \
	--virtual-time-budget=2000 --dump-dom "file://$PWD/$CHECK" 2>/dev/null \
	| grep -o '<title>[^<]*</title>' | sed 's/<[^>]*>//g')

PAGES=$(python3 -c 'import re,sys;print(len(re.findall(rb"/MediaBox", open(sys.argv[1],"rb").read())))' "$OUT")

echo "Pages:  $PAGES (must be 2)"
echo "Gap to footer: $GAPS px (must be positive)"

FAIL=0
[ "$PAGES" = "2" ] || { echo "FAIL: the sheet must be exactly 2 pages." >&2; FAIL=1; }
for g in $GAPS; do
	case "${g#*=}" in
		-*) echo "FAIL: page ${g%%=*} is printing over its footer." >&2; FAIL=1 ;;
	esac
done
[ "$FAIL" = "0" ] || exit 1

echo "Wrote $(cd .. && pwd)/hti-forex-lot-size-cheat-sheet.pdf"
