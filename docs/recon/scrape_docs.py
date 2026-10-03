#!/usr/bin/env python3
"""Scrape the per-endpoint API documentation pages into a single spec file."""
import html
import re
import subprocess
import sys
from pathlib import Path

BASE = 'https://mfcw.782778.xyz/document'
OUT = Path('/tmp/recon/api_v1_spec.txt')
CACHE = Path('/tmp/recon/docpages')
CACHE.mkdir(parents=True, exist_ok=True)

links = [line.strip() for line in Path('/tmp/recon/doc_links.txt').read_text().splitlines() if line.strip()]


def fetch(query):
    name = re.sub(r'[^A-Za-z0-9_=]+', '_', query)
    path = CACHE / f'{name}.html'
    if not path.exists():
        subprocess.run(
            ['curl', '-s', '--max-time', '30', f'{BASE}{query}', '-o', str(path)],
            check=False,
        )
    return path.read_text(encoding='utf-8', errors='replace')


def extract(raw):
    s = re.sub(r'(?s)<(script|style|nav)\b.*?</\1>', '', raw)
    # Grab the tab-pane content blocks that hold the actual endpoint docs.
    panes = re.findall(r'(?s)<div[^>]*class="[^"]*tab-pane[^"]*"[^>]*>(.*?)(?=<div[^>]*class="[^"]*tab-pane|</body>)', s)
    if not panes:
        panes = [s]
    chunks = []
    for pane in panes:
        pane = re.sub(r'(?i)<(br|/p|/div|/tr|/h[1-6]|/li|/table|/thead|/tbody)[^>]*>', '\n', pane)
        pane = re.sub(r'(?i)</?(td|th)[^>]*>', ' | ', pane)
        pane = re.sub(r'<[^>]+>', '', pane)
        pane = html.unescape(pane)
        pane = re.sub(r'[ \t]*\|[ \t]*(\|[ \t]*)+', ' | ', pane)
        pane = re.sub(r'[ \t]+', ' ', pane)
        pane = re.sub(r'\n\s*\n+', '\n', pane).strip()
        if len(pane) > 20:
            chunks.append(pane)
    return '\n'.join(chunks)


blocks = []
for query in links:
    raw = fetch(query)
    text = extract(raw)
    title = re.search(r'API\s*-\s*(.+)', text)
    header = title.group(1).strip() if title else query
    blocks.append(f'\n{"="*90}\n### {query}  —  {header}\n{"="*90}\n{text}')

OUT.write_text('\n'.join(blocks), encoding='utf-8')
print(f'wrote {OUT} with {len(blocks)} endpoints, {OUT.stat().st_size} bytes')
