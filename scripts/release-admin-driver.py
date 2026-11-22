"""Two-phase approved admin release, using the established FTPS delta workflow.

First invocation stages only known-code recovery and a CLI migration helper.
After the owner-authorized cPanel backup/migration, invoke the same exact SHA to
publish. Database contents and credentials never leave the hosting account.
"""
import argparse, hashlib, importlib.util, io, json, os, pathlib, socket, ssl, ftplib, subprocess, zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('delta', ROOT/'scripts/release-driver-background.py')
delta = importlib.util.module_from_spec(spec)
spec.loader.exec_module(delta)
BASE = '1f4fe6648f414a23d5b9e94051a8a1a603a37a29'
REVIEWED_LIVE = {
 # Existing public commit adb33963: retain the installed parcel-confirmation wording.
 'assets/js/delivery-core.js': '5816432c9ecee68a7aa8a9a9f72e2c6201f2b75134c9f8421d38cee6519fd5b1',
 'assets/js/driver-delivery-ui.js': '7be050618222894943927a7bf50dd406cca9634a8318f6152991bf9a55c550d4',
 # Existing public commit 38e9ebc3: keep settings, marketing restrictions and badges.
 'shared/ess-navigation.php': '35bf664f8663b225b6ac2f282a8254442c6065c4cb18f22e688e9e158b61ea0c',
}
# Dependencies first; owner entry points and navigation last.
FILES = [
 'assets/css/driver-admin-operations.css',
 'assets/css/driver-admin-proof-viewer.css',
 'assets/css/driver-admin-view-as-driver.css',
 'assets/js/delivery-core.js',
 'assets/js/driver-admin-operations.js',
 'assets/js/driver-admin-proof-viewer.js',
 'assets/js/driver-admin-view-as-driver.js',
 'assets/js/driver-cash-handover.js',
 'assets/js/driver-delivery-ui.js',
 'assets/js/driver-dispatch-messages.js',
 'assets/js/driver-owner-readonly-bridge.js',
 'shared/delivery/NotificationOutbox.php',
 'shared/delivery/OwnerDriverView.php',
 'shared/delivery/OwnerDriverViewContent.php',
 'shared/delivery/OwnerDriverViewController.php',
 'shared/delivery/OwnerOperations.php',
 'shared/delivery/OwnerOperationsContent.php',
 'shared/delivery/OwnerOperationsController.php',
 'shared/delivery/OwnerProofContent.php',
 'shared/delivery/OwnerProofController.php',
 'shared/delivery/OwnerProofViewer.php',
 'shared/delivery/cash/View.php',
 'shared/delivery/dispatch/View.php',
 'shared/delivery/history/View.php',
 'shared/delivery/notifications/View.php',
 'shared/delivery/profile/View.php',
 'apps/operations/driver-delivery-details.php',
 'apps/operations/driver-operations-dashboard.php',
 'apps/operations/view-as-driver.php',
 'shared/ess-navigation.php',
 'shared/ess-topbar.php',
]
STAGE = '.driver-release-staging/admin-driver'
MIGRATION = 'database/delivery/014-owner-proof-review.sql'
HELPER = 'scripts/driver-app-release/migrate-admin-proof.php'

