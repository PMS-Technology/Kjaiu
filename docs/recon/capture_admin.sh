#!/bin/bash
# Capture read-only admin GET endpoint response shapes from the original site.
set -u
COOKIE=/tmp/mf_admin.cookies
OUT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/api_admin"
mkdir -p "$OUT/shapes"
BASE=https://mfcw.782778.xyz
while IFS=$'\t' read -r rule ctrl; do
  [ -z "$rule" ] && continue
  safe=$(echo "$rule" | tr '/' '_')
  code=$(curl -s -o "$OUT/shapes/$safe.json" -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" --max-time 25 "$BASE/$rule")
  size=$(wc -c < "$OUT/shapes/$safe.json")
  printf '%s\t%s\t%s\t%s\n' "$code" "$size" "$rule" "$ctrl" >> "$OUT/index.tsv"
  sleep 0.15
done < "$OUT/targets.txt"
echo DONE
