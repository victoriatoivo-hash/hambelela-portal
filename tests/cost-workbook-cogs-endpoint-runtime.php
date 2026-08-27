<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/cost-workbook-cogs.php';
require_once dirname(__DIR__) . '/shared/cost-workbook-cogs-endpoint.php';

$assertions = 0;
$failures = [];
$check = static function (string $name, bool $passed) use (&$assertions, &$failures): void {
    $assertions++;
    echo ($passed ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$passed) $failures[] = $name;
};

$source = static fn(int $id): array => [
    'sale_size_id' => $id, 'calculation_version_id' => 17, 'version_status' => 'confirmed',
    'confirmed_at' => '2026-08-12 12:00:00', 'classification' => 'simple', 'entity_type' => 'product', 'entity_id' => 41,
    'parent_id' => null, 'product_name' => 'Synthetic product', 'variation_name' => '',
    'attributes' => '{}', 'confirmed_cost' => 14.5,
];

$scenario = static function (array $options = []) use ($source): array {
    $state = (object) ['reads' => 0, 'puts' => 0, 'audits' => [], 'adapter_reached' => false];
    $role = $options['role'] ?? 'owner';
    $feature = $options['feature'] ?? true;
    $currentCost = $options['current_cost'] ?? 12.3456;
    $returnedCost = $options['returned_cost'] ?? 14.5;
    $adapterError = $options['adapter_error'] ?? null;
    $sourceCallback = $options['source'] ?? $source;
    $nonce = $options['nonce'] ?? 'valid';
    $adapter = new class($state, $feature, $currentCost, $returnedCost, $adapterError) {
        public function __construct(private object $state, private bool $feature, private ?float $current, private float $returned, private ?Throwable $error) {}
        public function read(string $type, int $id, ?int $parent): array {
            $this->state->adapter_reached = true; $this->state->reads++;
            if ($this->error) throw $this->error;
            return ['feature_enabled'=>$this->feature,'entity_type'=>$type,'entity_id'=>$id,'defined_cost'=>$this->current,'effective_cost'=>$this->current,'total_cost'=>$this->current,'inheritance_mode'=>'direct','defined_value_is_additive'=>null,'verified'=>false];
        }
        public function updateVerified(string $type, int $id, ?int $parent, float $cost, ?float $expected): array {
            $before = $this->read($type, $id, $parent);
            if (!$before['feature_enabled']) throw new DomainException('woocommerce_cogs_disabled');
            if (($before['defined_cost'] === null) !== ($expected === null) || ($expected !== null && abs($before['defined_cost'] - $expected) >= 0.000001)) throw new DomainException('woocommerce_cogs_stale');
            $this->state->puts++;
            if (abs($this->returned - $cost) >= 0.000001) throw new RuntimeException('woocommerce_cogs_verification_failed');
            $before['defined_cost'] = $this->returned; $before['verified'] = true; return $before;
        }
    };
    $dependencies = [
        'authorize' => static function () use ($role): void {
            if ($role === 'guest') throw new RuntimeException('authentication_required');
            if (!in_array($role, ['owner', 'admin'], true)) throw new RuntimeException('permission_denied');
        },
        'verify_nonce' => static function () use ($nonce): void { if ($nonce !== 'valid') throw new RuntimeException('invalid_nonce'); },
        'source' => $sourceCallback,
        'adapter' => static fn() => $adapter,
        'user' => static fn(): array => ['id'=>1,'name'=>'Synthetic Owner'],
        'audit' => static function (string $action, int $id, array $before, array $after, array $user) use ($state): void { $state->audits[] = $action; },
    ];
    $method = $options['method'] ?? 'GET';
    $request = $options['request'] ?? ['sale_size_id' => 9];
    return [cw3_handle($method, $request, $dependencies), $state];
};

