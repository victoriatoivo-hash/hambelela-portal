param([Parameter(Mandatory)][string]$MariaBin,[Parameter(Mandatory)][string]$PhpDir)
$ErrorActionPreference='Stop'
$port=13419
$dir=New-Item -ItemType Directory -Path (Join-Path $env:TEMP ('delivery-accountability-'+[guid]::NewGuid().ToString('N')))
$env:DELIVERY_TEST_PASSWORD=[guid]::NewGuid().ToString('N')
$env:DELIVERY_TEST_DSN="mysql:host=127.0.0.1;port=$port"
$env:DELIVERY_ACCOUNTABILITY_TEST='1'
& "$MariaBin/mariadb-install-db.exe" "--datadir=$($dir.FullName)" "--password=$env:DELIVERY_TEST_PASSWORD" "--port=$port" | Out-Null
if($LASTEXITCODE){throw 'Synthetic database initialization failed'}
$server=Start-Process -FilePath "$MariaBin/mariadbd.exe" -ArgumentList @('--no-defaults',('--datadir="'+$dir.FullName+'"'),"--port=$port",'--bind-address=127.0.0.1','--skip-log-bin') -WindowStyle Hidden -PassThru
try{
 $ready=$false
 for($i=0;$i -lt 40;$i++){$client=New-Object Net.Sockets.TcpClient;try{$client.Connect('127.0.0.1',$port);$ready=$true}catch{}finally{$client.Dispose()};if($ready){break};if($server.HasExited){throw 'Synthetic server exited'};Start-Sleep -Milliseconds 250}
 if(!$ready){throw 'Synthetic database unavailable'}
 & "$PhpDir/php.exe" -n -d "extension_dir=$PhpDir/ext" -d extension=pdo_mysql -d extension=mbstring "$PSScriptRoot/delivery-arrangement-regression.php"
 if($LASTEXITCODE){throw 'Delivery regression tests failed'}
}finally{
 if(!$server.HasExited){$env:MYSQL_PWD=$env:DELIVERY_TEST_PASSWORD;& "$MariaBin/mariadb-admin.exe" '--host=127.0.0.1' "--port=$port" '--user=root' shutdown;if(!$server.WaitForExit(5000)){$server.Kill()}}
 Remove-Item Env:MYSQL_PWD,Env:DELIVERY_TEST_PASSWORD,Env:DELIVERY_TEST_DSN,Env:DELIVERY_ACCOUNTABILITY_TEST -ErrorAction SilentlyContinue
}
