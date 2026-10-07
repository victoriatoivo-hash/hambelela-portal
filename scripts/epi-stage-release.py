"""Guarded dormant runtime publication. No SQL migrations, activation or scoring."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import urllib.parse
import urllib.request
import zipfile

spec = importlib.util.spec_from_file_location('shadow', Path(__file__).with_name('epi-shadow-release.py'))
shadow = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shadow)
release = shadow.release
FILES = shadow.FILES
BASELINE = shadow.stage_package.BASELINE


def preflight(ftp):
    """Read-only host health/schema observation; never disclose credentials or HR rows."""
    token = secrets.token_hex(32)
    path = 'apps/operations/epi-stage-check-' + secrets.token_hex(12) + '.php'
    source = r'''<?php
ini_set('display_errors','0');header('Content-Type: application/json');header('Cache-Control: no-store');
if(time()>__EXPIRY__||$_SERVER['REQUEST_METHOD']!=='POST'||!hash_equals('__TOKEN__',(string)($_POST['token']??''))){http_response_code(403);exit;}
try{
require dirname(__DIR__,2).'/shared/database.php';
$db=db();$db->exec('START TRANSACTION READ ONLY');
$flags=$db->query("SELECT setting_key,setting_value FROM epi_employee_performance_settings WHERE setting_key IN ('epi_v2_orders_sla_enabled','epi_v2_front_roster_enabled','epi_v2_front_coverage_enabled','epi_v2_capture_enabled','epi_v2_watchdog_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
$tables=['notifications','notification_recipients','employee_user_links','epi_v2_front_rosters','epi_v2_front_plans','epi_v2_orders_tracking'];$schema=[];
foreach($tables as $table){$s=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);$schema[$table]=$s->fetchAll(PDO::FETCH_COLUMN);$s->closeCursor();}
$runs=$db->query("SELECT started_at,finished_at,status FROM epi_v2_watchdog_runs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$db->exec('ROLLBACK');
echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'flags'=>(object)$flags,'schema'=>$schema,'watchdog_runs'=>$runs]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error_type'=>get_class($e),'code'=>$e->getCode()]);}
'''.replace('__EXPIRY__', str(int(time.time()) + 300)).replace('__TOKEN__', token).encode()
    if release.read(ftp, path) is not None:
        raise RuntimeError('Temporary path collision')
    try:
        release.write(ftp, path, source)
        request = urllib.request.Request('https://portal.hambelelaorganic.com/' + path,
            data=urllib.parse.urlencode({'token': token}).encode(),
            headers={'User-Agent': 'Hambelela-Deployment-Validator/1.0'})
        with urllib.request.urlopen(request, timeout=60) as response:
            result = json.load(response)
        if not result.get('ok'):
            raise RuntimeError('Read-only host preflight failed')
        for key in ('epi_v2_orders_sla_enabled', 'epi_v2_front_roster_enabled', 'epi_v2_front_coverage_enabled'):
            if result['flags'].get(key, '0') != '0':
                raise RuntimeError('Dormant release refused: feature already active: ' + key)
        return result
    finally:
        if release.read(ftp, path) is not None:
            ftp.delete(path)
        if release.read(ftp, path) is not None:
            raise RuntimeError('Preflight cleanup failed')


def replace_file(ftp, path, data):
    """Upload completely before rename; no half-written PHP served to requests."""
    temp = path + '.release-' + secrets.token_hex(8)
    try:
        release.write(ftp, temp, data)
        if release.read(ftp, temp) != data:
            raise RuntimeError('Temporary upload verification failed: ' + path)
        ftp.rename(temp, path)
        if release.read(ftp, path) != data:
            raise RuntimeError('Published hash mismatch: ' + path)
    finally:
        if release.read(ftp, temp) is not None:
            ftp.delete(temp)


def main(mode, sha):
    if subprocess.check_output(['git', 'rev-parse', 'HEAD']).decode().strip() != sha:
        raise RuntimeError('Approved SHA mismatch')
    expected = {p: release.blob(sha, p) for p in FILES}
    if any(value is None for value in expected.values()):
        raise RuntimeError('Missing pinned dependency')
    ftp = release.ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
    ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
    report = {'sha': sha, 'baseline': BASELINE, 'mode': mode, 'state': 'started',
              'migrations_run': False, 'features_activated': False, 'official_scores_changed': False}
    changed = []
    before = {}
    try:
        before = {p: release.read(ftp, p) for p in FILES}
        conflicts = [p for p in FILES if not release.same(before[p], expected[p])
                     and not release.same(before[p], release.blob(BASELINE, p))]
        report['conflicts'] = conflicts
        if conflicts:
            raise RuntimeError('Live-only edits preserved; baseline mismatch: ' + ', '.join(conflicts))
        report['preflight'] = preflight(ftp)
        if mode == 'inspect':
            report['state'] = 'inspected'
            return
        with zipfile.ZipFile('epi-stage-backup.zip', 'w', zipfile.ZIP_DEFLATED) as backup:
            for path, data in before.items():
                if data is not None:
                    backup.writestr(path, data)
            backup.writestr('release-manifest.json', json.dumps({'sha': sha, 'absent': [p for p in FILES if before[p] is None]}))
        for path in FILES:
            if not release.same(before[path], expected[path]):
                # Recheck immediately before replacement, preserving concurrent live edits.
                if release.read(ftp, path) != before[path]:
                    raise RuntimeError('File changed during release: ' + path)
                changed.append(path)
                replace_file(ftp, path, expected[path])
        if any(not release.same(release.read(ftp, p), expected[p]) for p in FILES):
            raise RuntimeError('Final release verification failed')
        report['after'] = preflight(ftp)
        if report['after']['flags'] != report['preflight']['flags']:
            raise RuntimeError('Feature flags changed during publication')
        # Read-only service check. Do not create artificial employee activity.
        report['shadow_health'] = shadow.invoke(ftp, 'inspect', sha)
        report['files_published'] = changed
        report['state'] = 'published_dormant_verified'
    except Exception as error:
        report['state'] = 'failed'
        report['error'] = str(error)
        rollback = {}
        for path in reversed(changed):
            try:
                current = release.read(ftp, path)
                if current == before[path]:
                    rollback[path] = 'already_original'
                elif release.same(current, expected[path]):
                    if before[path] is None:
                        ftp.delete(path)
                    else:
                        replace_file(ftp, path, before[path])
                    rollback[path] = 'restored'
                else:
                    rollback[path] = 'concurrent_edit_preserved_requires_review'
            except Exception:
                rollback[path] = 'restore_failed_requires_review'
        report['rollback'] = rollback
        raise
    finally:
        Path('epi-stage-result.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
        print(json.dumps(report))
        ftp.quit()


if __name__ == '__main__':
    if len(sys.argv) != 3 or sys.argv[1] not in ('inspect', 'publish'):
        raise SystemExit('inspect|publish APPROVED_SHA')
    main(sys.argv[1], sys.argv[2])
