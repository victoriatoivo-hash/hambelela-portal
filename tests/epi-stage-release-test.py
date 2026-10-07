"""Release safety tests use an in-memory host; never production credentials."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('stage', Path(__file__).resolve().parents[1] / 'scripts/epi-stage-release.py')
stage = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage)


class Host:
    def __init__(self):
        self.files = {'old.php': b'baseline'}
        self.renames = []
    def login(self, *args): pass
    def quit(self): pass
    def delete(self, path): del self.files[path]
    def rename(self, old, new):
        self.renames.append(new)
        self.files[new] = self.files.pop(old)


class ReleaseTests(unittest.TestCase):
    def run_release(self, mode='publish', conflict=False, fail_health=False, concurrent=False):
        host = Host()
        if conflict: host.files['old.php'] = b'live-only'
        with tempfile.TemporaryDirectory() as folder, contextlib.ExitStack() as stack:
            previous = os.getcwd()
            os.chdir(folder)
            stack.callback(os.chdir, previous)
            stack.enter_context(patch.dict(os.environ, {'FTP_SERVER': 'fake', 'FTP_USERNAME': 'fake', 'FTP_PASSWORD': 'fake'}))
            stack.enter_context(patch.object(stage, 'FILES', ('new.php', 'old.php')))
            stack.enter_context(patch.object(stage.release.ftplib, 'FTP', return_value=host))
            stack.enter_context(patch.object(stage.subprocess, 'check_output', return_value=b'approved'))
            stack.enter_context(patch.object(stage.release, 'blob', side_effect=lambda sha, p: b'new' if sha == 'approved' else (b'baseline' if p == 'old.php' else None)))
            stack.enter_context(patch.object(stage.release, 'read', side_effect=lambda ftp, p: ftp.files.get(p)))
            stack.enter_context(patch.object(stage.release, 'write', side_effect=lambda ftp, p, value: ftp.files.__setitem__(p, value)))
            stack.enter_context(patch.object(stage, 'preflight', return_value={'flags': {}}))
            def health(*args):
                if concurrent: host.files['old.php'] = b'concurrent edit'
                if fail_health: raise RuntimeError('host health failed')
                return {'ok': True}
            stack.enter_context(patch.object(stage.shadow, 'invoke', side_effect=health))
            with contextlib.redirect_stdout(io.StringIO()):
                if conflict or fail_health:
                    with self.assertRaises(RuntimeError): stage.main(mode, 'approved')
                else: stage.main(mode, 'approved')
            result = json.loads(Path('epi-stage-result.json').read_text())
            return host, result

    def test_inspect_does_not_publish(self):
        host, result = self.run_release('inspect')
        self.assertEqual(host.files, {'old.php': b'baseline'})
        self.assertEqual(result['state'], 'inspected')

    def test_publication_stays_dormant(self):
        host, result = self.run_release()
        self.assertEqual(host.files, {'new.php': b'new', 'old.php': b'new'})
        self.assertFalse(result['features_activated'])
        self.assertFalse(result['migrations_run'])
        self.assertFalse(result['official_scores_changed'])
        self.assertEqual(host.renames, ['new.php', 'old.php'])

    def test_live_drift_refuses_all_uploads(self):
        host, result = self.run_release(conflict=True)
        self.assertEqual(host.files, {'old.php': b'live-only'})
        self.assertEqual(result['conflicts'], ['old.php'])

    def test_failure_restores_previous_files(self):
        host, result = self.run_release(fail_health=True)
        self.assertEqual(host.files, {'old.php': b'baseline'})
        self.assertEqual(result['rollback']['old.php'], 'restored')

    def test_rollback_preserves_concurrent_edits(self):
        host, result = self.run_release(fail_health=True, concurrent=True)
        self.assertEqual(host.files, {'old.php': b'concurrent edit'})
        self.assertEqual(result['rollback']['old.php'], 'concurrent_edit_preserved_requires_review')


if __name__ == '__main__': unittest.main()
