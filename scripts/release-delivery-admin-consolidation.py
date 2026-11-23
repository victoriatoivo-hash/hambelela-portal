"""Presentation-only Delivery consolidation. Exact baseline, protected backup, atomic release.

No database credentials, migrations, customer data or driver-mobile runtime changes.
"""
import argparse, importlib.util, os, pathlib, subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='f9c679df29379edf327fc098ddba727c991023fe'
# Assets and shared dependencies first; public routes and navigation last.
FILES=[
    'assets/css/delivery-admin.css',
    'assets/css/driver-admin-operations.css',
    'assets/css/driver-admin-proof-viewer.css',
    'assets/css/driver-admin-view-as-driver.css',
    'assets/js/driver-admin-operations.js',
    'assets/js/delivery-admin.js',
    'assets/js/driver-admin-view-as-driver.js',
    'assets/js/ess-dashboard.js',
    'shared/delivery/OwnerNavigation.php',
    'apps/delivery/driver-operations.php',
    'apps/delivery/delivery-details.php',
    'apps/delivery/view-as-driver.php',
    'shared/delivery/OwnerOperations.php',
    'shared/delivery/OwnerOperationsContent.php',
    'shared/delivery/OwnerProofContent.php',
    'shared/delivery/OwnerDriverViewContent.php',
    'shared/delivery/OwnerOperationsController.php',
    'shared/delivery/OwnerProofController.php',
    'shared/delivery/OwnerDriverViewController.php',
    'apps/delivery/driver-view.php',
    'apps/delivery/index.php',
    'assets/js/delivery-core.js',
    'shared/delivery/DashboardView.php',
    'shared/delivery/Navigation.php',
    'shared/ess-navigation.php',
]
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if os.environ.get('APPROVED_SHA')!=sha:raise RuntimeError('Approved SHA mismatch')
    paths=subprocess.check_output(['git','diff','--name-only',BASE,sha,'--','apps','assets','shared','database'],cwd=ROOT,text=True).splitlines()
    if sorted(paths)!=sorted(FILES):raise RuntimeError('Unexpected runtime or schema change')
    spec=importlib.util.spec_from_file_location('delta',ROOT/'scripts/release-driver-background.py');delta=importlib.util.module_from_spec(spec);spec.loader.exec_module(delta)
    delta.BASE=BASE;delta.FILES=FILES
    # Exact live versions reviewed locally; preserve driver actor support and shared menu fixes.
    delta.REVIEWED_LIVE={
        'assets/js/ess-dashboard.js':'b4a692f8b4b0ed1f3a0cc1b57a47af0ce92417cda46215af2edbee0b92d03119',
        'shared/delivery/Navigation.php':'26dbfe58cb320eb1c36a3db2bbaecabedcfb42d4d5aa5261cba6ed5da8ad9a6b',
        'shared/delivery/DashboardView.php':'b410c0e6af0fc03ded23c6abc75a78519eec5e671c37cc2dd5af03af9a977f56',
    }
    delta.BACKUP=ROOT/'delivery-admin-consolidation-backup.zip';delta.REPORT=ROOT/'delivery-admin-consolidation-report.json'
    delta.main(args.deploy)
