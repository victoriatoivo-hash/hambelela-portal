"""Exercise the real PHP entry point over HTTP; substitute only auth, DB and view boundaries."""
from pathlib import Path
from tempfile import TemporaryDirectory
from http.client import HTTPConnection
from subprocess import Popen, DEVNULL
import shutil, socket, time

with TemporaryDirectory() as tmp:
    root = Path(tmp)
    def put(path, content):
        f = root / path
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_text(content, encoding="utf-8")
    for p in ("apps/delivery/index.php", "shared/delivery/DeliveryPolicy.php"):
        (root / p).parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(p, root / p)
    put("config.php", "<?php define('BASE_PATH',__DIR__); define('BASE_URL',''); define('DELIVERY_ENABLED',true);")
    put("shared/delivery/ReleaseFlags.php", "")
    put("apps/operations/operations.php", """<?php function require_login(){} function db(){return null;}
function current_role_key(){return $_SERVER['HTTP_X_TEST_ROLE'];} function current_user(){return ['id'=>42];}""")
    for cls in ("FrontSession", "EmployeeDriverSession"):
        put("shared/delivery/"+cls+".php", "<?php namespace Hambelela\\Delivery; require_once __DIR__.'/DeliveryPolicy.php'; class "+cls+" {static function actor($db,$id){return ['role'=>\\current_role_key(),'kind'=>'employee','active'=>true,'id'=>$id];}}")
    put("shared/delivery/PortalShell.php","<?php namespace Hambelela\\Delivery; class PortalShell {static function begin(...$args){}}")
    for name in ("CoreView","WorkspaceView","ErrandView"):
        put("shared/delivery/"+name+".php", name)
    with socket.socket() as probe:
        probe.bind(("127.0.0.1",0))
        port=probe.getsockname()[1]
    server=Popen(["php","-S","127.0.0.1:"+str(port),"-t",tmp],stdout=DEVNULL,stderr=DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(("127.0.0.1",port),timeout=.5): break
            except OSError: time.sleep(.05)
        def request(role,query="",method="GET"):
            conn=HTTPConnection("127.0.0.1",port,timeout=3)
            conn.request(method,"/apps/delivery/index.php"+query,headers={"X-Test-Role":role})
            r=conn.getresponse()
            result=(r.status,r.getheader("Location"),r.read().decode())
            conn.close()
            return result
        for role in ("front_desk_admin","front_desk_admin_employee","marketing_sales"):
            assert request(role)==(302,"/apps/delivery/index.php?errands=1",""),role
            assert request(role,"?errands=1")== (200,None,"ErrandView")
            assert request(role,"?errands=data","POST")== (200,None,"ErrandView")
            assert request(role,"?delivery=12")== (200,None,"WorkspaceView")
        assert request("owner_admin")== (200,None,"WorkspaceView")
        assert request("delivery_driver")== (200,None,"CoreView")
        print("PASS front desk and marketing errand landing, driver and owner landing, explicit delivery and errand API routes")
    finally:
        server.terminate()
        server.wait(timeout=5)
