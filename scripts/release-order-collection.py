"""Narrow, baseline-checked FTPS release. The Order Board entry point is published last."""
import ftplib
import io
import os
import secrets
import socket
import ssl
import subprocess
from pathlib import Path

base = "4235bbdf"
paths = [
    "shared/delivery/DeliveryService.php", "shared/delivery/DeliveryArrangement.php",
    "shared/delivery/PortalOrderRepository.php", "shared/delivery/CoreApi.php",
    "apps/delivery/order-collection.php", "assets/css/order-collection.css",
    "assets/js/order-collection-ui.js", "assets/js/orders-board.js",
]
new = set(paths[4:7])
def original(p):
    return None if p in new else subprocess.check_output(["git","show",base+":"+p])
def norm(b):
    return None if b is None else b.replace(b"\r\n",b"\n")
def digest(b):
    import hashlib
    return "absent" if b is None else hashlib.sha256(norm(b)).hexdigest()
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
    bad=[p for p in paths if norm(before[p]) not in (norm(original(p)),norm(desired[p]))]
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
    print("Verified live bytes for", len(paths), "allowlisted files; no schema or customer data writes.")
