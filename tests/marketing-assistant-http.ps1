$ErrorActionPreference = 'Stop'
$taskBase = 'http://127.0.0.1:8802'
$taskChecks = 0
function Test-FixtureResponse($taskPath, $taskExpected, $taskBody = $null, $taskText = '') {
    $taskArgs = @{ Uri = "$taskBase$taskPath"; SkipHttpErrorCheck = $true }
    if ($null -ne $taskBody) { $taskArgs.Method = 'Post'; $taskArgs.Body = $taskBody }
    $taskResult = Invoke-WebRequest @taskArgs
    if ($taskResult.StatusCode -ne $taskExpected) { throw "$taskPath expected $taskExpected; got $($taskResult.StatusCode)" }
    if ($taskText -and !$taskResult.Content.Contains($taskText)) { throw "$taskPath missing expected result" }
    if ($taskResult.Content -match 'Fatal error|Warning:|Uncaught') { throw "$taskPath emitted a PHP error" }
    $script:taskChecks++
    Write-Output "PASS $taskExpected $taskPath"
}
foreach ($taskRole in @('employee','owner')) {
    foreach ($taskRoute in @('analytics-data.php','export.php','metric-file.php','track-link.php')) {
        $taskExpected = if ($taskRole -eq 'owner') { 200 } else { 403 }
        Test-FixtureResponse "/guard?route=$taskRoute&role=$taskRole" $taskExpected
    }
}
foreach ($taskView in @('analytics','reports','performance','create-content')) { Test-FixtureResponse "/guard?route=index.php&view=$taskView" 403 }
foreach ($taskAction in @('save','status','product_save','phase3_campaign_save','phase3_metric_save')) { Test-FixtureResponse '/guard?route=index.php' 403 @{ action=$taskAction } }
foreach ($taskView in @('dashboard','apps','tasks','calendar','social','reels','whatsapp','blog','newsletter','website','library')) { Test-FixtureResponse "/apps/marketing/index.php?view=$taskView" 200 }
Test-FixtureResponse '/apps/marketing/execution.php?id=2' 404
Test-FixtureResponse '/apps/marketing/execution.php?id=2&role=owner' 200
Test-FixtureResponse '/apps/marketing/product-work.php?id=1' 200
Test-FixtureResponse '/apps/marketing/product-work.php?id=2' 404
Test-FixtureResponse '/apps/marketing/product-work.php?id=1' 200 @{csrf='fixture-only-token';id='1';proposed_name='Prepared product';submit_review='1'} 'Sent to the owner'
Write-Output "$taskChecks local HTTP fixture checks passed."
