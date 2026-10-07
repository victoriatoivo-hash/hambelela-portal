$ErrorActionPreference = 'Stop'
# Explicit release-only allowlist. No credentials, preview code, seeders or customer data.
$releaseRoot = Split-Path $PSScriptRoot -Parent
$outputRoot = 'C:\Users\User\Documents\New project\verification\delivery-production\release-artifacts'
New-Item -ItemType Directory -Path $outputRoot -Force | Out-Null
$files = @(
    'index.php',
    'shared/ess-navigation.php',
    'apps/operations/operations.php',
    'apps/operations/orders-board-action.php',
    'apps/operations/sync-orders.php',
    'shared/employee-features.php',
    'shared/order-payment-methods.php',
    'assets/css/delivery-core.css',
    'assets/js/delivery-core.js',
    'assets/js/delivery-reviews.js',
    'assets/js/delivery-accounting.js',
    'assets/js/delivery-partner.js',
    'scripts/delivery-readonly-preflight.php',
    'scripts/delivery-migration-check.php',
    'scripts/delivery-runtime-check.php',
    'scripts/delivery-notification-worker.php',
    'scripts/delivery-install-setup.php'
)
foreach ($directory in @('apps/delivery','shared/delivery','database/delivery')) {
    $files += Get-ChildItem -LiteralPath (Join-Path $releaseRoot $directory) -Recurse -File | ForEach-Object { $_.FullName.Substring($releaseRoot.Length + 1).Replace('\','/') }
}
$archivePath = Join-Path $outputRoot ('delivery-staging-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::Open($archivePath,[IO.Compression.ZipArchiveMode]::Create)
try {
    $hashes = [ordered]@{}
    foreach($relative in ($files | Sort-Object -Unique)) {
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive,(Join-Path $releaseRoot $relative),$relative) | Out-Null
        $hashes[$relative] = (Get-FileHash -LiteralPath (Join-Path $releaseRoot $relative) -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $entry = $archive.CreateEntry('release-manifest.json')
    $writer = [IO.StreamWriter]::new($entry.Open(),[Text.UTF8Encoding]::new($false))
    try { $writer.Write((@{ files=$hashes; integrationBaseline='1472b927f798221b112098729fe27257813fd7ec'; activation='external release flags; packaging never changes activation'; deployment='reviewed delta only; preserve newer live navigation and do not replay installed migrations' } | ConvertTo-Json -Depth 5)) } finally { $writer.Dispose() }
} finally { $archive.Dispose() }
Get-Item -LiteralPath $archivePath | Select-Object FullName,Length
Get-FileHash -LiteralPath $archivePath -Algorithm SHA256
