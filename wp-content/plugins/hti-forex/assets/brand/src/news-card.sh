#!/usr/bin/env bash
# Render one news card, 2560x1440, in the bot's visual language.
#
# The card is data, not design: every string arrives as an argument, and the
# comparison chip is derived from the two numbers rather than typed — so a card
# can never announce a beat while printing a miss.
#
#   ./news-card.sh \
#     --out ../us-ism-2026-09-01.png \
#     --eyebrow "United States - 1 September 2026" \
#     --head "US factories|held their pace" \
#     --value 55.4 --vs 55.2 --vslabel consensus \
#     --label "ISM Manufacturing PMI, August" \
#     --sub "previous 55.6" \
#     --note "Above 50 = expanding - educational only"
#
# Only --out, --value and --label are required. Use | in --head for a line break.
# Leave --vs out and the comparison chip disappears.
set -euo pipefail
cd "$(dirname "$0")"

OUT=""; EYEBROW=""; HEAD=""; VALUE=""; LABEL=""; SUB=""; NOTE="educational only"
VS=""; VSLABEL="consensus"

while [ $# -gt 0 ]; do
	case "$1" in
		--out) OUT="$2"; shift 2 ;;
		--eyebrow) EYEBROW="$2"; shift 2 ;;
		--head) HEAD="$2"; shift 2 ;;
		--value) VALUE="$2"; shift 2 ;;
		--label) LABEL="$2"; shift 2 ;;
		--sub) SUB="$2"; shift 2 ;;
		--note) NOTE="$2"; shift 2 ;;
		--vs) VS="$2"; shift 2 ;;
		--vslabel) VSLABEL="$2"; shift 2 ;;
		*) echo "Unknown option: $1" >&2; exit 1 ;;
	esac
done

[ -n "$OUT" ] && [ -n "$VALUE" ] && [ -n "$LABEL" ] || {
	echo "--out, --value and --label are required. See the header of this file." >&2
	exit 1
}

BIN="${CHROMIUM:-}"
if [ -z "$BIN" ]; then
	for c in /opt/pw-browsers/chromium chromium chromium-browser google-chrome; do
		if [ -x "$c" ]; then BIN="$c"; break; fi
		if command -v "$c" >/dev/null 2>&1; then BIN="$(command -v "$c")"; break; fi
	done
fi
[ -n "$BIN" ] || { echo "No chromium found. Set CHROMIUM=/path/to/chrome." >&2; exit 1; }

QUERY=$(OUT="$OUT" EYEBROW="$EYEBROW" HEAD="$HEAD" VALUE="$VALUE" LABEL="$LABEL" \
	SUB="$SUB" NOTE="$NOTE" VS="$VS" VSLABEL="$VSLABEL" python3 query.py)

NODE_PATH="${NODE_PATH:-/opt/node22/lib/node_modules}" \
CHROMIUM="$BIN" CARD_QUERY="$QUERY" CARD_OUT="$OUT" node render.js

echo "Wrote $OUT"
