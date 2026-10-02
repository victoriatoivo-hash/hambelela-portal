<?php
declare(strict_types=1);
require_once __DIR__.'/../apps/operations/budgeting-model.php';
function check_budget(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$source=['id'=>7,'revision'=>3,'kind'=>'supplier','title'=>'Fourchem','budget_date'=>'2026-09-07','items_json'=>json_encode([
    ['quantity'=>'5kg','item'=>'Oil','cost'=>'425.00'],
    ['quantity'=>'2 packs','item'=>'Unpriced item','cost'=>''],
    ['quantity'=>'1','item'=>'Delivery','cost'=>'0.00'],
]),'created_by'=>12];
$draft=budget_duplicate_draft($source,'2026-11-02');
check_budget($draft['id']===0&&$draft['revision']===0,'Draft must have a new identity.');
check_budget($draft['date']==='2026-11-02'&&$draft['title']==='Fourchem','New date and supplier must be editable.');
check_budget($draft['items']==json_decode($source['items_json'],true),'Copy all quantities, names, blank prices and zero prices.');
check_budget(budget_summary($draft['items'])===['cents'=>42500,'missing'=>1],'Preserve totals and unpriced rows.');
$draft['items'][0]['cost']='500.00';
check_budget(json_decode($source['items_json'],true)[0]['cost']==='425.00','Editing a draft must not mutate its source.');
try {budget_duplicate_draft($source,'2026-02-30');throw new LogicException('Invalid date accepted.');}catch(RuntimeException $expected){}

// Isolated children execute the real page controller against an in-memory DB.
// This checks actual POST routing, CSRF, validation and insert-vs-update behavior.
$case=$argv[1]??'';
if($case==='') {
    foreach(['draft','save','bad-csrf','invalid','missing','edit'] as $scenario) {
        $process=proc_open([PHP_BINARY,__FILE__,$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        check_budget($code===0&&str_contains($output,'PASS '.$scenario),'Controller case failed: '.$scenario.' '.$output.' '.$errors);
    }
    echo "Budget duplicate tests passed: draft, independent save, editable date/items, original preserved, CSRF, validation, missing source, existing edit.\n";
    exit;
}
define('APP_NAME','Budget test');
$records=[7=>$source];$writes=[];$authorized=false;
function require_role($role){check_budget($role==='owner_admin','Existing permission must remain.');$GLOBALS['authorized']=true;}
function current_user(){return ['id'=>42];}
function db(){return new class {
    function exec($sql){} // Existing CREATE IF NOT EXISTS is not executed in this test.
    function lastInsertId(){return '8';}
    function prepare($sql){return new class($sql){
        private array $params=[];
        function __construct(private string $sql){}
        function execute($params){$this->params=$params;
            if(str_starts_with($this->sql,'INSERT')){
                $GLOBALS['writes'][]='insert';
                [$kind,$title,$date,$json,$actor]=$params;
                $GLOBALS['records'][8]=['id'=>8,'kind'=>$kind,'title'=>$title,'budget_date'=>$date,'items_json'=>$json,'created_by'=>$actor];
            }elseif(str_starts_with($this->sql,'UPDATE')){$GLOBALS['writes'][]='update';}
        }
        function fetch($mode){return $GLOBALS['records'][$this->params[0]]??false;}
        function fetchAll($mode){return array_values($GLOBALS['records']);}
        function rowCount(){return 1;}
    };}
};}
$_SESSION=['budget_csrf'=>'test-token'];
$_GET=$case==='edit'?['id'=>7]:['duplicate'=>$case==='missing'?99:7];
$_SERVER['REQUEST_METHOD']=in_array($case,['draft','missing'],true)?'GET':'POST';
$_POST=['csrf'=>$case==='bad-csrf'?'wrong':'test-token','id'=>7,'revision'=>3,'kind'=>'supplier','title'=>'Fourchem November','date'=>'2026-11-02','items'=>[['quantity'=>'6kg','item'=>'Oil','cost'=>'500.00']]];
if($case==='invalid')$_POST['date']='2026-02-30';
register_shutdown_function(function()use($case,$source){
    check_budget($GLOBALS['authorized'],'Permission check must run.');
    if($case==='save') {
        check_budget($GLOBALS['writes']===['insert'],'Duplicate must INSERT even if source ID is submitted.');
        check_budget($GLOBALS['records'][7]===$source,'Original budget must remain unchanged.');
        check_budget($GLOBALS['records'][8]['budget_date']==='2026-11-02','Save the chosen new date.');
        check_budget($GLOBALS['records'][8]['created_by']===42,'Attribute the new budget to the current user.');
        check_budget(json_decode($GLOBALS['records'][8]['items_json'],true)[0]['quantity']==='6kg','Save edits to copied rows.');
    }elseif($case==='edit')check_budget($GLOBALS['writes']===['update'],'Existing edit behavior must stay intact.');
    else {
        check_budget($GLOBALS['writes']===[],'Opening or invalid submission must not create a budget.');
        if($case==='draft')check_budget($GLOBALS['duplicateValues']['id']===0,'GET must prepare a new unsaved draft.');
        else check_budget($GLOBALS['error']!=='','Invalid requests must show an error.');
    }
    echo 'PASS '.$case."\n";
});
$page=file_get_contents(__DIR__.'/../apps/operations/budget-planning.php');
$controller=substr($page,5,strpos($page,'function bh(')-5);
$controller=str_replace(["require_once __DIR__.'/operations.php';","require_once __DIR__.'/budgeting-model.php';"],'',$controller);
eval($controller);
