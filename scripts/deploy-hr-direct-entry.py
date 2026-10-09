"""One-file direct HR routing delta, run in cPanel's authenticated terminal.

Uses the same exact-SHA, audited baseline, private backup and readback guards as
the portal FTP delta. No application code or records in the old root are removed.
"""
import hashlib, json, os, sys, tempfile, urllib.request
from pathlib import Path

sha = sys.argv[1]
if len(sha) != 40 or any(c not in '0123456789abcdef' for c in sha):
    raise SystemExit('An exact commit SHA is required')
target = Path('/home/hambele1/hr.hambelelaorganic.com/.htaccess')
if target.parent.resolve() != Path('/home/hambele1/hr.hambelelaorganic.com') or target.is_symlink():
    raise SystemExit('Unexpected direct HR destination')
base = 'https://raw.githubusercontent.com/victoriatoivo-hash/hambelela-portal/'+sha+'/'
with urllib.request.urlopen(base+'standalone/hr-entry/.htaccess',timeout=30) as response:
    desired=response.read()
with urllib.request.urlopen(base+'scripts/hr-direct-entry-baseline.json',timeout=30) as response:
    baseline=json.load(response)
before=target.read_bytes() if target.exists() else None
digest=lambda data: hashlib.sha256(data).hexdigest() if data is not None else None
if before != desired and digest(before) != baseline['sha256']:
    raise SystemExit('Direct HR .htaccess changed since audit; no upload')
if before==desired:
    print('Direct HR entry already matches approved release');sys.exit(0)
backup=Path('/home/hambele1/.hr-interface-backups')/sha
backup.mkdir(parents=True,exist_ok=True,mode=0o700)
os.chmod(backup.parent,0o700)
if before is not None:(backup/'direct-hr.htaccess').write_bytes(before)
(backup/'manifest.json').write_text(json.dumps({'before':digest(before),'approved_sha':sha,'after':digest(desired)}))
if (target.read_bytes() if target.exists() else None)!=before:
    raise SystemExit('Direct HR entry drifted during preflight')
fd,stage=tempfile.mkstemp(prefix='.hr-entry-',dir=str(target.parent))
try:
    with os.fdopen(fd,'wb') as handle:handle.write(desired)
    os.chmod(stage,0o644);os.replace(stage,target)
    if target.read_bytes()!=desired:raise RuntimeError('Direct HR routing readback mismatch')
except Exception:
    if before is None:target.unlink(missing_ok=True)
    else:target.write_bytes(before)
    raise
finally:
    if os.path.exists(stage):os.unlink(stage)
print(json.dumps({'state':'deployed_and_verified','target':str(target),'sha256':digest(desired),'backup':str(backup)}))
