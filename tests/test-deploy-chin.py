import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location("deploy_chin", Path(__file__).resolve().parents[1] / "scripts/deploy-chin.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class FakeFTP:
    def __init__(self):
        self.files = {name: b"old" for name in module.FILES}
        self.files.update({".chin-deploy-target": module.MARKER, "bootstrap.php": b"bootstrap"})
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
            raise OSError("simulated interrupted upload")

class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.ftp = FakeFTP()
        self.bundle = {module.PREFIX + name: "new" for name in module.FILES}
        self.bundle[module.PREFIX + "bootstrap.php"] = "bootstrap"
    def test_preflight_never_writes(self):
        module.deploy(self.ftp, self.bundle, True)
        self.assertEqual(self.ftp.writes, [])
    def test_wrong_destination_never_writes(self):
        self.ftp.files[".chin-deploy-target"] = b"portal"
        with self.assertRaises(RuntimeError):
            module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_changed_bootstrap_never_writes(self):
        self.ftp.files["bootstrap.php"] = b"other"
        with self.assertRaises(RuntimeError):
            module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.writes, [])
    def test_only_four_app_files_are_updated(self):
        module.deploy(self.ftp, self.bundle)
        self.assertEqual(set(self.ftp.writes), set(module.FILES))
        self.assertEqual(self.ftp.files["bootstrap.php"], b"bootstrap")
        module.deploy(self.ftp, self.bundle)
        self.assertEqual(len(self.ftp.writes), 4)
    def test_partial_upload_rolls_back_every_touched_file(self):
        before = dict(self.ftp.files)
        self.ftp.fail = module.FILES[1]
        with self.assertRaisesRegex(RuntimeError, "previous files restored"):
            module.deploy(self.ftp, self.bundle)
        self.assertEqual(self.ftp.files, before)

if __name__ == "__main__":
    unittest.main()