[$response,$state]=$scenario(['role'=>'owner']); $check('owner financial read succeeds',$response['status']===200&&$state->adapter_reached);
[$response,$state]=$scenario(['role'=>'admin']); $check('admin financial read succeeds',$response['status']===200&&$state->adapter_reached);
$publish=['method'=>'POST','request'=>['sale_size_id'=>9,'confirmed'=>true,'expected_current_cost'=>12.3456]];
[$response,$state]=$scenario(['role'=>'owner']+$publish); $check('owner eligible publish reaches adapter',$response['status']===200&&$state->puts===1);
[$response,$state]=$scenario(['role'=>'admin']+$publish); $check('admin eligible publish reaches adapter',$response['status']===200&&$state->puts===1);
foreach(['employee','capabilityless'] as $role){[$response,$state]=$scenario(['role'=>$role]);$check($role.' financial read fails',$response['status']===403&&!$state->adapter_reached);}
[$response,$state]=$scenario(['role'=>'employee']+$publish); $check('employee publish fails',$response['status']===403&&$state->puts===0);
[$response,$state]=$scenario(['role'=>'guest']); $check('logged-out request fails',$response['status']===403&&!$state->adapter_reached);
foreach(['employee','guest'] as $role){[$response]=$scenario(['role'=>$role]);$encoded=strtolower(json_encode($response));$check($role.' response isolates financial data',!preg_match('/landed|cogs|profit|margin|markup|consumer_|secret|publish|restore|retry|woocommerce/', $encoded));}
[$response,$state]=$scenario($publish); $check('valid nonce succeeds',$response['status']===200&&$state->puts===1);
foreach(['missing','invalid','malformed'] as $nonce){[$response,$state]=$scenario(['nonce'=>$nonce]+$publish);$check($nonce.' nonce fails',$response['status']===403&&$state->puts===0);}
[$response]=$scenario(['method'=>'PATCH']); $check('unsupported method fails',$response['status']===405);
[$response]=$scenario(['role'=>'guest','method'=>'POST','request'=>[]]); $check('direct invocation without authorization fails',$response['status']===403);
foreach([null,0,-1,'1.5','abc'] as $id){[$response,$state]=$scenario(['request'=>['sale_size_id'=>$id]]);$check('invalid sale-size id rejected: '.var_export($id,true),$response['body']['code']==='invalid_entity_id'&&!$state->adapter_reached);}
[$response]=$scenario(['request'=>['sale_size_ids'=>[9,10]]]); $check('multiple lines rejected atomically',$response['body']['code']==='single_line_required');
foreach([null,'bundle'] as $type){[$response,$state]=$scenario(['source'=>static fn(int $id):array=>['sale_size_id'=>$id,'calculation_version_id'=>17,'version_status'=>'confirmed','confirmed_at'=>'x','classification'=>'simple','entity_type'=>$type,'entity_id'=>41,'product_name'=>'Synthetic','variation_name'=>'','attributes'=>'{}','confirmed_cost'=>14.5]]);$check('missing or invalid entity type fails',$response['body']['code']==='exact_entity_required'&&!$state->adapter_reached);}
[$response,$state]=$scenario(['source'=>static fn(int $id):array=>['sale_size_id'=>$id,'calculation_version_id'=>17,'version_status'=>'confirmed','confirmed_at'=>'x','classification'=>'simple','entity_type'=>'product','entity_id'=>0,'product_name'=>'Synthetic','variation_name'=>'','attributes'=>'{}','confirmed_cost'=>14.5]]); $check('missing exact entity id fails',$response['body']['code']==='exact_entity_required'&&!$state->adapter_reached);
foreach([['simple','variation'],['variation','product']] as [$classification,$type]){[$response,$state]=$scenario(['source'=>static fn(int $saleId):array=>['sale_size_id'=>$saleId,'calculation_version_id'=>17,'version_status'=>'confirmed','confirmed_at'=>'x','classification'=>$classification,'entity_type'=>$type,'entity_id'=>81,'parent_id'=>40,'product_name'=>'Synthetic','variation_name'=>'','attributes'=>'{}','confirmed_cost'=>14.5]]);$check($classification.' and entity type mismatch fails',$response['body']['code']==='exact_entity_required'&&!$state->adapter_reached);}
[$response,$state]=$scenario(['source'=>static fn(int $id):array=>throw new DomainException('confirmed_cost_required')]); $check('missing confirmed source fails',$response['body']['code']==='confirmed_cost_required'&&!$state->adapter_reached);
foreach([['draft','x'],['confirmed',null]] as [$status,$confirmedAt]){[$response,$state]=$scenario(['source'=>static fn(int $id):array=>['sale_size_id'=>$id,'calculation_version_id'=>17,'version_status'=>$status,'confirmed_at'=>$confirmedAt,'classification'=>'simple','entity_type'=>'product','entity_id'=>41,'product_name'=>'Synthetic','variation_name'=>'','attributes'=>'{}','confirmed_cost'=>14.5]]);$check('mutable or unconfirmed source fails',$response['body']['code']==='confirmed_cost_required'&&!$state->adapter_reached);}
[$response,$state]=$scenario(['current_cost'=>10.0]+$publish); $check('stale value fails before PUT',$response['body']['code']==='woocommerce_cogs_stale'&&$state->puts===0);
[$response,$state]=$scenario(['feature'=>false]+$publish); $check('disabled COGS returns required code',$response['body']['code']==='woocommerce_cogs_disabled'); $check('disabled COGS performs no PUT',$state->puts===0); $check('disabled COGS queues no audit or batch line',$state->audits===[]); $check('disabled COGS causes no partial execution',$state->puts===0&&$state->audits===[]);
[$response,$state]=$scenario(['returned_cost'=>99.0]+$publish); $check('returned-value mismatch fails verification',$response['body']['code']==='woocommerce_cogs_verification_failed'&&$state->puts===1); $check('failed mutation is not reported successful',$response['body']['ok']===false);
[$response,$state]=$scenario(['adapter_error'=>new RuntimeException('consumer_secret=synthetic-secret https://example.invalid/auth')]); $encoded=json_encode($response); $check('adapter exception is safely normalized',$response['body']['code']==='woocommerce_cogs_request_failed'); $check('raw response credentials and authenticated URL absent',!str_contains($encoded,'synthetic-secret')&&!str_contains($encoded,'example.invalid'));
[$response,$state]=$scenario(['returned_cost'=>99.0]+$publish); $check('no automatic mutation retry occurs',$state->puts===1);

$passed=$assertions-count($failures);
echo "Phase 3 endpoint runtime assertions: {$passed}/{$assertions} passed." . PHP_EOL;
exit($failures ? 1 : 0);
