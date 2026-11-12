"""Run on the normal portal network. Only acknowledge after all public checks pass."""
import hashlib
import json
import re
import secrets
import subprocess
import sys
from urllib.parse import urlparse, parse_qs

repo='victoriatoivo-hash/hambelela-portal'
release='6ef38d0b96153ae9e6e8a6aaee106000c0ef9938'
run,attempt=sys.argv[1:]
if not all(re.fullmatch(r'[0-9]+',v) for v in (run,attempt)): raise ValueError('Invalid run')
base='https://portal.hambelelaorganic.com'
def curl(path):
    result=subprocess.check_output(['curl','--proto','=https','--proto-redir','=https','-sSL','--max-time','20','-w','\n%{http_code}\n%{url_effective}',base+path+'?release_check='+secrets.token_hex(6)])
    body,code,url=result.rsplit(b'\n',2)
    parsed=urlparse(url.decode().strip())
    if parsed.netloc!='portal.hambelelaorganic.com' or parsed.scheme!='https': raise RuntimeError('Unexpected destination')
    return body,code.strip(),parsed
body,code,url=curl('/login.php')
if code!=b'200' or url.path!='/login.php' or b'You stay signed in on this browser for up to 90 days' not in body:
    raise RuntimeError('New login page not verified')
body,code,url=curl('/assets/js/portal-presence.js')
expected=subprocess.check_output(['git','cat-file','blob',release+':assets/js/portal-presence.js'])
if code!=b'200' or hashlib.sha256(body.replace(b'\r\n',b'\n')).digest()!=hashlib.sha256(expected).digest():
    raise RuntimeError('Presence JavaScript does not match the exact release')
body,code,url=curl('/index.php')
if code!=b'200' or url.path!='/login.php' or not parse_qs(url.query).get('return'):
    raise RuntimeError('Anonymous dashboard access check failed')
body,code,url=curl('/apps/operations/portal-presence.php')
if code!=b'401' or json.loads(body).get('resume_dashboard') is not False: raise RuntimeError('Anonymous presence check failed')
subprocess.run(['gh','api','--method','POST',f'repos/{repo}/statuses/{release}','-f','state=success','-f',f'context=live-session-http/{run}/{attempt}','-f','description=Live login, exact presence JS, protected dashboard and anonymous API verified','--silent'],check=True)
print('Live sign-in, presence asset, dashboard protection, and employee data privacy verified.')
