"""Narrow baseline-checked errand/quiet-refresh release; preserve unrelated live pages."""
import ftplib
import io
import os
import secrets
import socket
import ssl
import subprocess
from pathlib import Path

base = "ad016fc8b5069c1ca740c8b8146fd7d76d1ec1ea"
new_paths = ["shared/delivery/DriverInbox.php","shared/delivery/DriverInboxPage.php","assets/css/driver-inbox.css","assets/js/driver-inbox.js"]
paths = new_paths + ["assets/css/driver-errands.css","assets/css/driver-delivery-ui.css","assets/js/driver-errands.js","shared/delivery/NotificationOutbox.php","shared/delivery/DeliveryService.php","apps/operations/operations.php","notifications.php","assets/js/driver-delivery-ui.js"]
def original(p):
    if p in new_paths:return None
    return subprocess.check_output(["git","show",base+":"+p])
def norm(b):
    return None if b is None else b.replace(b"\r\n",b"\n")
def digest(b):
    import hashlib
    return "absent" if b is None else hashlib.sha256(norm(b)).hexdigest()
def overlay(path,live):
    # Production has independent releases of these shared files. Only the one
    # reviewed hunk is permitted, and its exact structural anchor must be unique.
    import difflib
    anchors={"notifications.php":b"require_login();", "shared/delivery/NotificationOutbox.php":b"if($driver&&$kind==='assignment')$title=", "apps/operations/operations.php":b"db()->prepare('INSERT INTO order_payment_allocation_audit ", "shared/delivery/DeliveryService.php":b"if($action==='revised')NotificationOutbox::queue("}
    if path not in anchors or not live:return None
    a=norm(original(path)).splitlines(True); b=norm(Path(path).read_bytes()).splitlines(True)
    hunks=[(i,j,k,l) for op,i,j,k,l in difflib.SequenceMatcher(None,a,b,autojunk=False).get_opcodes() if op!='equal']
    if len(hunks)!=1:raise ValueError("Unexpected hunks in "+path)
    i,j,k,l=hunks[0]; old=b''.join(a[i:j]); new=b''.join(b[k:l]);anchor=anchors[path]
    if not old:
        if a[i-1].lstrip().startswith(anchor):old=a[i-1];new=old+new
        elif a[i].lstrip().startswith(anchor):old=a[i];new=new+old
        else:raise ValueError("Unexpected insertion in "+path)
    elif not old.lstrip().startswith(anchor):raise ValueError("Unexpected replacement in "+path)
    src=norm(live)
    if src.count(new)==1:return live
    if src.count(old)!=1:raise ValueError("Live anchor changed: "+path)
    out=src.replace(old,new,1)
    return out.replace(b'\n',b'\r\n') if b'\r\n' in live and b'\n' not in live.replace(b'\r\n',b'') else out
host=os.environ["FTP_SERVER"]
canonical="s11745.sgp1.stableserver.net"
if set(socket.gethostbyname_ex(canonical)[2]) & set(socket.gethostbyname_ex(host)[2]):host=canonical
with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=45) as ftp:
    ftp.login(os.environ["FTP_USERNAME"],os.environ["FTP_PASSWORD"]);ftp.prot_p()
    def read(p):
        data=io.BytesIO()
        try:ftp.retrbinary("RETR "+p,data.write)
        except ftplib.error_perm as e:
            if not str(e).startswith("550") or Path(p).name in {Path(x).name for x in ftp.nlst(str(Path(p).parent))}:raise
            return None
        return data.getvalue()
    def put(p,b,prior):
        tmp=p+"."+secrets.token_hex(12)+Path(p).suffix
        try:
            ftp.storbinary("STOR "+tmp,io.BytesIO(b))
            if read(tmp)!=b or read(p)!=prior:raise RuntimeError("Concurrent edit or transfer failure: "+p)
            ftp.rename(tmp,p)
        finally:
            try:ftp.delete(tmp)
            except ftplib.error_perm:pass
    before={p:read(p) for p in paths};desired={p:Path(p).read_bytes() for p in paths}
    for p in paths:
        if digest(before[p]) not in (digest(original(p)),digest(desired[p])):
            merged=overlay(p,before[p])
            if merged is None:raise RuntimeError("Unrecognised live baseline; nothing published: "+p+" "+digest(before[p]))
            desired[p]=merged
    for p in paths:
        if p.endswith('.php'):subprocess.run(['php','-l'],input=desired[p],check=True)
    for p in ["apps/delivery/front-api.php","shared/epi/BusinessTimeEngine.php","shared/epi/Support.php"]:
        if not read(p):raise RuntimeError("Missing delivery dependency: "+p)
    changed=[]
    try:
        for p in paths:
            if norm(before[p])==norm(desired[p]):continue
            put(p,desired[p],before[p]);changed.append(p)
            if read(p)!=desired[p]:raise RuntimeError("Readback failed: "+p)
    except BaseException:
        for p in reversed(changed):
            if read(p)!=desired[p]:raise RuntimeError("Concurrent edit during rollback: "+p)
            if before[p] is None:ftp.delete(p)
            else:put(p,before[p],desired[p])
        raise
    print("Verified live bytes for", len(paths), "allowlisted files; no live errands submitted.")
