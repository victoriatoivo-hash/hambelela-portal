<?php
declare(strict_types=1);
// Separate PHP processes reproduce two authenticated requests sharing one session.
$api = file_get_contents(__DIR__.'/../api/notifications.php');
$start = strpos($api, '// Persist the token before releasing');
$end = strpos($api, '// Notification acknowledgements', $start ?: 0);
if ($start === false || $end === false) throw new RuntimeException('Missing session release boundary');
$boundary = substr($api, $start, $end - $start);
$dir = sys_get_temp_dir().'/marketing-lock-'.bin2hex(random_bytes(8));
mkdir($dir);
$worker = <<<'PHP'
<?php
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
session_save_path(__DIR__);
session_id('synthetic-marketing-session');
session_start();
require __DIR__.'/boundary.php';
if ($argv[1] === 'poll') {
    file_put_contents(__DIR__.'/ready', $_SESSION['marketing_popup_csrf']);
    usleep(3000000); // Slow HR synchronization after authentication.
} else {
    if (!hash_equals(file_get_contents(__DIR__.'/ready'), $_SESSION['marketing_popup_csrf'])) exit(2);
    echo 'token persisted';
}
PHP;
file_put_contents($dir.'/worker.php', $worker);
file_put_contents($dir.'/boundary.php', "<?php\n".$boundary);
$processes = [];
try {
    $descriptors = [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
    $poll = proc_open([PHP_BINARY,$dir.'/worker.php','poll'], $descriptors, $pollPipes);
    $processes[] = $poll;
    $deadline = microtime(true)+5;
    while (!is_file($dir.'/ready') && microtime(true)<$deadline) usleep(10000);
    if (!is_file($dir.'/ready')) throw new RuntimeException('Polling worker did not start');
    $started = microtime(true);
    $ack = proc_open([PHP_BINARY,$dir.'/worker.php','ack'], $descriptors, $ackPipes);
    $processes[] = $ack;
    $result = stream_get_contents($ackPipes[1]);
    $elapsed = microtime(true)-$started;
    foreach ($ackPipes as $pipe) fclose($pipe);
    if (proc_close($ack)!==0 || $result!=='token persisted' || $elapsed>1.5) throw new RuntimeException('Dismissal blocked by polling session lock or lost CSRF token');
    foreach ($pollPipes as $pipe) fclose($pipe);
    proc_close($poll);
    echo "PASS concurrent dismissal does not wait for slow polling; token persists across requests\n";
} finally {
    foreach ($processes as $process) if (is_resource($process)) {proc_terminate($process);proc_close($process);}
    foreach (glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
