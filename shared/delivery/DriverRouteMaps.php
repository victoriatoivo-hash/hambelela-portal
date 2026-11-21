<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/TrackingPolicy.php';
require_once __DIR__.'/DriverLiveRouteData.php';

/** Driver-scoped Google estimates. Explicit foreground location only; no tracking or route-order writes. */
final class DriverRouteMaps
{
    private \PDO $db;private string $key;private $transport;
    public function __construct(\PDO $db,string $key,?callable $transport=null){$this->db=$db;$this->key=$key;$this->transport=$transport;}
    private function rows(string $sql,array $p=[]):array{$s=$this->db->prepare($sql);$s->execute($p);return $s->fetchAll(\PDO::FETCH_ASSOC);}
    private function run(string $sql,array $p=[]):int{$s=$this->db->prepare($sql);$s->execute($p);return $s->rowCount();}
    private function allowance(string $api,int $limit):void
    {
        $day=(new \DateTimeImmutable('now',new \DateTimeZone('Africa/Windhoek')))->format('Y-m-d');
        $this->run('INSERT IGNORE INTO delivery_tracking_api_usage(usage_day,api_name,requests) VALUES(?,?,0)',[$day,$api]);
        if(!$this->run('UPDATE delivery_tracking_api_usage SET requests=requests+1 WHERE usage_day=? AND api_name=? AND requests<?',[$day,$api,$limit]))throw new \DomainException('Daily request limit reached.');
    }
    private function request(string $url,?array $body,array $headers=[]):array
    {
        if($this->transport!==null)return ($this->transport)($url,$body,$headers);
        $c=curl_init($url);$output='';
        curl_setopt_array($c,[CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$output):int{if(strlen($output)+strlen($chunk)>524288)return 0;$output.=$chunk;return strlen($chunk);}]);
        if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
        $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($ok===false||$status!==200)throw new \DomainException('Google routing unavailable. Check key restrictions and billing in Google Cloud.');
        $result=json_decode($output,true,32,JSON_THROW_ON_ERROR);if(!is_array($result))throw new \DomainException('Invalid map response.');return $result;
    }
    /** Bounded, driver-scoped destinations; no GPS stored and no route ordering changed. */
    public function nearby(int $driver,array $point,string $revision):array
    {
        EmployeeDriverSession::actor($this->db,$driver);
        $snapshot=DriverLiveRouteData::read($this->db,$driver);
        if(!hash_equals($snapshot['revision_token'],$revision))throw new \DomainException('Assignments changed. Locate again.');
        $now=time();$fix=TrackingPolicy::point($point,$now);
        if($now-$fix['recorded_at']>120||$fix['accuracy']>250)throw new \DomainException('Location is stale or inaccurate. Try outdoors.');
        $limit=TrackingPolicy::settings([])['route_daily_limit'];
        foreach($this->rows('SELECT settings_json FROM delivery_tracking_devices WHERE employee_id=? AND active=1',[$driver]) as $device)$limit=min($limit,TrackingPolicy::settings(json_decode($device['settings_json'],true)??[])['route_daily_limit']);
        $points=[];$unavailable=0;$attempts=0;$deadline=microtime(true)+8;
        foreach($snapshot['stops'] as $stop){
            if(!$stop['address_display']){$unavailable++;continue;}
            $id=(int)$stop['assignment_id'];$jobs=$this->rows("SELECT address FROM delivery_jobs WHERE id=? AND driver_employee_id=? AND status IN('ready','assigned','accepted','out_for_delivery')",[$id,$driver]);
            if(!$jobs){$unavailable++;continue;}
            $address=trim((string)$jobs[0]['address']);$hash=hash('sha256',$address);
            $this->run('INSERT IGNORE INTO delivery_tracking_routes(delivery_id,address_hash) VALUES(?,?)',[$id,$hash]);
            $cache=$this->rows('SELECT * FROM delivery_tracking_routes WHERE delivery_id=?',[$id])[0];
            if($cache['address_hash']!==$hash||$cache['destination_lat']===null||$now-(int)$cache['geocoded_at']>86400*29){
                if($this->key===''||$attempts>=3||microtime(true)>$deadline||!$this->run('UPDATE delivery_tracking_routes SET route_lock_until=? WHERE delivery_id=? AND route_lock_until<=?',[$now+45,$id,$now])){$unavailable++;continue;}
                $attempts++;
                try{
                    $this->allowance('geocoding',min(30,$limit));
                    $geo=$this->request('https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query(['address'=>$address,'components'=>'country:NA','key'=>$this->key]),null);
                    $rows=$geo['results']??[];$p=$rows[0]['geometry']['location']??[];
                    if(($geo['status']??'')!=='OK'||count($rows)!==1||!empty($rows[0]['partial_match'])||!isset($p['lat'],$p['lng'])||!is_numeric($p['lat'])||!is_numeric($p['lng'])||abs((float)$p['lat'])>90||abs((float)$p['lng'])>180)throw new \DomainException('Address needs review.');
                    $cache['destination_lat']=(float)$p['lat'];$cache['destination_lon']=(float)$p['lng'];
                    $this->run('UPDATE delivery_tracking_routes SET address_hash=?,destination_lat=?,destination_lon=?,geocoded_at=?,calculated_at=NULL,route_lock_until=0 WHERE delivery_id=?',[$hash,$p['lat'],$p['lng'],$now,$id]);
                }catch(\Throwable $e){$this->run('UPDATE delivery_tracking_routes SET route_lock_until=? WHERE delivery_id=?',[$now+180,$id]);$unavailable++;continue;}
            }
            $points[]=['assignment_id'=>$id,'reference'=>$stop['order_reference'],'lat'=>(float)$cache['destination_lat'],'lng'=>(float)$cache['destination_lon']];
        }
        $fresh=DriverLiveRouteData::read($this->db,$driver);
        if(!hash_equals($fresh['revision_token'],$revision))throw new \DomainException('Assignments changed. Locate again.');
        return ['driver_id'=>$driver,'route_revision'=>$revision,'stops'=>$points,'unavailable'=>$unavailable];
    }
    public function route(int $driver,int $delivery,array $point,string $revision):array
    {
        EmployeeDriverSession::actor($this->db,$driver);
        $snapshot=DriverLiveRouteData::read($this->db,$driver);
        if(!hash_equals($snapshot['revision_token'],$revision))throw new \DomainException('Assignments changed. Refresh the route first.');
        $selected=null;foreach($snapshot['stops'] as $stop)if($stop['assignment_id']===$delivery)$selected=$stop;
        if(!$selected||$selected['status']!=='on_route'||!$selected['address_display'])throw new \DomainException('Start an assigned delivery with a confirmed address before requesting its road route.');
        $jobs=$this->rows("SELECT id,address,status FROM delivery_jobs WHERE id=? AND driver_employee_id=? AND status='out_for_delivery'",[$delivery,$driver]);$job=$jobs[0]??null;
        if(!$job)throw new \DomainException('Start delivery first.');
        if($this->key==='')return ['available'=>false,'reason'=>'Google server key is not configured.'];
        $settings=$this->rows('SELECT settings_json FROM delivery_tracking_devices WHERE employee_id=? AND active=1',[$driver]);
        // A browser route does not require a paired tracking device. Reuse the
        // admin policy defaults and shared daily counters, never a new quota.
        $s=TrackingPolicy::settings([]);
        foreach($settings as $setting){$limit=TrackingPolicy::settings(json_decode($setting['settings_json'],true)??[]);$s['route_daily_limit']=min($s['route_daily_limit'],$limit['route_daily_limit']);$s['route_interval_seconds']=max($s['route_interval_seconds'],$limit['route_interval_seconds']);}
        $now=time();$fix=TrackingPolicy::point($point,$now);
        if($now-$fix['recorded_at']>120||$fix['accuracy']>250)throw new \DomainException('Location is stale or inaccurate. Locate again outdoors before requesting a route.');
        $d=['latitude'=>$fix['latitude'],'longitude'=>$fix['longitude'],'last_position'=>$fix['recorded_at']];
        $address=trim((string)$job['address']);if($address==='')return ['available'=>false,'reason'=>'Delivery address is missing.'];$hash=hash('sha256',$address);
        $this->run('INSERT IGNORE INTO delivery_tracking_routes(delivery_id,address_hash) VALUES(?,?)',[$delivery,$hash]);
        $cache=$this->rows('SELECT * FROM delivery_tracking_routes WHERE delivery_id=?',[$delivery])[0];
        // Only permitted geocoded coordinates are cached. Route geometry and
        // duration are delivered for current display, never archived in the DB.
        // https://developers.google.com/maps/documentation/routes/policies
        if($cache['address_hash']===$hash&&$cache['calculated_at']&&$now-(int)$cache['calculated_at']<$s['route_interval_seconds']&&$cache['error_code']===null)return ['available'=>false,'throttled'=>true,'reason'=>'Road estimate refresh is limited to once per configured interval. Please try again shortly.'];
        // One route computation at a time across dashboard tabs and retries.
        if(!$this->run('UPDATE delivery_tracking_routes SET route_lock_until=? WHERE delivery_id=? AND route_lock_until<=?',[$now+45,$delivery,$now]))return ['available'=>false,'reason'=>'Road estimate is refreshing.'];
        try{
            if($cache['address_hash']!==$hash||$cache['destination_lat']===null||$now-(int)$cache['geocoded_at']>86400*29){
                $this->allowance('geocoding',min(30,$s['route_daily_limit']));
                $geo=$this->request('https://maps.googleapis.com/maps/api/geocode/json?'.http_build_query(['address'=>$address,'components'=>'country:NA','key'=>$this->key]),null);
                $results=$geo['results']??[];
                if(($geo['status']??'')!=='OK'||count($results)!==1||!empty($results[0]['partial_match']))throw new \DomainException('Address needs review before road ETA can be calculated.');
                $location=$results[0]['geometry']['location']??[];
                if(!isset($location['lat'],$location['lng'])||!is_numeric($location['lat'])||!is_numeric($location['lng'])||abs((float)$location['lat'])>90||abs((float)$location['lng'])>180)throw new \DomainException('Address coordinates unavailable.');
                $cache['destination_lat']=(float)$location['lat'];$cache['destination_lon']=(float)$location['lng'];
                $this->run('UPDATE delivery_tracking_routes SET address_hash=?,destination_lat=?,destination_lon=?,geocoded_at=?,calculated_at=NULL WHERE delivery_id=?',[$hash,$cache['destination_lat'],$cache['destination_lon'],$now,$delivery]);
            }
            $this->allowance('routes',$s['route_daily_limit']);
            $route=$this->request('https://routes.googleapis.com/directions/v2:computeRoutes',[
                'origin'=>['location'=>['latLng'=>['latitude'=>(float)$d['latitude'],'longitude'=>(float)$d['longitude']]]],
                'destination'=>['location'=>['latLng'=>['latitude'=>(float)$cache['destination_lat'],'longitude'=>(float)$cache['destination_lon']]]],
                'travelMode'=>'DRIVE','routingPreference'=>'TRAFFIC_UNAWARE','computeAlternativeRoutes'=>false,'units'=>'METRIC'
            ],['Content-Type: application/json','X-Goog-Api-Key: '.$this->key,'X-Goog-FieldMask: routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline']);
            $r=$route['routes'][0]??[];
            if(!isset($r['distanceMeters'],$r['duration'],$r['polyline']['encodedPolyline'])||!preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/D',(string)$r['duration'],$m))throw new \DomainException('No road route available for this address.');
            $duration=(int)ceil((float)$m[1]);$distance=(int)$r['distanceMeters'];if($duration<0||$distance<0)throw new \DomainException('Invalid road estimate.');
            $this->run('UPDATE delivery_tracking_routes SET calculated_at=?,origin_at=?,distance_metres=NULL,duration_seconds=NULL,expected_arrival=NULL,polyline=NULL,error_code=NULL,route_lock_until=0 WHERE delivery_id=?',[$now,$d['last_position'],$delivery]);
            $fresh=DriverLiveRouteData::read($this->db,$driver);
            if(!hash_equals($fresh['revision_token'],$revision))throw new \DomainException('Assignments changed while calculating. Refresh the route.');
            return $this->view(array_merge($cache,['calculated_at'=>$now,'origin_at'=>$d['last_position'],'distance_metres'=>$distance,'duration_seconds'=>$duration,'expected_arrival'=>$now+$duration,'polyline'=>$r['polyline']['encodedPolyline']]))+['driver_id'=>$driver,'assignment_id'=>$delivery,'route_revision'=>$revision,'origin'=>['lat'=>$d['latitude'],'lng'=>$d['longitude']]];

        }catch(\Throwable $e){
            $this->run('UPDATE delivery_tracking_routes SET error_code=?,route_lock_until=?,polyline=NULL,calculated_at=NULL WHERE delivery_id=?',['unavailable',$now+$s['route_interval_seconds'],$delivery]);
            return ['available'=>false,'reason'=>$e instanceof \DomainException?$e->getMessage():'Road estimate temporarily unavailable.'];
        }
    }
    private function view(array $r):array{return ['available'=>true,'calculated_at'=>(int)$r['calculated_at'],'origin_at'=>(int)$r['origin_at'],'distance_metres'=>(int)$r['distance_metres'],'duration_seconds'=>(int)$r['duration_seconds'],'expected_arrival'=>(int)$r['expected_arrival'],'polyline'=>$r['polyline'],'destination'=>['lat'=>(float)$r['destination_lat'],'lng'=>(float)$r['destination_lon']],'description'=>'Google road estimate; excludes live traffic and is not a guaranteed arrival time.'];}
}
