<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
// Test-only helpers deliberately do not polyfill PHP 8 functions in application code.
function test_contains(string $haystack,string $needle):bool {return $needle===''||strpos($haystack,$needle)!==false;}
function test_starts_with(string $haystack,string $needle):bool {return $needle===''||strpos($haystack,$needle)===0;}
