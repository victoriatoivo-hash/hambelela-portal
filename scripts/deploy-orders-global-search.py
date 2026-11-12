"""Apply only the monthly-default delta to the current board, preserving other releases."""
import difflib
import ftplib
import hashlib
import io
import json
import os
from pathlib import Path
import secrets
import socket
import ssl
import subprocess
import sys
import tempfile
import zipfile

paths = ['apps/operations/orders-board.php', 'assets/js/orders-board.js']
mode, sha = sys.argv[1:]
def git(*args):
    return subprocess.check_output(['git', *args])
def norm(raw):
    return raw.replace(b'\r\n', b'\n')
def patched(path, raw):
    old = norm(git('show', 'cfae896f:' + path)).splitlines(True)
    new = norm(git('show', sha + ':' + path)).splitlines(True)
    live = norm(raw)
    for group in difflib.SequenceMatcher(None, old, new, autojunk=False).get_grouped_opcodes(1):
        a = b''.join(old[group[0][1]:group[-1][2]])
        b = b''.join(new[group[0][3]:group[-1][4]])
        if live.count(b) == 1:
            continue
        if live.count(a) != 1:
            raise RuntimeError(f'No upload: live context differs in {path} at line {group[0][1]+1}')
        live = live.replace(a, b, 1)
    return live.replace(b'\n', b'\r\n') if raw.count(b'\n') == raw.count(b'\r\n') else live

if mode not in ('preflight','deploy') or git('rev-parse','HEAD').decode().strip() != sha:
    raise ValueError('Invalid release request')
host = os.environ['FTP_SERVER']
canonical = 's11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=60) as ftp:
    ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    def read(path):
        buf = io.BytesIO()
        ftp.retrbinary('RETR ' + path, buf.write)
        return buf.getvalue()
    def write(path, value):
        tmp = path+'.'+secrets.token_hex(12)+Path(path).suffix
        try:
            ftp.storbinary('STOR '+tmp, io.BytesIO(value))
            if read(tmp) != value:
                raise RuntimeError('Staged file mismatch')
            ftp.rename(tmp,path)
        finally:
            try: ftp.delete(tmp)
            except ftplib.error_perm: pass
    before = {p:read(p) for p in paths}
    after = {p:patched(p,before[p]) for p in paths}
    with tempfile.TemporaryDirectory() as folder:
        for p,data in after.items():
            f=Path(folder,p);f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(data)
            if p.endswith('.php'): subprocess.run(['php','-l',str(f)],check=True)
            if p.endswith('.js'): subprocess.run(['node','--check',str(f)],check=True)
    changed = [p for p in paths if before[p] != after[p]]
    print('Verified live source:',paths,'Files requiring update:',changed,flush=True)
    if mode=='deploy' and changed:
        with zipfile.ZipFile('orders-pagination-backup.zip','x',zipfile.ZIP_DEFLATED) as z:
            for p in changed: z.writestr(p,before[p])
        attempted=[]
        try:
            for p in changed:
                if read(p)!=before[p]: raise RuntimeError('Concurrent update: '+p)
                attempted.append(p)
                write(p,after[p])
            if any(read(p)!=after[p] for p in paths): raise RuntimeError('Final verification failed')
        except Exception:
            for p in reversed(attempted):
                present=read(p)
                if present==after[p]: write(p,before[p])
                elif present!=before[p]: raise RuntimeError('Concurrent change during rollback: '+p)
            raise
    Path('orders-pagination-report.json').write_text(json.dumps({'mode':mode,'files':{
        p:hashlib.sha256(after[p]).hexdigest() for p in paths}}))
