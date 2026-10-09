<?php
// Load the actual canonical method function without bootstrapping a production session/database.
$source=file_get_contents(__DIR__.'/../apps/operations/operations.php');
if(!preg_match('/function ops_payment_method_map\(\): array\s*\{.*?\n\}/s',$source,$function))throw new RuntimeException('Canonical payment map not found');
eval($function[0]);
