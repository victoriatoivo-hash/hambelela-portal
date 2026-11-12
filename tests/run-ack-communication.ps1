param([Parameter(Mandatory)][string]$MariaBin,[Parameter(Mandatory)][string]$PhpDir)
$ErrorActionPreference='Stop'
$port=13317
$listeners=[Net.NetworkInformation.IPGlobalProperties]::GetIPGlobalProperties().GetActiveTcpListeners()
if($listeners.Port -contains $port){throw 'The isolated test port is already in use.'}
$dir=New-Item -ItemType Directory -Path (Join-Path $env:TEMP ('ack-communication-test-'+[guid]::NewGuid().ToString('N')))
$env:ACK_TEST_PASSWORD=[guid]::NewGuid().ToString('N')
$env:ACK_TEST_DSN='mysql:host=127.0.0.1;port=13317;dbname=acknowledgments_test'
& "$MariaBin/mariadb-install-db.exe" "--datadir=$($dir.FullName)" "--password=$env:ACK_TEST_PASSWORD" '--port=13317' | Out-Null
if($LASTEXITCODE){throw 'Test database initialization failed'}
$server=Start-Process -FilePath "$MariaBin/mariadbd.exe" -ArgumentList @('--no-defaults',('--datadir="'+$dir.FullName+'"'),'--port=13317','--bind-address=127.0.0.1','--skip-log-bin') -WindowStyle Hidden -PassThru
try{
    $ready=$false
    for($i=0;$i -lt 40;$i++){if($server.HasExited){throw 'Test server exited'}
        $client=New-Object Net.Sockets.TcpClient
        try{$client.Connect('127.0.0.1',$port);$ready=$true}catch{}finally{$client.Dispose()}
        if($ready){break};Start-Sleep -Milliseconds 500
    }
    if(!$ready){throw 'Test server did not start'}
    & "$PhpDir/php.exe" -n -d "extension_dir=$PhpDir/ext" -d extension=pdo_mysql -d extension=gd "$PSScriptRoot/acknowledgments.php"
    if($LASTEXITCODE){throw 'Acknowledgment tests failed'}
}finally{
    if(!$server.HasExited){
        $env:MYSQL_PWD=$env:ACK_TEST_PASSWORD
        & "$MariaBin/mariadb-admin.exe" '--host=127.0.0.1' '--port=13317' '--user=root' shutdown
        if(!$server.WaitForExit(5000)){$server.Kill()}
    }
    Remove-Item Env:MYSQL_PWD,Env:ACK_TEST_PASSWORD,Env:ACK_TEST_DSN -ErrorAction SilentlyContinue
}
