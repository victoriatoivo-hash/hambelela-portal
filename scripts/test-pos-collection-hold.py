"""Exercise the candidate endpoint using an isolated local fixture only."""
from pathlib import Path
from tempfile import TemporaryDirectory
import http.client,json,os,shutil,socket,subprocess,time
src=Path(__file__).resolve().parents[1]
with TemporaryDirectory() as d:
    p=Path(d);(p/'shared').mkdir();shutil.copy(src/'pos-collection-hold.php',p)
    (p/'config.php').write_text('<?php');(p/'shared/pos-identity.php').write_text("""<?php
function pos_identity_session($db,$token,$now){if($token!=='fixture')throw new RuntimeException;return ['employee_id'=>7];}
function pos_identity_employee($db,$id){return ['role_key'=>'front_desk_admin'];}
""")
    (p/'shared/database.php').write_text("""<?php
class FixturePDO extends PDO {
 function __construct(){parent::__construct('sqlite:'.__DIR__.'/fixture.sqlite');$this->sqliteCreateFunction('NOW',fn()=>'2026-11-17 12:00:00');}
 function prepare(string $sql,array $opts=[]): PDOStatement|false{return parent::prepare(str_replace(' FOR UPDATE','',$sql),$opts);}
}
function db(){return new FixturePDO;}
""")
    import sqlite3
    db=sqlite3.connect(p/'shared/fixture.sqlite');db.execute('CREATE TABLE ops_orders(id INTEGER PRIMARY KEY,woo_order_id INTEGER, status TEXT, deleted_at TEXT, archived_at TEXT, archived_by INTEGER, archive_reason TEXT, fulfilment_mode TEXT, order_type TEXT, updated_at TEXT, total_amount INTEGER, paid INTEGER)')
    for n,mode,stage in [(1,'collection','packed'),(2,'courier','packed'),(3,'collection','completed'),(4,'collection','in_progress')]:
        db.execute('INSERT INTO ops_orders(id,woo_order_id,status,fulfilment_mode,total_amount,paid) VALUES(?,?,?,?,99,1)',(n,n,stage,mode))
    db.execute("UPDATE ops_orders SET archived_at='yesterday',archive_reason='manual' WHERE id=4");db.commit()
    sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
    proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',d],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    def call(op,n=1,token='fixture',tag='a'*32):
        conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5);conn.request('POST','/pos-collection-hold.php',json.dumps(dict(operation=op,token=token,tag=tag,order_id=n)),{'Content-Type':'application/json'});r=conn.getresponse();v=(r.status,json.loads(r.read()));conn.close();return v
    try:
        for _ in range(80):
            try:
                if call('probe')[0]==200:break
            except ConnectionRefusedError:time.sleep(.05)
        assert call('probe')[1]['capability']=='postpone_collection_v1'
        assert call('hold',token='invalid')[0]==403
        assert call('hold')[0]==call('hold')[0]==200
        assert db.execute('SELECT COUNT(*) FROM ops_orders WHERE id=1 AND archived_at IS NULL').fetchone()[0]==0
        assert call('release',tag='b'*32)[0]==409
        assert call('release')[0]==call('release')[0]==200
        assert db.execute('SELECT archived_at,status,total_amount,paid FROM ops_orders WHERE id=1').fetchone()==(None,'packed',99,1)
        assert all(call('hold',n)[0]==409 for n in [2,3,4,99])
        print('PASS: probe, access, duplicate requests, hide/restore, paid total and stage preservation, refusal of unsafe holds')
    finally:proc.terminate();proc.wait()
