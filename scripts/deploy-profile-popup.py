"""Pinned profile-popover-only delta release. Refuses unknown live file contents."""
import argparse, ftplib, hashlib, io, json, os, subprocess, sys, zipfile
APPROVED = "4998ed767fc569514162961210c939d2a7b98af0"
BASELINE = "c5d1e1e1fbfe2db738e8a1480120e1f59e72a09a"
COMMIT_FILES = ["assets/css/profile-menu.css","assets/js/profile-menu.js","shared/profile-menu.php","shared/header.php","shared/footer.php"]
DEPLOY_FILES = [p for p in COMMIT_FILES if not p.startswith("tests/")]
def git(*args): return subprocess.check_output(["git", *args])
def blob(ref,p):
    result=subprocess.run(["git","show",ref+":"+p],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode: return None
    return result.stdout
def baseline_blob(p): return blob(BASELINE,p)
def digest(data): return hashlib.sha256(data).hexdigest() if data is not None else None
def same(a,b): return a==b or (a is not None and b is not None and a.replace(b"\r\n",b"\n")==b.replace(b"\r\n",b"\n"))
def report(state,**extra):
    with open("profile-popup-report.json","w") as out: json.dump(dict(state=state,sha=APPROVED,base=BASELINE,files=DEPLOY_FILES,**extra),out,indent=2)
def validate(sha):
    if sha!=APPROVED: raise RuntimeError("Unapproved commit")
    actual=git("diff","--name-only",BASELINE,sha).decode().splitlines()
    if sorted(actual)!=sorted(COMMIT_FILES): raise RuntimeError("Manifest mismatch")
    result={p:blob(sha,p) for p in DEPLOY_FILES}
    if any(v is None for v in result.values()): raise RuntimeError("Missing release file")
    for p,data in result.items():
        if p.endswith(".php"): subprocess.run(["php","-l"],input=data,check=True,stdout=subprocess.PIPE)
        if p.endswith(".js"): subprocess.run(["node","--check"],input=data,check=True,stdout=subprocess.PIPE)
    return result
def read(ftp,p):
    out=io.BytesIO()
    try: ftp.retrbinary("RETR "+p,out.write)
    except ftplib.error_perm as exc:
        if not str(exc).startswith("550"): raise
        # A successful directory listing must independently confirm absence.
        names=ftp.nlst(p.rsplit("/",1)[0] if "/" in p else ".")
        if p.split("/")[-1] in [n.rstrip("/").split("/")[-1] for n in names]: raise
        return None
    return out.getvalue()
def put(ftp,p,data): ftp.storbinary("STOR "+p,io.BytesIO(data))
def deploy(sha):
    wanted=validate(sha)
    ftp=ftplib.FTP(os.environ["FTP_SERVER"],timeout=45)
    ftp.login(os.environ["FTP_USERNAME"],os.environ["FTP_PASSWORD"])
    try:
        before={p:read(ftp,p) for p in DEPLOY_FILES}
        conflicts=[dict(path=p,server=digest(before[p]),base=digest(baseline_blob(p))) for p in DEPLOY_FILES if not same(before[p],baseline_blob(p)) and not same(before[p],wanted[p])]
        if conflicts:
            report("blocked-baseline-mismatch",conflicts=conflicts)
            raise RuntimeError("Unknown live files; no uploads performed: "+str([c["path"] for c in conflicts]))
        with zipfile.ZipFile("profile-popup-backup.zip","w") as z:
            for p,data in before.items():
                if data is not None:z.writestr(p,data)
            z.writestr("rollback-manifest.json",json.dumps({p:dict(existed=v is not None,sha256=digest(v)) for p,v in before.items()},indent=2))
        changed=[p for p in DEPLOY_FILES if before[p]!=wanted[p]]
        # Assets/new dependencies first; shared loader before entry points.
        changed.sort(key=lambda p:(0 if p.startswith("assets/") else 1 if before[p] is None else 2 if p.startswith("shared/") else 3,p))
        attempted=[]
        try:
            for p in changed:
                attempted.append(p)
                put(ftp,p,wanted[p])
                if read(ftp,p)!=wanted[p]:raise RuntimeError("Upload verification failed: "+p)
        except Exception:
            failed=[]
            for p in reversed(attempted):
                try:
                    if before[p] is None:ftp.delete(p)
                    else:put(ftp,p,before[p])
                    if read(ftp,p)!=before[p]:raise RuntimeError("Rollback verification")
                except Exception:failed.append(p)
            report("rollback-incomplete" if failed else "rolled-back",rollback_failures=failed,attempted=attempted)
            raise
        report("verified",changed=changed,hashes={p:digest(v) for p,v in wanted.items()})
    finally:
        ftp.close()
if __name__=="__main__":
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--dry-run");group.add_argument("--deploy")
    args=parser.parse_args()
    if args.dry_run:
        validate(args.dry_run)
        print(json.dumps(dict(result="PASS",files=DEPLOY_FILES,count=len(DEPLOY_FILES)),indent=2))
    else:deploy(args.deploy)
