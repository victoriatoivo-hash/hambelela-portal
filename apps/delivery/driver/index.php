<?php
 declare(strict_types=1);
require __DIR__.'/bootstrap.php';
try{\Hambelela\Delivery\EmployeeDriverSession::actor(db(),(int)(current_user()['id']??0));}catch(Throwable $e){http_response_code(403);exit('Active Driver employee required.');}
// Old bookmarks use the same role-aware module, not a second Driver interface.
header('Location: '.BASE_URL.'/apps/delivery/index.php',true,303);
exit;
