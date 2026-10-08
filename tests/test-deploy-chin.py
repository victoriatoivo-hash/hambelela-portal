import importlib.util
import hashlib
import os
import unittest
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('deploy_chin', Path(__file__).resolve().parents[1] / 'scripts/deploy-chin.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class FakeFTP:
    def __init__(self):
        self.files = {name: b'old' for name in module.FILES}
        self.files.update({'.chin-deploy-target': module.MARKER, 'setup-key.php': b'private setup key', 'login.php': b'existing login', 'schema.php': b'existing schema', '.miv-shipping-private/miv.sqlite': b'account and records'})
        self.writes = []
        self.fail = None
    def retrbinary(self, command, callback):
        callback(self.files[command[5:]])
    def storbinary(self, command, data):
        name = command[5:]
        self.writes.append(name)
        self.files[name] = data.read()
        if name == self.fail:
            self.fail = None
            raise OSError('simulated interrupted upload')

class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.ftp = FakeFTP()
        self.bundle = {module.PREFIX + name: 'new' for name in module.FILES}
        self.old_hashes = patch.dict(module.PREVIOUS_HASHES, {name: hashlib.sha256(b'old').hexdigest() for name in module.PREVIOUS_HASHES})
        self.old_hashes.start()
        self.addCleanup(self.old_hashes.stop)
        self.clearance = patch.dict(os.environ, {'CHIN_SCANNER_CLEARANCE_SHA256': module.payload_hash(self.bundle)})
        self.clearance.start()
        self.addCleanup(self.clearance.stop)
    def test_preflight_never_writes(self):
        module.deploy(self.ftp, self.bundle, True)
        self.assertEqual(self.ftp.writes, [])
    def test_wrong_destination_never_writes(self):
        self.ftp.files['.chin-deploy-target'] = b'portal'
        with self.assertRaises(RuntimeError): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_marker_must_match_exactly(self):
        self.ftp.files['.chin-deploy-target'] += b'\n'
        with self.assertRaises(RuntimeError): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_changed_bootstrap_never_writes(self):
        self.ftp.files['bootstrap.php'] = b'unknown manual configuration'
        with self.assertRaises(RuntimeError): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_changed_extract_never_writes(self):
        self.ftp.files['extract.php'] = b'unknown manual configuration'
        with self.assertRaises(RuntimeError): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_only_six_repair_files_updated_and_private_files_preserved(self):
        preserved = {name: value for name, value in self.ftp.files.items() if name not in module.FILES}
        module.deploy(self.ftp, self.bundle)
        self.assertEqual(set(self.ftp.writes), set(module.FILES))
        for name, value in preserved.items(): self.assertEqual(self.ftp.files[name], value)
        module.deploy(self.ftp, self.bundle)
        self.assertEqual(len(self.ftp.writes), 6)
    def test_partial_upload_rolls_back_every_touched_file(self):
        before = dict(self.ftp.files)
        self.ftp.fail = module.FILES[1]
        with self.assertRaisesRegex(RuntimeError, 'previous files restored'): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.files, before)
    def test_missing_scanner_clearance_never_writes(self):
        with patch.dict(os.environ, {'CHIN_SCANNER_CLEARANCE_SHA256': ''}):
            with self.assertRaisesRegex(RuntimeError, 'scanner clearance'): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_changed_payload_invalidates_scanner_clearance(self):
        self.bundle[module.PREFIX + 'api.php'] = 'different release'
        with self.assertRaisesRegex(RuntimeError, 'scanner clearance'): module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])

if __name__ == '__main__': unittest.main()
