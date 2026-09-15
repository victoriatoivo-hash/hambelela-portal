"""Pinned six-file login presentation release; verify baseline, back up, rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
SHA = '9fe4919a2a7600e1b3ca8215e14e8989ae222685'
BASE = '1a62b7f56a62ea0d451d4dbfda10241cd86727b5'
FILES = ['assets/css/login-olive.css', 'assets/fonts/jost-regular.ttf', 'assets/fonts/jost-bold.ttf', 'assets/images/login-botanical.png', 'assets/images/login-wordmark.jpg', 'login.php']
def git(*args): return subprocess.check_output(['git', *args])
def blob(ref, path): return git('show', ref + ':' + path)
def read(ftp, path):
    buf = io.BytesIO()
    try: ftp.retrbinary('RETR ' + path, buf.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith('550') and path != 'login.php': return None
        raise
    return buf.getvalue()
def write(ftp, path, data): ftp.storbinary('STOR ' + path, io.BytesIO(data))
def report(state, **extra):
    with open('login-visual-report.json', 'w') as out: json.dump(dict(state=state, sha=SHA, files=FILES, **extra), out, indent=2)
assert git('rev-parse', SHA+'^').decode().strip() == BASE
assert set(git('diff-tree','--no-commit-id','--name-only','-r',SHA).decode().splitlines()) == set(FILES)
expected = {p:blob(SHA,p) for p in FILES}
assert blob(BASE,'login.php').split(b'<!doctype html>')[0] == expected['login.php'].split(b'<!doctype html>')[0], 'Authentication logic changed'
if '--deploy' not in sys.argv:
    print('PASS: six presentation files only; authentication prefix unchanged'); sys.exit(0)
ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
try:
    old = {p:read(ftp,p) for p in FILES}
    for p in FILES:
        baseline = blob(BASE,p) if p=='login.php' else None
        if old[p] != baseline and old[p] != expected[p]:
            report('blocked-baseline-mismatch', path=p)
            raise RuntimeError('Live baseline differs: '+p)
    with zipfile.ZipFile('login-visual-backup.zip','w') as archive:
        for p,data in old.items():
            if data is not None: archive.writestr(p,data)
        archive.writestr('manifest.json',json.dumps({p:hashlib.sha256(data).hexdigest() if data is not None else None for p,data in old.items()}))
    changed=[]
    try:
        for folder in ['assets/fonts']:
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
    print('VERIFIED: six login presentation files deployed; backup preserved')
finally: ftp.quit()
