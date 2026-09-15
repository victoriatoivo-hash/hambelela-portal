"""Pinned sidebar-only release using the existing backup/verification engine."""
import argparse
import importlib.util
import pathlib

spec = importlib.util.spec_from_file_location('engine', pathlib.Path(__file__).with_name('deploy-settings-release.py'))
engine = importlib.util.module_from_spec(spec)
spec.loader.exec_module(engine)
engine.APPROVED = '2733fcf5b1d86e82cce00a8b4f6c59b76a9eb55e'
engine.BASELINE = '7fa04c0848a428889c7d5f82f745789e07024ed6'
engine.COMMIT_FILES = [
    'apps/hr-portal/includes/emp-sidebar.php',
    'apps/hr-portal/includes/sidebar.php',
    'assets/css/hr-sidebar-theme.css',
    'assets/css/sidebar-badges.css',
    'assets/js/portal.js',
    'shared/ess-navigation.php',
    'shared/ess-sidebar.php',
    'shared/header.php',
    'shared/notifications.php',
]
engine.DEPLOY_FILES = engine.COMMIT_FILES[:]
engine.baseline_blob = lambda path: engine.blob('57e6e4e06db896647d5fb4844598d5616cc66a54' if path == 'shared/notifications.php' else engine.BASELINE, path)
if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--dry-run')
    group.add_argument('--deploy')
    args = parser.parse_args()
    if args.dry_run:
        engine.validate(args.dry_run)
        print('PASS: sidebar-only manifest, 9 files')
    else:
        engine.deploy(args.deploy)
