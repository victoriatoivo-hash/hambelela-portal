"""Build a reviewable release bundle only. No FTP, migrations, flags or git writes."""
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BASELINE = '1472b927f798221b112098729fe27257813fd7ec'
NEW = [
    'shared/epi/HrAbsenceEvidence.php', 'shared/epi/HrConnection.php',
    'shared/epi/OrdersSlaPolicy.php', 'shared/epi/OrdersStageBridge.php',
    'shared/epi/FrontDeskCoverage.php', 'shared/epi/FrontDeskRoster.php',
    'shared/epi/FrontCoverageNotifications.php', 'shared/front-coverage-prompt.php',
    'shared/epi/FrontHandoverChecklist.php',
    'shared/epi/StageReadiness.php', 'scripts/epi-stage-readiness.php',
    'shared/epi/StageSchemaInstaller.php', 'scripts/epi-stage-migrate.php',
    'assets/css/front-coverage.css', 'assets/js/front-coverage.js',
]
RUNTIME = NEW + [
    'shared/epi/bootstrap.php', 'shared/epi/OwnershipPeriodEngine.php',
    'shared/epi/DeadlineEngine.php', 'shared/epi/V2OperationalBridge.php',
    'shared/epi/V2Watchdog.php', 'apps/operations/front-coverage.php',
    'apps/operations/front-roster.php', 'apps/operations/epi-orders-deadlines.php',
    'apps/operations/epi-v2-shadow.php', 'apps/operations/orders-board-action.php',
    'apps/operations/sync-orders.php',
    'shared/footer.php', 'scripts/epi-front-coverage-reminders.php',
]
MIGRATIONS = [
    'operations-epi-front-coverage-migration.sql',
    'operations-epi-orders-sla-migration.sql',
    'operations-epi-front-roster-migration.sql',
]

def build(output):
    records = []
    for name in RUNTIME + MIGRATIONS:
        data = (ROOT / name).read_bytes()
        old = subprocess.run(['git', 'show', BASELINE + ':' + name], cwd=ROOT, capture_output=True)
        records.append({'path': name, 'sha256': hashlib.sha256(data).hexdigest(),
                        'baseline_sha256': hashlib.sha256(old.stdout).hexdigest() if old.returncode == 0 else None,
                        'kind': 'explicit_migration' if name in MIGRATIONS else 'runtime'})
    manifest = {'baseline': BASELINE, 'source': 'local_working_tree',
                'deployable': False, 'activation_authorized_by_bundle': False,
                'gates': ['live file drift comparison', 'authenticated role tests',
                          'reliable scheduler and independent failure alert',
                          'prospective owner-approved roster and policy',
                          'review remaining functional limitations'], 'files': records}
    output.mkdir(parents=True, exist_ok=True)
    target = output / 'epi-stage-review.zip'
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED) as bundle:
        bundle.writestr('manifest.json', json.dumps(manifest, indent=2))
        for record in records:
            bundle.write(ROOT / record['path'], record['path'])
    with zipfile.ZipFile(target) as bundle:
        for record in records:
            assert hashlib.sha256(bundle.read(record['path'])).hexdigest() == record['sha256']
    print(json.dumps({'bundle': str(target), 'files': len(records), 'hashes_verified': True,
                      'production_changed': False, 'deployable': False}))

if __name__ == '__main__':
    build(ROOT / '.run-artifacts' / 'epi-stage-package')
