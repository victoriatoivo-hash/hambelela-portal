param(
 [Parameter(Mandatory=$true)][string]$JavaBin,
 [Parameter(Mandatory=$true)][string]$SignerJar,
 [Parameter(Mandatory=$true)][string]$UnsignedApk,
 [Parameter(Mandatory=$true)][string]$SignedApk
)
$ErrorActionPreference='Stop'
# User-owned signing material remains outside the repository and public server.
$gpsSigningDirectory='C:\Users\User\.codex\credentials\hambelela-delivery-signing'
$gpsKeystore=Join-Path $gpsSigningDirectory 'release.p12'
$gpsPasswordFile=Join-Path $gpsSigningDirectory 'password.dpapi.xml'
if(-not(Test-Path -LiteralPath $gpsSigningDirectory)) {New-Item -ItemType Directory -Path $gpsSigningDirectory -Force | Out-Null}
$gpsIdentity=[System.Security.Principal.WindowsIdentity]::GetCurrent()
$gpsAcl=New-Object System.Security.AccessControl.DirectorySecurity
$gpsAcl.SetAccessRuleProtection($true,$false)
$gpsAcl.SetOwner($gpsIdentity.User)
$gpsRule=New-Object System.Security.AccessControl.FileSystemAccessRule($gpsIdentity.User,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
$gpsAcl.AddAccessRule($gpsRule)
Set-Acl -LiteralPath $gpsSigningDirectory -AclObject $gpsAcl
if(Test-Path -LiteralPath $gpsPasswordFile){$gpsSecure=Import-Clixml -LiteralPath $gpsPasswordFile}
else {
 if(Test-Path -LiteralPath $gpsKeystore){throw 'Existing signing key requires its original password; do not replace it.'}
 $gpsBytes=New-Object byte[] 32
 [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($gpsBytes)
 $gpsSecure=ConvertTo-SecureString ([Convert]::ToBase64String($gpsBytes)) -AsPlainText -Force
 $gpsSecure | Export-Clixml -LiteralPath $gpsPasswordFile
}
try {
 $env:HAMBELELA_APK_SIGNING_PASSWORD=[System.Net.NetworkCredential]::new('',$gpsSecure).Password
 if(-not(Test-Path -LiteralPath $gpsKeystore)){
  & (Join-Path $JavaBin 'keytool.exe') -genkeypair -keystore $gpsKeystore -storetype PKCS12 -storepass:env HAMBELELA_APK_SIGNING_PASSWORD -alias hambelela-delivery -keyalg RSA -keysize 3072 -validity 10950 -dname 'CN=Hambelela Delivery, O=Hambelela Organic, C=NA'
  if($LASTEXITCODE -ne 0){throw 'Signing key generation failed'}
 }
 & (Join-Path $JavaBin 'java.exe') -jar $SignerJar sign --ks $gpsKeystore --ks-key-alias hambelela-delivery --ks-pass env:HAMBELELA_APK_SIGNING_PASSWORD --out $SignedApk $UnsignedApk
 if($LASTEXITCODE -ne 0){throw 'Android signing failed'}
 & (Join-Path $JavaBin 'java.exe') -jar $SignerJar verify --verbose --print-certs $SignedApk
 if($LASTEXITCODE -ne 0){throw 'Android signature verification failed'}
 Get-FileHash -LiteralPath $SignedApk -Algorithm SHA256
}finally{Remove-Item Env:HAMBELELA_APK_SIGNING_PASSWORD -ErrorAction SilentlyContinue}
