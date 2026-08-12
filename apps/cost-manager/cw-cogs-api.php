<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/shared/auth.php';
require_once BASE_PATH . '/shared/database.php';
require_once BASE_PATH . '/shared/cost-workbook.php';
require_once BASE_PATH . '/shared/woocommerce.php';
require_once BASE_PATH . '/shared/cost-workbook-cogs.php';

require_role('owner_admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function cw3_json(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function cw3_body(): array { $body=json_decode((string)file_get_contents('php://input'),true); return is_array($body)?$body:[]; }
function cw3_source(PDO $pdo, int $saleSizeId): array
{
    $sql="SELECT ss.id sale_size_id,ss.complete_cost_per_sale_unit confirmed_cost,v.id calculation_version_id,v.status version_status,v.confirmed_at,m.classification,m.woo_product_id,m.woo_variation_id,s.parent_product_id,s.product_name,s.variation_name,s.attributes FROM cw_sale_size_costs ss JOIN cw_landed_calculation_lines l ON l.id=ss.calculation_line_id JOIN cw_landed_calculation_versions v ON v.id=l.calculation_version_id JOIN cw_calculation_product_matches m ON m.sale_size_cost_id=ss.id JOIN cw_product_snapshots s ON s.sync_batch_id=m.snapshot_id AND s.product_id=m.woo_product_id AND s.variation_id=COALESCE(m.woo_variation_id,0) WHERE ss.id=? AND v.status='confirmed'";
    $stmt=$pdo->prepare($sql);$stmt->execute([$saleSizeId]);$row=$stmt->fetch();
    if(!$row)throw new DomainException('confirmed_cost_required');
    $cost=(float)$row['confirmed_cost'];if(!is_finite($cost)||$cost<=0)throw new DomainException('confirmed_cost_required');
    if($row['classification']==='variation'&&(int)$row['woo_variation_id']>0){$row['entity_type']='variation';$row['entity_id']=(int)$row['woo_variation_id'];$row['parent_id']=(int)$row['parent_product_id'];}
    elseif($row['classification']==='simple'&&(int)$row['woo_product_id']>0){$row['entity_type']='product';$row['entity_id']=(int)$row['woo_product_id'];$row['parent_id']=null;}
    else throw new DomainException('exact_entity_required');
    $row['confirmed_cost']=$cost;return $row;
}
function cw3_public_source(array $source): array{return ['sale_size_id'=>(int)$source['sale_size_id'],'calculation_version_id'=>(int)$source['calculation_version_id'],'entity_type'=>$source['entity_type'],'entity_id'=>$source['entity_id'],'product_name'=>(string)$source['product_name'],'variation_name'=>(string)$source['variation_name'],'attributes'=>(string)$source['attributes'],'confirmed_cost'=>(float)$source['confirmed_cost']];}

try {
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');if(!in_array($method,['GET','POST'],true))cw3_json(['ok'=>false,'code'=>'method_not_allowed','message'=>'Use GET for preview or POST for confirmed publishing.'],405);
    if($method==='POST')cw_require_csrf();
    $body=$method==='POST'?cw3_body():$_GET;$ids=$body['sale_size_ids']??[$body['sale_size_id']??0];if(!is_array($ids)||count($ids)!==1)throw new DomainException('single_line_required');
    $saleSizeId=(int)$ids[0];if($saleSizeId<1)throw new DomainException('invalid_entity_id');
    $pdo=db();cw_install_schema($pdo);$source=cw3_source($pdo,$saleSizeId);$adapter=new CostWorkbookNativeCogs();$current=$adapter->read($source['entity_type'],$source['entity_id'],$source['parent_id']);
    if(!$current['feature_enabled'])throw new DomainException('woocommerce_cogs_disabled');
    if($method==='GET')cw3_json(['ok'=>true,'publish_available'=>true,'source'=>cw3_public_source($source),'woocommerce'=>$current]);
    if(empty($body['confirmed']))throw new DomainException('explicit_confirmation_required');
    $expected=array_key_exists('expected_current_cost',$body)&&$body['expected_current_cost']!==null?(float)$body['expected_current_cost']:null;
    $user=cw_user();$audit=$pdo->prepare('INSERT INTO cw_cost_audit_events(entity_type,entity_id,action_key,before_json,after_json,reason,actor_id,actor_name) VALUES(?,?,?,?,?,?,?,?)');
    $audit->execute(['sale_size',$saleSizeId,'woocommerce_cogs_publish_started',json_encode($current,JSON_UNESCAPED_SLASHES),json_encode(['confirmed_source'=>cw3_public_source($source)],JSON_UNESCAPED_SLASHES),'Owner-confirmed native WooCommerce COGS publication started',$user['id'],$user['name']]);
    $after=$adapter->updateVerified($source['entity_type'],$source['entity_id'],$source['parent_id'],$source['confirmed_cost'],$expected);
    $audit->execute(['sale_size',$saleSizeId,'woocommerce_cogs_published',json_encode($current,JSON_UNESCAPED_SLASHES),json_encode($after,JSON_UNESCAPED_SLASHES),'Owner-confirmed native WooCommerce COGS publication',$user['id'],$user['name']]);
    cw3_json(['ok'=>true,'source'=>cw3_public_source($source),'woocommerce'=>$after]);
} catch(Throwable $e) {
    $known=['confirmed_cost_required','exact_entity_required','single_line_required','invalid_entity_id','explicit_confirmation_required'];
    if(in_array($e->getMessage(),$known,true))cw3_json(['ok'=>false,'code'=>$e->getMessage(),'message'=>'The confirmed cost request is not eligible for publishing.'],422);
    $safe=CostWorkbookNativeCogs::safeError($e);cw3_json($safe,$safe['code']==='woocommerce_cogs_disabled'?409:502);
}
