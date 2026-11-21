<?php
if(PHP_SAPI!=='cli'||getenv('OFFLINE_BUILD')!=='owner-proof-v1')exit(1);
$db=new PDO('mysql:host=127.0.0.1;port=43379;dbname=driver_review;charset=utf8mb4','driver_review',getenv('OFFLINE_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(!$db->query("SHOW TABLES LIKE 'delivery_owner_proof_actions'")->fetchColumn())$db->exec(file_get_contents(dirname(__DIR__,2).'/database/delivery/014-owner-proof-review.sql'));
echo "Local review migration ready. No production connection.\n";
