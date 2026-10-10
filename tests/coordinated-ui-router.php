<?php
// Synthetic browser regression harness. Never included in the deployment manifest.
declare(strict_types=1);
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(403);exit;}
$root=dirname(__DIR__);$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($path,'/apps/acknowledgments/')===0){
    session_start();if(isset($_GET['actor']))$_SESSION['test_actor']=(int)$_GET['actor'];$actor=(int)($_SESSION['test_actor']??1);
    if($path==='/apps/acknowledgments/api.php'){
        header('Content-Type: application/json');require $root.'/shared/acknowledgments/Service.php';
        $db=new PDO('mysql:host=127.0.0.1;port=13317;dbname=acknowledgments_test','root',getenv('ACK_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$s=new \Hambelela\Acknowledgments\Service($db);$action=$_GET['action']??'list';
        try{$b=json_decode(file_get_contents('php://input'),true)??[];
            if($action==='send')$result=$s->send($actor,$b);
            elseif($action==='respond'){$s->respond($actor,$b,session_id());$result=['ok'=>true];}
            elseif($action==='detail')$result=$s->detail($actor,(int)$_GET['id']);
            elseif($action==='popup')$result=['notice'=>$s->popup($actor),'pending'=>1,'session'=>hash('sha256',session_id())];
            else $result=$s->listing($actor)+['csrf'=>'synthetic-test'];echo json_encode($result);
        }catch(Throwable $e){http_response_code(400);echo json_encode(['error'=>$e->getMessage()]);}exit;
    }
    $source=file_get_contents($root.'/apps/acknowledgments/index.php');$start=strpos($source,'?><main');$end=strpos($source,'<script src=',$start);
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/portal.css"><link rel="stylesheet" href="/assets/css/acknowledgments.css"></head><body>'.substr($source,$start+2,$end-$start-2).'<script src="/assets/js/acknowledgments.js"></script></body></html>';exit;
}
if(strpos($path,'/assets/')===0){$file=realpath($root.$path);if(!$file||strpos(str_replace('\\','/',$file),str_replace('\\','/',$root).'/assets/')!==0){http_response_code(404);exit;}header('Content-Type: '.(['css'=>'text/css','js'=>'application/javascript','woff2'=>'font/woff2','svg'=>'image/svg+xml'][pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream'));readfile($file);exit;}
if($path==='/payment'){
    $js=file_get_contents($root.'/assets/js/orders-board.js');$start=strpos($js,'  function closePaymentEditor()');$end=strpos($js,'  function labelCellStyle(',$start);$editor=substr($js,$start,$end-$start);
    ?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/portal.css"><link rel="stylesheet" href="/assets/css/orders-board.css"><link rel="stylesheet" href="/assets/css/orders-essentials.css"><link rel="stylesheet" href="/assets/css/acknowledgments.css"></head><body class="ess-dashboard"><main id="ess-main" class="ess-orders-page"><p>Synthetic payment regression. No live records.</p></main><script src="/assets/js/portal.js"></script><script src="/assets/js/orders-essentials.js"></script><script>
    const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),normalize=v=>String(v||'').toLowerCase(),normaliseOrderColourKey=v=>String(v).replaceAll('_','-'),formatOrderInvoiceReference=v=>v,money=v=>'N$ '+Number(v).toFixed(2),selectorEsc=v=>v;
    const PAYMENT_METHODS=[['cash','Cash'],['card_swipe','Swipe'],['eft','EFT']],ordersCache=[{id:1,order_number:'SYNTHETIC-1',total_amount:100,can_edit_payment:true,payments:[{method:'cash',amount_cents:4000},{method:'eft',amount_cents:4000}]}],body=document.body,syncState=null;
    async function post(){throw Error('Synthetic save failure — inputs must remain intact.');}function renderPaymentBadge(){return '';}
    <?=$editor?>
    openPaymentEditor(1);
    </script></body></html><?php exit;
}
if($path==='/popup'){echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/acknowledgments.css"><h1>Synthetic employee dashboard</h1><a class="ess-module-card" href="/apps/acknowledgments/index.php">Acknowledgments</a><script src="/assets/js/acknowledgments-popup.js" data-base=""></script>';exit;}
http_response_code(404);echo 'Test route not found';
