"""Localhost-only fixture using synthetic employees and production policy handlers."""
from pathlib import Path
import shutil
root=Path(__file__).resolve().parents[1]
fixture=root/'verification/fixture'
for folder in ('shared','apps/operations','apps/hr-portal/includes'):
    (fixture/folder).mkdir(parents=True,exist_ok=True)
for p in ('shared/portal-policy-popup.php','shared/hr-access.php','apps/hr-portal/includes/policy-system.php','apps/hr-portal/portal-login.php'):
    shutil.copy2(root/p,fixture/p)
for p in ('policy-action.php','policy-view.php','policy-receipt.php'):
    shutil.copy2(root/'verification/live-source/apps/hr-portal'/p,fixture/'apps/hr-portal'/p)
for p in ('policy-signature.js','policies.css'):
    shutil.copy2(root/'apps/hr-portal/includes'/p,fixture/'apps/hr-portal/includes'/p)
def write(p,text): (fixture/p).write_text(text,encoding='utf-8')
write('config.php',"""<?php
define('BASE_PATH',__DIR__);define('BASE_URL','');
session_name('policy_portal_fixture');session_start();
$localSecrets=['hr_db_host'=>'127.0.0.1;port=3339','hr_db_name'=>'hr_policy_test','hr_db_user'=>'root','hr_db_pass'=>''];
""")
write('shared/auth.php',"""<?php
function current_user():array{return $_SESSION['user']??[];}
function current_role_key():string{return current_user()['role_key']??'guest';}
function current_user_has_capability(string $cap):bool{return current_role_key()==='owner_admin';}
function require_login():void{if(empty(current_user()['id'])){http_response_code(401);exit('Login required');}}
""")
write('shared/database.php',"""<?php
function db():PDO{static $db;return $db??($db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_portal_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));}
""")
write('apps/operations/operations.php',"""<?php
function ops_hr_db():PDO{static $db;return $db??($db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));}
""")
write('apps/hr-portal/config.php',"""<?php
session_name('hambelela_hr_test_session');session_start();
function db():PDO{static $db;return $db??($db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=hr_policy_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]));}
function currentUser():?array{return $_SESSION['user']??null;}
function requireLogin():void{if(!currentUser()){http_response_code(401);exit('HR login required');}}
function hrTableExists(PDO $db,string $table):bool{$s=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return(bool)$s->fetchColumn();}
function hrColumnExists(PDO $db,string $table,string $column):bool{$s=$db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$s->execute([$table,$column]);return(bool)$s->fetchColumn();}
""")
write('login-test.php',"""<?php
require __DIR__.'/config.php';require __DIR__.'/shared/database.php';
$s=db()->prepare('SELECT e.*,r.role_key FROM ops_employees e JOIN ops_roles r ON r.id=e.role_id WHERE e.id=?');$s->execute([(int)($_GET['id']??2)]);$_SESSION['user']=$s->fetch();echo 'Synthetic session';
""")
write('index.php',"""<?php require __DIR__.'/config.php';require __DIR__.'/shared/auth.php';require __DIR__.'/shared/database.php';$user=current_user();?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Main Portal Local Test</title></head><body><h1>Main Portal</h1><?php require __DIR__.'/shared/portal-policy-popup.php';?></body></html>""")
print('Prepared synthetic policy fixture')
