"""One-file post-deployment correction; no schema or business data changes."""
import argparse, importlib.util, os, pathlib, subprocess

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='5a4a1d90c97ec5511bdd72504e4a544cca95764c'
FILES=['shared/delivery/OwnerDriverView.php']
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--deploy',action='store_true');args=parser.parse_args()
    sha=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    if os.environ.get('APPROVED_SHA')!=sha:raise RuntimeError('Approved SHA mismatch')
    paths=subprocess.check_output(['git','diff','--name-only',BASE,sha,'--','apps','assets','shared','database'],cwd=ROOT,text=True).splitlines()
    if paths!=FILES:raise RuntimeError('Unexpected runtime or schema change')
    spec=importlib.util.spec_from_file_location('delta',ROOT/'scripts/release-driver-background.py');delta=importlib.util.module_from_spec(spec);spec.loader.exec_module(delta)
    delta.BASE=BASE;delta.FILES=FILES
    delta.BACKUP=ROOT/'admin-driver-code-backup.zip';delta.REPORT=ROOT/'admin-driver-report.json'
    delta.main(args.deploy)
