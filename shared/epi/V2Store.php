<?php
declare(strict_types=1);
namespace Hambelela\EPI;
use PDO;
use RuntimeException;
final class V2Store
{
 public static function transaction(PDO $db,callable $fn){$outer=$db->inTransaction();$sp='epi_'.bin2hex(random_bytes(6));if($outer)$db->exec('SAVEPOINT '.$sp);else $db->beginTransaction();try{$r=$fn();if($outer)$db->exec('RELEASE SAVEPOINT '.$sp);else $db->commit();return $r;}catch(\Throwable $e){if($db->inTransaction()){if($outer)$db->exec('ROLLBACK TO SAVEPOINT '.$sp);else $db->rollBack();}throw $e;}}
 public static function one(PDO $db,string $sql,array $args=[]):?array{$s=$db->prepare($sql);$s->execute($args);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
 public static function lock(PDO $db,string $scope):void{$key=hash('sha256',$scope);$db->prepare('INSERT IGNORE INTO epi_v2_scope_locks VALUES(?)')->execute([$key]);self::one($db,'SELECT * FROM epi_v2_scope_locks WHERE scope_key=? FOR UPDATE',[$key]);}
 public static function employee(PDO $db,int $id):void{if($id<=0||!self::one($db,'SELECT id FROM ops_employees WHERE id=?',[$id]))throw new RuntimeException('Unknown employee');}
 public static function activation(PDO $db):?array{return self::one($db,'SELECT * FROM epi_v2_activation WHERE id=1');}
 public static function activate(PDO $db,string $at,int $actor,array $policy):void{self::employee($db,$actor);if(empty($policy['version'])||empty($policy['calendar_version']))throw new RuntimeException('Versioned policy required');self::transaction($db,function()use($db,$at,$actor,$policy){self::lock($db,'activation');if(self::activation($db))throw new RuntimeException('Activation is immutable');$db->prepare("INSERT INTO epi_v2_activation VALUES(1,?,?,NOW(),?,'shadow')")->execute([Support::timestamp($at)->format('Y-m-d H:i:s'),$actor,Support::json($policy)]);});}
 public static function policy(PDO $db):array{$a=self::activation($db);return $a?(json_decode($a['policy_json'],true)?:[]):[];}
 public static function audit(PDO $db,string $scope,int $actor,string $reason,$before,$after):void{$db->prepare('INSERT INTO epi_v2_ownership_audits(scope_key,actor_id,reason,before_json,after_json) VALUES(?,?,?,?,?)')->execute([$scope,$actor,$reason,Support::json($before),Support::json($after)]);}
 public static function absence(PDO $db,int $employee,string $at):?array{return self::one($db,"SELECT * FROM epi_v2_exceptions WHERE employee_id=? AND kind='absence' AND effective_from<=? AND effective_to>? AND approved_at<=? ORDER BY id LIMIT 1",[$employee,$at,$at,$at]);}
 public static function approveAbsence(PDO $db,int $employee,string $from,string $to,int $reviewer,string $reason,string $source,string $approvedAt):int{self::employee($db,$employee);self::employee($db,$reviewer);if($to<=$from||trim($reason)===''||trim($source)==='')throw new RuntimeException('Invalid exception');$db->prepare("INSERT INTO epi_v2_exceptions(employee_id,effective_from,effective_to,approved_at,approved_by,reason,source,kind) VALUES(?,?,?,?,?,?,?,'absence')")->execute([$employee,$from,$to,$approvedAt,$reviewer,$reason,$source]);return (int)$db->lastInsertId();}
}
