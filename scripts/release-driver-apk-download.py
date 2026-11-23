"""Two presentation files only; add links to the already signed Android APK."""
import argparse, importlib.util, os, pathlib, subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='28ba2336cc5db7dfc5493c0baba18eead1bcb3e2'
FILES=['shared/delivery/dashboard/BottomNav.php','shared/delivery/profile/View.php']
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if os.environ.get('APPROVED_SHA')!=sha:raise RuntimeError('Approved SHA mismatch')
    paths=subprocess.check_output(['git','diff','--name-only',BASE,sha,'--','apps','assets','shared','database'],cwd=ROOT,text=True).splitlines()
    if paths!=FILES:raise RuntimeError('Unexpected runtime or schema change')
    spec=importlib.util.spec_from_file_location('delta',ROOT/'scripts/release-driver-background.py');delta=importlib.util.module_from_spec(spec);spec.loader.exec_module(delta)
    delta.BASE=BASE;delta.FILES=FILES
    delta.BACKUP=ROOT/'driver-apk-download-backup.zip';delta.REPORT=ROOT/'driver-apk-download-report.json'
    delta.main(args.deploy)
