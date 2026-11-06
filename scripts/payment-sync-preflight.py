import ftplib, hashlib, io, os, subprocess, json
paths=['apps/operations/operations.php','apps/operations/orders-board-action.php','apps/operations/sync-orders.php','assets/js/orders-board.js']
ftp=ftplib.FTP(os.environ['FTP_SERVER'],timeout=45)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD'])
for path in paths:
    buf=io.BytesIO();ftp.retrbinary('RETR '+path,buf.write());data=buf.getvalue()
    sha=subprocess.check_output(['git','hash-object','--stdin'],input=data).decode().strip()
    history=subprocess.check_output(['git','log','--all','--format=%H','--',path]).decode().splitlines()
    matches=[]
    for commit in history:
        blob=subprocess.check_output(['git','rev-parse',commit+':'+path]).decode().strip()
        if blob==sha: matches.append(commit);break
    print(json.dumps({'path':path,'sha256':hashlib.sha256(data).hexdigest(),'matching_commit':matches}))
ftp.quit()
