"""Existing FTPS delta workflow: compare Git baseline, back up replaced files, rollback."""
import ftplib, hashlib, io, json, os, subprocess, sys, zipfile
from pathlib import Path

BASELINE = '29ebccf6'
NEW_FILES = []
FILES = ['assets/js/portal.js','assets/js/cost-workbook-controls.js']
DEPENDENCIES = []
# Preserve current live revisions matched to Git; only FILES are uploaded.
DEPENDENCY_BASELINES = {}
def git(*args): return subprocess.check_output(['git', *args])
def digest(data): return None if data is None else hashlib.sha256(data).hexdigest()
def read(ftp, path):
    data = io.BytesIO()
    try: ftp.retrbinary('RETR '+path, data.write)
    except ftplib.error_perm as exc:
        if path not in NEW_FILES or not str(exc).startswith('550'): raise
        if Path(path).name in [Path(p).name for p in ftp.nlst(str(Path(path).parent).replace('\\','/'))]: raise
        return None
    return data.getvalue()
def put(ftp, path, data): ftp.storbinary('STOR '+path, io.BytesIO(data))

mode, sha = sys.argv[1:3]
assert mode in ('preflight','deploy') and git('rev-parse','HEAD').decode().strip() == sha
report = {'sha':sha, 'mode':mode, 'state':'checking', 'files':FILES, 'hashes':{}}
ftp = ftplib.FTP_TLS(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD']); ftp.prot_p()
try:
    before = {p:read(ftp,p) for p in FILES+DEPENDENCIES}
    desired = {p:git('show', sha+':'+p) for p in FILES}
    # The global portal script may contain newer release changes outside this menu.
    # Transplant just the reviewed opening-focus statement, never the whole Git file.
    import tempfile
    p=FILES[0]
    anchor=b'      position();\n      focusSelectedOption(focusPreference);\n    });'
    next_anchor=b"      position();\n      // Searchable menus manage their own opening focus. Don't steal the\n      // caret (or an arrow-key selection) on the next animation frame.\n      if (!popupElement.querySelector('[data-portal-opening-focus]')) focusSelectedOption(focusPreference);\n    });"
    source=before[p].replace(b'\r\n',b'\n')
    if source.count(anchor)+source.count(next_anchor)!=1:raise RuntimeError('Unrecognised live menu opener; nothing deployed')
    assert git('show',BASELINE+':'+p).replace(anchor,next_anchor)==desired[p]
    desired[p]=source.replace(anchor,next_anchor)
    if b'\r\n' in before[p]:
        if before[p].count(b'\r\n')!=before[p].count(b'\n'):raise RuntimeError('Mixed line endings; nothing deployed')
        desired[p]=desired[p].replace(b'\n',b'\r\n')
    with tempfile.TemporaryDirectory() as tmp:
        for path,buf in desired.items():
            staged=Path(tmp)/Path(path).name;staged.write_bytes(buf);subprocess.run(['node','--check',str(staged)],check=True)
    conflicts = []
    for p,data in before.items():
        baseline = None if p in NEW_FILES else git('show', DEPENDENCY_BASELINES.get(p, BASELINE)+':'+p)
        if p==FILES[0]:baseline=before[p] # verified exact live opener above
        approved = desired.get(p, baseline)
        # Git checkout line-ending differences do not change PHP/JS/CSS semantics.
        norm = lambda value:None if value is None else value.replace(b'\r\n',b'\n')
        matches = norm(data) in (norm(baseline),norm(approved))
        report['hashes'][p] = {'live':digest(data),'baseline':digest(baseline),'approved':digest(approved),'matches':matches}
        if not matches: conflicts.append(p)
    if conflicts:
        report['state']='blocked-live-baseline-mismatch';report['conflicts']=conflicts
        raise RuntimeError('Newer/different live files; nothing uploaded: '+', '.join(conflicts))
    report['state']='preflight-pass'
    if mode=='deploy':
        changed=[p for p in FILES if norm(before[p])!=norm(desired[p])]
        with zipfile.ZipFile('supplier-costs-deployment-backup.zip','w',zipfile.ZIP_DEFLATED) as backup:
            for p in changed:
                if before[p] is not None: backup.writestr(p,before[p])
            backup.writestr('manifest.json',json.dumps(report))
        attempted=[]
        try:
            for p in changed:
                if read(ftp,p)!=before[p]: raise RuntimeError('Concurrent live change: '+p)
                attempted.append(p);put(ftp,p,desired[p])
                if read(ftp,p)!=desired[p]: raise RuntimeError('Upload verification failed: '+p)
        except Exception:
            for p in reversed(attempted):
                current=read(ftp,p)
                if current==desired[p] or (current is not None and desired[p].startswith(current)):
                    if before[p] is None: ftp.delete(p)
                    else: put(ftp,p,before[p])
                elif current!=before[p]: raise RuntimeError('Concurrent change prevents safe rollback: '+p)
            report['state']='rolled-back';raise
        report['state']='deployed-and-verified';report['changed']=changed
finally:
    Path('supplier-costs-deployment-report.json').write_text(json.dumps(report,indent=2))
    print(json.dumps(report))
    ftp.quit()
