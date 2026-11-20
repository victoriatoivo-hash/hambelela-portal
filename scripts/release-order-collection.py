"""Narrow baseline-checked errand/quiet-refresh release; preserve unrelated live pages."""
import ftplib
import io
import os
import secrets
import socket
import ssl
import subprocess
from pathlib import Path

base = "c7899d378bb4aec2b6777c0d571444c26185fa26"
new_paths = []
paths = ["assets/js/driver-delivery-ui.js","assets/js/delivery-core.js"]
def original(p):
    if p in new_paths:return None
    return subprocess.check_output(["git","show",base+":"+p])
def norm(b):
    return None if b is None else b.replace(b"\r\n",b"\n")
def digest(b):
    import hashlib
    return "absent" if b is None else hashlib.sha256(norm(b)).hexdigest()
def board_delta(live, local, stock):
    # Keep production-only board edits private and intact. Import ONLY our two tested insertions.
    import difflib
    expected="e4c3d367c3bb50cfbfd21b031323777aabb5b72e0366cf161eea64e5b043a4ac"
    a=norm(stock).splitlines(keepends=True);b=norm(local).splitlines(keepends=True)
    anchors=[b"require_login();\n",b'            <div class="monday-board-head-actions orders-header-actions">\n']
    changes=[(op,i,j,b[k:l]) for op,i,j,k,l in difflib.SequenceMatcher(None,a,b,autojunk=False).get_opcodes() if op!='equal']
    if len(changes)!=2 or any(op!='insert' or a[i-1]!=anchor for (op,i,j,add),anchor in zip(changes,anchors)):raise RuntimeError("Unexpected board patch")
    if live is None:raise RuntimeError("Missing live board")
    out=norm(live)
    for (_,i,j,add),anchor in zip(changes,anchors):
        block=anchor+b''.join(add)
        if out.count(block)==1:out=out.replace(block,anchor,1)
    if digest(out)!=expected:raise RuntimeError("Live board changed again; no files written")
    for (_,i,j,add),anchor in zip(changes,anchors):
        if out.count(anchor)!=1:raise RuntimeError("Non-unique board anchor; no files written")
        out=out.replace(anchor,anchor+b''.join(add),1)
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
        if p.endswith('.php'):subprocess.run(['php','-l'],input=desired[p],check=True)
    bad=[p for p in paths if digest(before[p]) not in (digest(original(p)),digest(desired[p]))]
    for p in bad:print("Unrecognised live baseline:",p,digest(before[p]))
    if bad:raise RuntimeError("No files published; resolve differences first.")
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
