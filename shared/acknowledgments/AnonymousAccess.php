<?php
declare(strict_types=1);
namespace Hambelela\Acknowledgments;
final class AnonymousAccess {
    public static function url(string $portalHost):?string {
        $origin=rtrim((string)getenv('ACK_ANON_ORIGIN'),'/');$key=(string)getenv('ACK_ANON_GROUP_SECRET');
        if(getenv('ACK_ANON_PRIVACY_READY')!=='1'||strlen($key)<32||!preg_match('~^https://([a-z0-9.-]+)$~iD',$origin,$m)||!$portalHost||strcasecmp($m[1],$portalHost)===0)return null;
        return $origin.'/#'.hash_hmac('sha256','ack-drop:'.gmdate('Y-m-d'),$key);
    }
}
