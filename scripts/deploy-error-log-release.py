"""Pinned three-file Error Log presentation release; verify baseline, back up, rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
SHA = '10829b1e5843c3a0a21e5c0677232b5cb761249e'
BASE = 'fc2925a7261f558280e2ef5d346fcf7d2894e3fb'
FILES = ['assets/css/error-log-workspace.css', 'assets/js/error-log-workspace.js', 'apps/operations/errors.php']
def git(*args): return subprocess.check_output(['git', *args])
def blob(ref, path): return git('show', ref + ':' + path)
def read(ftp, path):
    buf = io.BytesIO()
    try: ftp.retrbinary('RETR ' + path, buf.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith('550') and path != 'apps/operations/errors.php': return None
        raise
    return buf.getvalue()
def write(ftp, path, data): ftp.storbinary('STOR ' + path, io.BytesIO(data))
def report(state, **extra):
    with open('error-log-release-report.json', 'w') as out: json.dump(dict(state=state, sha=SHA, files=FILES, **extra), out, indent=2)
assert git('rev-parse', SHA+'^').decode().strip() == BASE
assert set(git('diff-tree','--no-commit-id','--name-only','-r',SHA).decode().splitlines()) == set(FILES)
expected = {p:blob(SHA,p) for p in FILES}
assert blob(BASE,'apps/operations/errors.php').split(b"include BASE_PATH . '/shared/header.php';")[0] == expected['apps/operations/errors.php'].split(b"include BASE_PATH . '/shared/header.php';")[0], 'Error Log backend changed'
if '--deploy' not in sys.argv:
    print('PASS: pinned presentation files only; backend prefix unchanged'); sys.exit(0)
ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
try:
    old = {p:read(ftp,p) for p in FILES}
    for p in FILES:
        baseline = blob(BASE,p)
        if old[p] != baseline and old[p] != expected[p]:
            report('blocked-baseline-mismatch', path=p)
            raise RuntimeError('Live baseline differs: '+p)
    with zipfile.ZipFile('error-log-release-backup.zip','w') as archive:
        for p,data in old.items():
            if data is not None: archive.writestr(p,data)
        archive.writestr('manifest.json',json.dumps({p:hashlib.sha256(data).hexdigest() if data is not None else None for p,data in old.items()}))
    changed=[]
    try:
        for folder in ['assets/css', 'assets/js']:
            try: ftp.mkd(folder)
            except ftplib.error_perm:
                current=ftp.pwd(); ftp.cwd(folder); ftp.cwd(current)
        for p in FILES:
            if old[p] == expected[p]: continue
            changed.append(p)
            write(ftp,p,expected[p])
            if read(ftp,p) != expected[p]: raise RuntimeError('Upload verification failed: '+p)
    except Exception:
        for p in reversed(changed):
            if old[p] is None: ftp.delete(p)
            else: write(ftp,p,old[p])
        report('rolled-back'); raise
    report('verified',hashes={p:hashlib.sha256(data).hexdigest() for p,data in expected.items()})
    print('VERIFIED: pinned Error Log presentation files deployed; backup preserved')
finally: ftp.quit()
