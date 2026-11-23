param(
    [Parameter(Mandatory=$true)][string]$JavaDirectory,
    [Parameter(Mandatory=$true)][string]$PrivateDirectory,
    [string]$UnsignedApk,
    [string]$ApkSignerJar,
    [string]$OutputApk
)
$ErrorActionPreference='Stop'
# This private directory MUST be outside the checkout and never uploaded.
$privateFull=[IO.Path]::GetFullPath($PrivateDirectory)
$repoFull=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot))
if($privateFull.StartsWith($repoFull,[StringComparison]::OrdinalIgnoreCase)){throw 'Keep signing material outside the repository.'}
New-Item -ItemType Directory -Force -Path $privateFull | Out-Null
$identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$acl=Get-Acl -LiteralPath $privateFull
$acl.SetAccessRuleProtection($true,$false)
$rule=New-Object Security.AccessControl.FileSystemAccessRule($identity,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
$acl.SetAccessRule($rule)
Set-Acl -LiteralPath $privateFull -AclObject $acl
$keyPath=Join-Path $privateFull 'hambelela-driver.p12'
$passPath=Join-Path $privateFull 'signing-password.dpapi'
if((Test-Path $keyPath) -ne (Test-Path $passPath)){throw 'Incomplete signing key pair; do not regenerate.'}
try {
    if(!(Test-Path $keyPath)) {
        $random=New-Object byte[] 48
        $rng=[Security.Cryptography.RandomNumberGenerator]::Create()
        $rng.GetBytes($random); $rng.Dispose()
        $env:HAMBELELA_SIGNING_PASSWORD=[Convert]::ToBase64String($random)
        $secure=ConvertTo-SecureString $env:HAMBELELA_SIGNING_PASSWORD -AsPlainText -Force
        [IO.File]::WriteAllText($passPath,(ConvertFrom-SecureString $secure))
        & (Join-Path $JavaDirectory 'keytool.exe') -genkeypair -keystore $keyPath -storetype PKCS12 -storepass:env HAMBELELA_SIGNING_PASSWORD -alias hambelela-driver -keyalg RSA -keysize 3072 -validity 10950 -dname 'CN=Hambelela Driver, O=Hambelela Organic, C=NA'
        if($LASTEXITCODE -ne 0){throw 'Signing key creation failed.'}
    } else {
        $secure=ConvertTo-SecureString ([IO.File]::ReadAllText($passPath))
        $env:HAMBELELA_SIGNING_PASSWORD=([Net.NetworkCredential]::new('',$secure)).Password
    }
    $certPath=Join-Path $privateFull 'signer-public.der'
    & (Join-Path $JavaDirectory 'keytool.exe') -exportcert -keystore $keyPath -storepass:env HAMBELELA_SIGNING_PASSWORD -alias hambelela-driver -file $certPath
    if($LASTEXITCODE -ne 0){throw 'Public certificate export failed.'}
    $fingerprint=((Get-FileHash -LiteralPath $certPath -Algorithm SHA256).Hash -replace '(..)(?!$)','$1:')
    Write-Output ('Public signing certificate SHA-256: '+$fingerprint)
    if($UnsignedApk) {
        if(!$ApkSignerJar -or !$OutputApk){throw 'Signing requires signer JAR and output APK paths.'}
        if(Test-Path $OutputApk){throw 'Output APK already exists; choose a new version path.'}
        & (Join-Path $JavaDirectory 'java.exe') -jar $ApkSignerJar sign --ks $keyPath --ks-key-alias hambelela-driver --ks-pass env:HAMBELELA_SIGNING_PASSWORD --out $OutputApk $UnsignedApk
        if($LASTEXITCODE -ne 0){throw 'APK signing failed.'}
        & (Join-Path $JavaDirectory 'java.exe') -jar $ApkSignerJar verify --verbose --print-certs $OutputApk
        if($LASTEXITCODE -ne 0){throw 'APK signature verification failed.'}
        Get-FileHash -LiteralPath $OutputApk -Algorithm SHA256
    }
} finally { Remove-Item Env:HAMBELELA_SIGNING_PASSWORD -ErrorAction SilentlyContinue }
