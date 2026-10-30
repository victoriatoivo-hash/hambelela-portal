"""Employee report UI delta using the portal's established FTP baseline/backup/rollback workflow."""
import difflib, ftplib, hashlib, io, json, os, secrets, subprocess, sys, zipfile

BASE = '2bfe4ff3a2f3b25cd6eca3fffd390d06200068a4'
NEW_FILES = ('shared/epi/OwnerFrontCoverage.php', 'apps/operations/front-coverage-settings.php')
FILES = NEW_FILES + ('apps/operations/front-coverage.php', 'apps/operations/my-account.php')
REPORT = 'performance-report-workspace-report.json'
BACKUP = 'performance-report-workspace-backup.zip'
def blob(ref, path): return subprocess.check_output(['git','show',ref+':'+path])
def digest(data): return None if data is None else hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest()
def read(ftp,path):
    out=io.BytesIO();ftp.retrbinary('RETR '+path,out.write);return out.getvalue()
def read_target(ftp,path):
    try: return read(ftp,path)
    except ftplib.error_perm as error:
        if path not in NEW_FILES or not str(error).startswith('550'): raise
        # A missing new file is allowed only after a successful parent listing.
        parent=path.rsplit('/',1)[0]
        names=ftp.nlst(parent)
        if any(name.rsplit('/',1)[-1]==path.rsplit('/',1)[-1] for name in names): raise
        return None
def publish(ftp,path,data):
    temp=path+'.delta-'+secrets.token_hex(8)
    ftp.storbinary('STOR '+temp,io.BytesIO(data))
    if read(ftp,temp)!=data: raise RuntimeError('Staged file verification failed: '+path)
    ftp.rename(temp,path)
    if read(ftp,path)!=data: raise RuntimeError('Published file verification failed: '+path)
def main():
    mode,sha=sys.argv[1:]
    if mode not in ('preflight','deploy'): raise RuntimeError('Invalid mode')
    if subprocess.check_output(['git','rev-parse','HEAD']).decode().strip()!=sha: raise RuntimeError('Pinned commit mismatch')
    expected={p:blob(sha,p) for p in FILES}
    baseline={p:None if p in NEW_FILES else blob(BASE,p) for p in FILES}
    report={'state':'started','sha':sha,'files':list(FILES),'mode':mode,'record_changes':False}
    ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
    attempted=[];before={}
    try:
        ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
        before={p:read_target(ftp,p) for p in FILES}
        report['before']={p:digest(v) for p,v in before.items()}
        conflicts=[p for p in FILES if digest(before[p]) not in (digest(baseline[p]),digest(expected[p]))]
        if conflicts:
            report.update(state='blocked-live-drift',conflicts=conflicts)
            if mode=='preflight' and conflicts==['apps/operations/my-account.php']:
                # Owner explicitly approved this navigation source comparison in the private log.
                p=conflicts[0]
                print(''.join(difflib.unified_diff(blob('17aae6e9',p).decode().splitlines(True),before[p].decode().splitlines(True),fromfile='Git Settings',tofile='Live Settings')))
            raise RuntimeError('Live drift; no files changed: '+', '.join(conflicts))
        report['state']='preflight-pass'
        if mode=='deploy':
            with zipfile.ZipFile(BACKUP,'x') as z:
                for p,data in before.items():
                    if data is not None:z.writestr(p,data)
                z.writestr('manifest.json',json.dumps(report))
            with zipfile.ZipFile(BACKUP) as z:
                for p,data in before.items():
                    if data is not None and z.read(p)!=data:raise RuntimeError('Backup verification failed')
            for p in FILES:
                if digest(before[p])==digest(expected[p]):continue
                if read_target(ftp,p)!=before[p]:raise RuntimeError('Concurrent live edit: '+p)
                attempted.append(p);publish(ftp,p,expected[p])
            report.update(state='verified-live',changed=attempted,after={p:digest(read(ftp,p)) for p in FILES})
            if any(report['after'][p]!=digest(expected[p]) for p in FILES):raise RuntimeError('Final verification failed')
    except Exception:
        if attempted:
            failed=[]
            for p in reversed(attempted):
                try:
                    current=read_target(ftp,p)
                    if current!=before[p] and current!=expected[p]:raise RuntimeError('Concurrent modification; manual recovery required')
                    if current!=before[p]:
                        if before[p] is None:ftp.delete(p)
                        else:publish(ftp,p,before[p])
                except Exception:failed.append(p)
            report.update(state='rollback-incomplete' if failed else 'rolled-back',rollback_failures=failed)
        raise
    finally:
        ftp.close()
        with open(REPORT,'w') as f:json.dump(report,f,indent=2)
        print(json.dumps(report))
if __name__=='__main__':main()
