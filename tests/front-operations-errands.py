"""Real PHP route/policy tests with synthetic authentication; no production writes."""
from pathlib import Path
from tempfile import TemporaryDirectory
from http.client import HTTPConnection
from subprocess import Popen, DEVNULL
import os, shutil, socket, time

with TemporaryDirectory() as tmp:
    root = Path(tmp)
    def put(path, body):
        p = root / path
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(body, encoding='utf-8')
    for p in ['apps/delivery/workspace.php', 'shared/delivery/DeliveryPolicy.php',
              'shared/delivery/ErrandView.php', 'shared/delivery/DriverErrands.php',
              'shared/delivery/WorkspaceApps.php', 'shared/delivery/ErrandManagementAccess.php']:
        (root / p).parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(p, root / p)
    put('config.php', "<?php define('BASE_PATH',__DIR__);define('BASE_URL','');define('DELIVERY_ENABLED',true);")
    put('shared/delivery/ReleaseFlags.php', '')
    put('apps/operations/operations.php', """<?php
function require_login(){if(($_SERVER['HTTP_X_TEST_ROLE']??'')==='guest'){header('Location: /login.php');exit;}}
function current_user(){return ['id'=>42];} function db(){throw new Exception('No DB writes allowed');}
""")
    # Route evaluates db() to obtain the actor; a sentinel is enough for this auth boundary.
    put('apps/operations/operations.php', (root/'apps/operations/operations.php').read_text().replace("throw new Exception('No DB writes allowed');", 'return null;'))
    put('shared/delivery/FrontSession.php', r"""<?php namespace Hambelela\Delivery;
require_once __DIR__.'/DeliveryPolicy.php';
class FrontSession { static function actor($db,$id){return ['id'=>$id,'role'=>$_SERVER['HTTP_X_TEST_ROLE'],
'active'=>empty($_SERVER['HTTP_X_INACTIVE']),'kind'=>'employee','grants'=>['delivery_view','delivery_arrange']];}}
""")
    put('shared/delivery/PartnerAccess.php', '<?php namespace Hambelela\\Delivery; class PartnerAccess {static function can($a,$p){return false;}}')
    put('policy.php', """<?php require 'config.php';require 'shared/delivery/WorkspaceApps.php';
require 'shared/delivery/DriverErrands.php';
$a=['id'=>42,'kind'=>'employee','active'=>empty($_SERVER['HTTP_X_INACTIVE']),
'role'=>$_SERVER['HTTP_X_TEST_ROLE'],'grants'=>['delivery_arrange','delivery_view']];
$allowed=\\Hambelela\\Delivery\\ErrandManagementAccess::allowed($a);
$links=\\Hambelela\\Delivery\\WorkspaceApps::forActor($a);
$front=array_values(array_filter($links,function($l){return $l[2]==='Front Operations';}));
// Exercise the service guard directly without invoking a database query.
$ref=new ReflectionClass(\\Hambelela\\Delivery\\DriverErrands::class);
$obj=$ref->newInstanceWithoutConstructor();$method=$ref->getMethod('front');$method->setAccessible(true);
try{$method->invoke($obj,$a);$service=true;}catch(DomainException $e){$service=false;}
echo json_encode(['allowed'=>$allowed,'service'=>$service,'links'=>$front]);
""")
    put('view.php', """<?php require 'config.php';require 'shared/delivery/DeliveryPolicy.php';
$driverPage=false;$deliveryActor=['id'=>42,'kind'=>'employee','active'=>true,
'role'=>$_SERVER['HTTP_X_TEST_ROLE'],'grants'=>['delivery_arrange']];
function db(){throw new Exception('Unauthorized view reached database');}
require 'shared/delivery/ErrandView.php';
""")
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    server=Popen([os.environ.get('PHP_BINARY','php'),'-S',f'127.0.0.1:{port}','-t',tmp],stdout=DEVNULL,stderr=DEVNULL)
    try:
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1',port),timeout=.3): break
            except OSError: time.sleep(.05)
        def request(role,path='/apps/delivery/workspace.php',method='GET',inactive=False):
            c=HTTPConnection('127.0.0.1',port,timeout=3)
            c.request(method,path,headers={'X-Test-Role':role,'X-Inactive':'1' if inactive else ''})
            r=c.getresponse();out=(r.status,r.getheader('Location'),r.read().decode());c.close();return out
        import json
        allowed=['owner_admin','front_desk_admin','front_desk_admin_employee','marketing_sales']
        denied=['packer','production_staff','delivery_driver','accountant','partner_admin','unknown']
        for role in allowed:
            for suffix in ['', '?delivery=12', '?view=completed']:
                assert request(role,'/apps/delivery/workspace.php'+suffix)==(302,'/apps/delivery/index.php?errands=1','')
            assert request(role,method='HEAD')[:2]==(302,'/apps/delivery/index.php?errands=1')
            assert request(role,method='POST')[0]==405
            p=json.loads(request(role,'/policy.php')[2])
            assert p['allowed'] and p['service'] and p['links'][0][0]=='index.php?errands=1'
            assert request(role,inactive=True)[0]==403
        for role in denied:
            assert request(role)[0]==403
            p=json.loads(request(role,'/policy.php')[2])
            assert not p['allowed'] and not p['service'] and not p['links']
            for method in ['GET','POST']:
                assert request(role,'/view.php?errands=data',method)[0]==403
        assert request('guest')[:2]==(302,'/login.php')
        # Existing driver-specific read/complete paths remain, and the existing view/form is reused.
        service=Path('shared/delivery/DriverErrands.php').read_text()
        assert "if($isDriver)$this->driver($a);else $this->front($a);" in service
        assert 'previewForDriver' in service
        assert 'CoreView.php' not in Path('apps/delivery/workspace.php').read_text()
        print('PASS allowed roles, denied roles even with grants, inactive users, login, GET/HEAD redirects, POST rejection, management API guards, navigation and driver-path preservation')
    finally:
        server.terminate();server.wait(timeout=5)
