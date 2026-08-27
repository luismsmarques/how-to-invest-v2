#!/usr/bin/env bash
# Regenerate the INR lot-size cheat sheet PDF from cheat-sheet.html.
#
# Chromium's print-to-pdf keeps <a href> as real PDF link annotations, which is
# what makes the sheet's links (and the partner link) clickable on a computer.
# Commit both the source and the generated PDF — the plugin ships the PDF.
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

OUT="../hti-forex-lot-size-cheat-sheet.pdf"
"$BIN" --headless --no-sandbox --disable-gpu \
	--print-to-pdf="$OUT" --no-pdf-header-footer \
	"file://$PWD/cheat-sheet.html"

echo "Wrote $(cd .. && pwd)/hti-forex-lot-size-cheat-sheet.pdf"
