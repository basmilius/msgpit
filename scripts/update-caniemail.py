#!/usr/bin/env python3
import json
import pathlib
import urllib.request

url = 'https://www.caniemail.com/api/data.json'
with urllib.request.urlopen(url, timeout=30) as response:
    data = json.load(response)
if not isinstance(data.get('data'), list) or not data['data']:
    raise SystemExit('The response contains no caniemail features.')
target = pathlib.Path(__file__).resolve().parents[1] / 'data' / 'caniemail.json'
temporary = target.with_suffix('.json.tmp')
temporary.write_text(json.dumps(data, separators=(',', ':')) + '\n')
temporary.replace(target)
print(f'Updated {len(data["data"])} caniemail features.')
