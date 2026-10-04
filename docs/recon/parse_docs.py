#!/usr/bin/env python3
"""Parse the scraped API doc pages into a structured spec (JSON + readable text)."""
import html
import json
import re
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
CACHE = BASE_DIR / 'docpages'
OUT_JSON = BASE_DIR / 'api_v1_spec.json'
OUT_TXT = BASE_DIR / 'api_v1_spec.md'


def clean(text: str) -> str:
    text = html.unescape(text)
    text = text.replace('\xa0', ' ')
    text = re.sub(r'<[^>]+>', '', text)
    return re.sub(r'\s+', ' ', text).strip()


def parse_tables(raw: str) -> list[dict]:
    tables = []
    for tbl in re.findall(r'(?s)<table class="table table-bordered">(.*?)</table>', raw):
        header_cells = re.findall(r'(?s)<td[^>]*>(.*?)</td>', tbl.split('</tr>')[0])
        header = [clean(c) for c in header_cells]
        rows = []
        for tr in re.findall(r'(?s)<tr[^>]*>(.*?)</tr>', tbl)[1:]:
            cells = [clean(c) for c in re.findall(r'(?s)<td[^>]*>(.*?)</td>', tr)]
            if not any(cells):
                continue
            if len(header) == len(cells):
                rows.append(dict(zip(header, cells)))
            elif cells:
                rows.append({'col%d' % i: c for i, c in enumerate(cells)})
        if rows:
            tables.append({'header': header, 'rows': rows})
    return tables


def parse_page(path: Path) -> dict:
    raw = path.read_text(encoding='utf-8', errors='replace')
    raw = re.sub(r'(?s)<(script|style)\b.*?</\1>', '', raw)

    query = path.stem.replace('_module=', 'module=').replace('_action=', '&action=')
    module = re.search(r'module=(\w+)', query)
    action = re.search(r'action=(\w+)', query)

    title = re.search(r'(?s)<h\d[^>]*>\s*(.*?)\s*</h\d>', raw)
    text = re.sub(r'(?i)<(br|/p|/div|/tr|/h[1-6]|/li|/table)[^>]*>', '\n', raw)
    text = re.sub(r'<[^>]+>', '', text)
    text = html.unescape(text).replace('\xa0', ' ')
    text = re.sub(r'[ \t]+', ' ', text)
    text = re.sub(r'\n\s*\n+', '\n', text)

    head = re.search(r'API\s*-\s*(.+)', text)
    desc = re.search(r'描述[:：]\s*(.+)', text)
    version = re.search(r'版本[:：]\s*(\S+)', text)
    url = re.search(r'(?:接口地址|请求地址|URL)[:：]\s*(\S+)', text)

    return {
        'module': module.group(1) if module else '',
        'action': action.group(1) if action else '',
        'title': head.group(1).strip() if head else '',
        'description': desc.group(1).strip() if desc else '',
        'version': version.group(1).strip() if version else '',
        'url': url.group(1).strip() if url else '',
        'tables': parse_tables(raw),
    }


spec = {}
for path in sorted(CACHE.glob('*.html')):
    page = parse_page(path)
    key = f"{page['module']}.{page['action']}"
    spec[key] = page

OUT_JSON.write_text(json.dumps(spec, ensure_ascii=False, indent=1), encoding='utf-8')

lines = []
for key, page in spec.items():
    lines.append(f"\n## {key}  {page['title']}")
    if page['url']:
        lines.append(f"URL: {page['url']}")
    if page['description']:
        lines.append(f"描述: {page['description']}")
    for tbl in page['tables']:
        lines.append('  ' + ' | '.join(tbl['header']))
        for row in tbl['rows'][:80]:
            lines.append('  ' + ' | '.join(str(v) for v in row.values()))
OUT_TXT.write_text('\n'.join(lines), encoding='utf-8')

print(f'{len(spec)} endpoints -> {OUT_JSON} ({OUT_JSON.stat().st_size}b), {OUT_TXT}')
