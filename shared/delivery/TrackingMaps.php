<?php
declare(strict_types=1);
namespace Hambelela\Delivery;
require_once __DIR__.'/TrackingPolicy.php';

/** Google road estimates are separate from GPS evidence and never change payments. */
final class TrackingMaps
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
        curl_setopt_array($c,[CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$output):int{if(strlen($output)+strlen($chunk)>524288)return 0;$output.=$chunk;return strlen($chunk);}]);
        if($body!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));}
        $ok=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($ok===false||$status!==200)throw new \DomainException('Google routing unavailable. Check key restrictions and billing in Google Cloud.');
        $result=json_decode($output,true,32,JSON_THROW_ON_ERROR);if(!is_array($result))throw new \DomainException('Invalid map response.');return $result;
    }
    public function route(array $actor,string $device,int $delivery):array
    {
        TrackingPolicy::owner($actor);if($this->key==='')return ['available'=>false,'reason'=>'Google server key is not configured.'];
        $rows=$this->rows('SELECT * FROM delivery_tracking_devices WHERE id=? AND active=1',[$device]);$d=$rows[0]??null;if(!$d)throw new \DomainException('Device not found.');
        $s=TrackingPolicy::settings(json_decode($d['settings_json'],true));$now=time();
        $state=TrackingPolicy::status($now,$d['last_contact']===null?null:(int)$d['last_contact'],$d['last_position']===null?null:(int)$d['last_position'],(bool)$d['gps_available'],$s,true);
        if($state!=='Live')return ['available'=>false,'reason'=>'Road ETA unavailable: location is '.$state.'.'];
        $jobs=$this->rows("SELECT id,address FROM delivery_jobs WHERE id=? AND driver_employee_id=? AND status='out_for_delivery'",[$delivery,$d['employee_id']]);$job=$jobs[0]??null;
        if(!$job)throw new \DomainException('Select a current delivery assigned to this Driver.');
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
                if(!isset($location['lat'],$location['lng'])||!is_numeric($location['lat'])||!is_numeric($location['lng']))throw new \DomainException('Address coordinates unavailable.');
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
            return $this->view(array_merge($cache,['calculated_at'=>$now,'origin_at'=>$d['last_position'],'distance_metres'=>$distance,'duration_seconds'=>$duration,'expected_arrival'=>$now+$duration,'polyline'=>$r['polyline']['encodedPolyline']]));
        }catch(\Throwable $e){
            $this->run('UPDATE delivery_tracking_routes SET error_code=?,route_lock_until=?,polyline=NULL,calculated_at=NULL WHERE delivery_id=?',['unavailable',$now+$s['route_interval_seconds'],$delivery]);
            return ['available'=>false,'reason'=>$e instanceof \DomainException?$e->getMessage():'Road estimate temporarily unavailable.'];
        }
    }
    private function view(array $r):array{return ['available'=>true,'calculated_at'=>(int)$r['calculated_at'],'origin_at'=>(int)$r['origin_at'],'distance_metres'=>(int)$r['distance_metres'],'duration_seconds'=>(int)$r['duration_seconds'],'expected_arrival'=>(int)$r['expected_arrival'],'polyline'=>$r['polyline'],'destination'=>['lat'=>(float)$r['destination_lat'],'lng'=>(float)$r['destination_lon']],'description'=>'Google road estimate; excludes live traffic and is not a guaranteed arrival time.'];}
}
