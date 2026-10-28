// Source-only package; upload original files individually through normal cPanel scanning.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
process.chdir(path.resolve(__dirname,'..'));
const out=path.resolve(process.argv[2]||'.run-artifacts/epi-scoring-activation-r1');
if(!out.startsWith(path.resolve('.run-artifacts')+path.sep)||fs.existsSync(out))throw new Error('New private artifact directory required');
const baseline='5296c666';
const files=['shared/epi/PerformanceCapturePolicy.php','shared/epi/DeadlineEngine.php','shared/epi/CompletedWorkCapture.php',
'shared/epi/V2QualityBridge.php','shared/epi/OperationalPerformanceProjector.php','shared/epi/EmployeePerformanceService.php',
'shared/epi/RateScoreCalculator.php','shared/epi/AttendanceEvidenceProjector.php','shared/epi/ProvisionalScorecardAssignments.php',
'shared/epi/V2Watchdog.php','shared/epi/FrontDeskRoster.php','apps/operations/kpi-presence-review.php',
'apps/operations/performance-workforce-response.php','assets/js/employee-performance.js','assets/js/performance-workspace.js','assets/js/employee-performance-workforce.js'];
const normal=b=>b.toString('utf8').replace(/\r\n/g,'\n');const hash=b=>crypto.createHash('sha256').update(normal(b)).digest('hex');
const manifest={baseline,files:{},dependencies:{},schema_changes:false};
const previous=process.argv[3]?JSON.parse(fs.readFileSync(path.resolve(process.argv[3]),'utf8')):null;
// Read and merged from production 27 October: retain leave/management corrections.
const reviewedLive={
 'shared/epi/DeadlineEngine.php':'f80a4739e12d5ddc752be3d21b37bc1fe72e7af810a17047bee625625ddd1fe9',
 'shared/epi/CompletedWorkCapture.php':'6bd104ce39c8f76277b714842925a763a9d256febf951f0d05f763599a4eb3b2',
 'shared/epi/V2OperationalBridge.php':'2a9945a2246f6dcd253693876c4298c25c8dd245bea78874186eea465b089d69',
 'apps/operations/operations.php':'709868816a9b3ba6f7db87452ee120f903974bcbfb9b714918c95dec0907b21f'
};
for(const name of files){let before=null;try{before=hash(cp.execFileSync('git',['show',`${baseline}:${name}`],{stdio:['ignore','pipe','ignore']}));}catch{}
 const data=normal(fs.readFileSync(name));manifest.files[name]={before:[previous?.files?.[name]?.after||reviewedLive[name]||before],after:hash(data)};
 const dst=path.join(out,'runtime',name);fs.mkdirSync(path.dirname(dst),{recursive:true});fs.writeFileSync(dst,data);
}
for(const name of ['apps/operations/operations.php','apps/operations/bookkeeping.php','apps/operations/whatsapp-service.php',
'shared/epi/OrdersStageBridge.php','shared/epi/OrdersSlaPolicy.php','shared/epi/OwnershipPeriodEngine.php','shared/epi/V2OperationalBridge.php',
'shared/epi/FrontDeskCoverage.php','shared/epi/HrAbsenceEvidence.php','shared/epi/PerformanceRefreshRuntime.php','shared/epi/PerformanceRefreshQueue.php',
'shared/epi/ModuleDeadlineBridge.php','shared/epi/CommunicationDeadlineBridge.php','shared/epi/IncidentCorrelation.php',
'shared/epi/EligibilityPolicy.php','shared/epi/ScorecardCatalog.php','shared/epi/PerformanceHistory.php','shared/epi/V2PerformanceQuery.php',
'apps/operations/kpi-employee-data.php','apps/operations/reports.php','assets/css/performance-workspace.css'])
 manifest.dependencies[name]=reviewedLive[name]||hash(cp.execFileSync('git',['show',`${baseline}:${name}`]));
manifest.dependencies['shared/task-leave.php']='206adad29c8b6d714515c7bedb16e6657aa1e069d555113aadaeba6c090da495';
fs.writeFileSync(path.join(out,'manifest.json'),JSON.stringify(manifest,null,2));
for(const [source,target] of [['scripts/epi-data-link-release.php','release.php'],['scripts/epi-scoring-activation.php','activation.php']])
 fs.writeFileSync(path.join(out,target),normal(fs.readFileSync(source)));
console.log(JSON.stringify({directory:out,files:files.length,dependencies:Object.keys(manifest.dependencies).length}));