def main(deploy):
    sha = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if os.environ.get('APPROVED_SHA') != sha: raise RuntimeError('Approved SHA mismatch')
    changes = subprocess.check_output(['git','diff','--name-only',BASE,sha,'--','apps','assets','shared'],cwd=ROOT,text=True).splitlines()
    if set(changes) != set(FILES): raise RuntimeError('Runtime delta does not match reviewed allowlist')
    for path in FILES+[HELPER]:
        data=delta.blob(sha,path)
        if not data: raise RuntimeError('Missing release file: '+path)
        if path.endswith('.php'):subprocess.run(['php','-n','-l'],input=data,check=True,stdout=subprocess.DEVNULL)
        if path.endswith('.js'):subprocess.run(['node','--check',str(ROOT/path)],check=True)
    if not deploy:
        print('PASS: 31 exact runtime files; one CLI-only additive migration; existing verification reused.')
        return
    host=os.environ['FTP_SERVER']; canonical='s11745.sgp1.stableserver.net'
    if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):host=canonical
    with ftplib.FTP_TLS(host,context=ssl.create_default_context(),timeout=60) as ftp:
        ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
        rules=delta.read(ftp,'.driver-release-staging/.htaccess') or b''
        if b'Require all denied' not in rules or b'Deny from all' not in rules:raise RuntimeError('Protected staging unavailable')
        before={p:delta.read(ftp,p) for p in FILES}
        unknown=[]
        for p,b in before.items():
            if delta.digest(b) not in (REVIEWED_LIVE.get(p,delta.digest(delta.blob(BASE,p))),delta.digest(delta.blob(sha,p))):
                # Hashes and existing public commit IDs only; no unreviewed live source.
                refs=subprocess.check_output(['git','log','--all','--format=%H','--',p],cwd=ROOT,text=True).splitlines()
                matches=[r for r in refs if delta.digest(delta.blob(r,p))==delta.digest(b)]
                unknown.append({'path':p,'sha256':delta.digest(b),'repository_matches':matches})
        if unknown:
            print(json.dumps({'unknown_live_baselines':unknown},indent=2))
            raise RuntimeError('Live baseline review required; nothing changed')
        receipt=delta.read(ftp,STAGE+'-ready.json')
        if receipt is not None:
            receipt=json.loads(receipt)
            receipt_sha=receipt.get('sha')
            # Reuse the verified backup/migration only for the exact same runtime release.
            # Deployment diagnostics may change; no app or schema delta is allowed here.
            same_runtime=receipt_sha=='593154632fbdcfe9886ae3e22f75150c6e78becb' and subprocess.run(['git','diff','--quiet',receipt_sha,sha,'--','apps','assets','shared',MIGRATION],cwd=ROOT).returncode==0
            if (receipt_sha!=sha and not same_runtime) or receipt.get('migration_sha256')!=hashlib.sha256(delta.blob(sha,MIGRATION)).hexdigest():
                raise RuntimeError('Hosting migration receipt does not match this exact release')
            if not receipt.get('database_backup_verified') or not receipt.get('code_backup_verified'):raise RuntimeError('Backup receipt incomplete')
        else:
            # Known repository source only, no config, proofs or customer records.
            buf=io.BytesIO()
            with zipfile.ZipFile(buf,'w',zipfile.ZIP_DEFLATED) as z:
                for p,b in before.items():
                    if b is not None:z.writestr(p,b)
                z.writestr('manifest.json',json.dumps({p:delta.digest(b) for p,b in before.items()}))
            archive=buf.getvalue()
            manifest={'sha':sha,'baseline':BASE,'before':{p:delta.digest(b) for p,b in before.items()},'backup_sha256':hashlib.sha256(archive).hexdigest(),'migration_sha256':hashlib.sha256(delta.blob(sha,MIGRATION)).hexdigest()}
            staged={STAGE+'-code-before.zip':archive,STAGE+'-manifest.json':json.dumps(manifest).encode(),STAGE+'-migration.sql':delta.blob(sha,MIGRATION),STAGE+'-migrate.php':delta.blob(sha,HELPER)}
            for p,b in staged.items():
                ftp.storbinary('STOR '+p,io.BytesIO(b))
                if hashlib.sha256(delta.read(ftp,p) or b'').digest()!=hashlib.sha256(b).digest():raise RuntimeError('Stage readback failed')
            report={'sha':sha,'state':'awaiting-private-backup-and-migration','runtime_changed':False,'files':FILES}
            (ROOT/'admin-driver-report.json').write_text(json.dumps(report,indent=2))
            print(json.dumps(report,indent=2));return
    # Reuse atomic writes, guarded preflight, backup, HTTPS checks and rollback.
    delta.BASE=BASE;delta.FILES=FILES;delta.REVIEWED_LIVE=REVIEWED_LIVE
    delta.BACKUP=ROOT/'admin-driver-code-backup.zip';delta.REPORT=ROOT/'admin-driver-report.json'
    delta.main(True)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--deploy',action='store_true')
    main(parser.parse_args().deploy)
