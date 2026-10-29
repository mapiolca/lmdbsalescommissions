"""Explicit live API checks on the two fictitious develop quotes used by the recipe.

Requires a separately authorized existing token file, which is removed on exit.
No API token is accepted in a URL, printed, or saved in the result report.
"""
import argparse
import json
from pathlib import Path
import urllib.error
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--phase', required=True, choices=['unlocked', 'pending'])
parser.add_argument('--token-file', required=True, type=Path)
args = parser.parse_args()
base = 'https://develop.lesmetiersdubatiment.fr/api/index.php'
records = []

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

opener = urllib.request.build_opener(NoRedirect())
try:
    token = args.token_file.read_text(encoding='utf-8').strip()
    if not token:
        raise RuntimeError('Existing token missing')

    def request(method, path, data=None, expected=200):
        if not (path == '/proposals/47' or path.startswith('/proposals/47/')):
            raise RuntimeError('Only fictitious quote 47 is mutable in this recipe')
        payload = None if data is None else json.dumps(data).encode()
        req = urllib.request.Request(base + path, data=payload, method=method, headers={
            'DOLAPIKEY': token, 'DOLAPIENTITY': '1', 'Accept': 'application/json',
            'Accept-Encoding': 'identity', 'Content-Type': 'application/json',
        })
        try:
            with opener.open(req, timeout=30) as response:
                status, raw = response.status, response.read(262144)
        except urllib.error.HTTPError as error:
            status, raw = error.code, error.read(262144)
        parsed = json.loads(raw)
        error_data = parsed.get('error') if isinstance(parsed, dict) else None
        message = error_data.get('message', '') if isinstance(error_data, dict) else parsed.get('message', '') if isinstance(parsed, dict) else ''
        records.append({'method': method, 'path': path, 'expected': expected, 'actual': status,
                        'message': str(message).replace(token, '[redacted]')[:300]})
        print(json.dumps(records[-1], ensure_ascii=False), flush=True)
        if status != expected:
            raise RuntimeError('Unexpected HTTP result; further mutations stopped')
        return parsed

    def fingerprint(quote):
        return {key: quote.get(key) for key in ['id', 'status', 'statut', 'total_ht', 'note_public', 'lines', 'array_options']}

    before = request('GET', '/proposals/47')
    if int(before.get('status', before.get('statut', -1))) != 0:
        raise RuntimeError('The fictitious quote must remain a native draft')
    if args.phase == 'unlocked':
        request('PUT', '/proposals/47', {'note_public': before.get('note_public', '')})
        request('PUT', '/proposals/47', {'status': 1}, 409)
        request('PUT', '/proposals/47', {'notrigger': 1}, 409)
        request('POST', '/proposals/47/validate', {'notrigger': 1}, 409)
        request('POST', '/proposals/47/validate', {'notrigger': 0}, 409)
        request('POST', '/proposals/47/close', {'status': 2, 'notrigger': 0}, 409)
    else:
        request('PUT', '/proposals/47', {'note_public': 'RECETTE API — modification interdite pendant attente'}, 409)
        line = before['lines'][0]
        line_id = int(line.get('id', line.get('rowid', 0)))
        if line_id <= 0:
            raise RuntimeError('Native line identifier missing')
        request('PUT', f'/proposals/47/lines/{line_id}', {'qty': 2}, 409)
        request('POST', '/proposals/47/lines', {'desc': 'RECETTE API — ligne interdite', 'qty': 1, 'subprice': 1, 'tva_tx': 0}, 409)
        request('POST', '/proposals/47/validate', {'notrigger': 1}, 409)
        request('POST', '/proposals/47/close', {'status': 2, 'notrigger': 0}, 409)
    after = request('GET', '/proposals/47')
    if fingerprint(before) != fingerprint(after):
        raise RuntimeError('Proposal data changed; manual review required')
    print('Native proposal status, economics, technical data, lines and public note preserved.')
finally:
    args.token_file.unlink(missing_ok=True)
    output = Path(__file__).resolve().parent / '.tools' / ('live-api-' + args.phase + '.json')
    output.write_text(json.dumps(records, ensure_ascii=False, indent=2), encoding='utf-8')
