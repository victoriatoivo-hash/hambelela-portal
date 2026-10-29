"""Employee report UI delta using the portal's established FTP baseline/backup/rollback workflow."""
import ftplib, hashlib, io, json, os, secrets, subprocess, sys, zipfile

BASE = '0d4e0c10039bb83c08a8b0d471087c9aef55085e'
FILES = ('apps/operations/kpi-employee.php', 'apps/operations/reports.php', 'apps/operations/performance-timing-settings.php', 'assets/css/performance-dashboard.css', 'assets/css/performance-workspace.css', 'assets/css/employee-performance.css', 'assets/js/reports-business-health.js', 'assets/js/reports-section.js')
REPORT = 'performance-report-workspace-report.json'
BACKUP = 'performance-report-workspace-backup.zip'
def blob(ref, path): return subprocess.check_output(['git','show',ref+':'+path])
def digest(data): return hashlib.sha256(data.replace(b'\r\n',b'\n')).hexdigest()
def read(ftp,path):
    out=io.BytesIO();ftp.retrbinary('RETR '+path,out.write);return out.getvalue()
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
    baseline={p:blob(BASE,p) for p in FILES}
    report={'state':'started','sha':sha,'files':list(FILES),'mode':mode,'record_changes':False}
    ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
    attempted=[];before={}
    try:
        ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
        before={p:read(ftp,p) for p in FILES}
        report['before']={p:digest(v) for p,v in before.items()}
        conflicts=[p for p in FILES if digest(before[p]) not in (digest(baseline[p]),digest(expected[p]))]
        if conflicts:
            report.update(state='blocked-live-drift',conflicts=conflicts)
            raise RuntimeError('Live drift; no files changed: '+', '.join(conflicts))
        report['state']='preflight-pass'
        if mode=='deploy':
            with zipfile.ZipFile(BACKUP,'x') as z:
                for p,data in before.items():z.writestr(p,data)
                z.writestr('manifest.json',json.dumps(report))
            with zipfile.ZipFile(BACKUP) as z:
                for p,data in before.items():
                    if z.read(p)!=data:raise RuntimeError('Backup verification failed')
            for p in FILES:
                if digest(before[p])==digest(expected[p]):continue
                if read(ftp,p)!=before[p]:raise RuntimeError('Concurrent live edit: '+p)
                attempted.append(p);publish(ftp,p,expected[p])
            report.update(state='verified-live',changed=attempted,after={p:digest(read(ftp,p)) for p in FILES})
            if any(report['after'][p]!=digest(expected[p]) for p in FILES):raise RuntimeError('Final verification failed')
    except Exception:
        if attempted:
            failed=[]
            for p in reversed(attempted):
                try:
                    current=read(ftp,p)
                    if current!=before[p] and current!=expected[p]:raise RuntimeError('Concurrent modification; manual recovery required')
                    if current!=before[p]:publish(ftp,p,before[p])
                except Exception:failed.append(p)
            report.update(state='rollback-incomplete' if failed else 'rolled-back',rollback_failures=failed)
        raise
    finally:
        ftp.close()
        with open(REPORT,'w') as f:json.dump(report,f,indent=2)
        print(json.dumps(report))
if __name__=='__main__':main()
