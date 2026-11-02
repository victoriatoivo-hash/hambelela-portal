"""Employee report UI delta using the portal's established FTP baseline/backup/rollback workflow."""
import ftplib, hashlib, io, json, os, secrets, subprocess, sys, zipfile

BASE = '483eaecf'
BASES = {}
NEW_FILES = ('apps/operations/orders-trash-permission.php',)
FILES = NEW_FILES + ('apps/operations/orders-board-action.php','apps/operations/orders-board-data.php')
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
    temp=path+'.delta-'+secrets.token_hex(8)+'.php'
    ftp.storbinary('STOR '+temp,io.BytesIO(data))
    if read(ftp,temp)!=data: raise RuntimeError('Staged file verification failed: '+path)
    ftp.rename(temp,path)
    if read(ftp,path)!=data: raise RuntimeError('Published file verification failed: '+path)
def main():
    mode,sha=sys.argv[1:]
    if mode not in ('preflight','deploy'): raise RuntimeError('Invalid mode')
    if subprocess.check_output(['git','rev-parse','HEAD']).decode().strip()!=sha: raise RuntimeError('Pinned commit mismatch')
    expected={p:blob(sha,p) for p in FILES}
    baseline={p:None if p in NEW_FILES else blob(BASES.get(p,BASE),p) for p in FILES}
    report={'state':'started','sha':sha,'files':list(FILES),'mode':mode,'record_changes':False}
    ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
    attempted=[];before={}
    try:
        ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
        before={p:read_target(ftp,p) for p in FILES}
        # Apply the reviewed hunks to the current live copy; never replace unrelated
        # newer board functionality with a historical repository file.
        import tempfile, pathlib
        for p in FILES:
            if p in NEW_FILES:continue
            old,new=baseline[p],expected[p]
            if before[p] is None:raise RuntimeError('Missing board file: '+p)
            with tempfile.TemporaryDirectory() as tmp:
                target=pathlib.Path(tmp,p);target.parent.mkdir(parents=True)
                original=before[p].replace(b'\r\n',b'\n')
                target.write_bytes(original)
                patch=subprocess.check_output(['git','diff','--no-ext-diff','--no-color','-U1',BASE,sha,'--',p])
                if not patch:raise RuntimeError('Missing release hunk: '+p)
                opts=['git','apply','--whitespace=nowarn']
                if p.endswith('board-data.php'):
                    # Newer live includes sit adjacent to the same operations bootstrap.
                    # Match complete unique statements, irrespective of their neighbours.
                    lines=original.splitlines(keepends=True)
                    bootstrap=b"require_once __DIR__ . '/operations.php';"
                    helper=b"require_once __DIR__ . '/orders-trash-permission.php';"
                    def swap(statement,replacement):
                        hits=[i for i,line in enumerate(lines) if line.rstrip()==statement]
                        if len(hits)!=1: raise RuntimeError('Unexpected live permission/require statement: '+p)
                        lines[hits[0]]=replacement+b'\n'
                    if helper not in original: swap(bootstrap,bootstrap+b'\n'+helper)
                    for key in [b'can_move_to_trash',b'can_delete']:
                        prefix=b"    '"+key+b"' => "
                        lookup=lambda data:next(line for line in data.splitlines() if line.startswith(prefix))
                        approved=lookup(new)
                        if approved not in lines and approved+b'\n' not in lines:swap(lookup(old),approved)
                    target.write_bytes(b''.join(lines))
                elif subprocess.run(opts+['--reverse','--check'],cwd=tmp,input=patch,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode!=0:
                    subprocess.run(opts+['--check'],cwd=tmp,input=patch,check=True)
                    subprocess.run(opts,cwd=tmp,input=patch,check=True)
                subprocess.run(['php','-l',str(target)],check=True)
                result=target.read_bytes()
                if p.endswith('action.php'):
                    for guard in [b'$canDelete = ops_board_can_move_to_trash();',b"'can_delete_forever' => user_has_role('owner_admin')",b'order_moved_to_trash', b'if (!ops_board_can_move_to_trash())']:
                        if guard not in result:raise RuntimeError('Unrecognised live guard: '+p)
                expected[p]=result.replace(b'\n',b'\r\n') if before[p].count(b'\r\n')==before[p].count(b'\n') else result
                baseline[p]=before[p]
        report['before']={p:digest(v) for p,v in before.items()}
        conflicts=[p for p in FILES if digest(before[p]) not in (digest(baseline[p]),digest(expected[p]))]
        if conflicts:
            report.update(state='blocked-live-drift',conflicts=conflicts)
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
