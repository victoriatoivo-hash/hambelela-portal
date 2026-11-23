"""Synthetic deployment tests. Never connect to hosting."""
import contextlib, importlib.util, io, json, os, pathlib, unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('release',ROOT/'scripts/release-driver-android-association.py')
release=importlib.util.module_from_spec(spec); spec.loader.exec_module(release)
WANTED=(ROOT/'android-driver/assetlinks.json').read_bytes()

class Report:
    def write_text(self, text): self.value=json.loads(text)

class FakeFTP:
    def __init__(self, old=None):
        self.files={'.driver-release-staging/.htaccess':b'Require all denied\nDeny from all'}
        if old is not None: self.files[release.TARGET]=old
        self.writes=[]
    def __enter__(self): return self
    def __exit__(self,*args): pass
    def login(self,*args): pass
    def prot_p(self): pass
    def cwd(self,path): pass
    def mkd(self,path): self.writes.append(path)
    def retrbinary(self,command,callback):
        path=command[5:]
        if path not in self.files: raise release.ftplib.error_perm('550 absent')
        callback(self.files[path])
    def storbinary(self,command,stream):
        self.files[command[5:]]=stream.read();self.writes.append(command[5:])
    def rename(self,source,target):
        self.files[target]=self.files.pop(source);self.writes.append(target)

class Response:
    url=release.URL; status=200
    def __init__(self,body=WANTED): self.body=body;self.headers=self
    def get_content_type(self): return 'application/json'
    def read(self): return self.body
    def __enter__(self): return self
    def __exit__(self,*args): pass

class AssociationRelease(unittest.TestCase):
    def run_release(self,ftp,response=None):
        report=Report()
        with contextlib.ExitStack() as stack:
            stack.enter_context(patch.dict(os.environ,{'APPROVED_SHA':'approved','FTP_SERVER':'hosting','FTP_USERNAME':'synthetic','FTP_PASSWORD':'synthetic'}))
            stack.enter_context(patch.object(release.subprocess,'check_output',return_value='approved\n'))
            stack.enter_context(patch.object(release.socket,'gethostbyname_ex',return_value=('hosting',[],['127.0.0.1'])))
            stack.enter_context(patch.object(release.ftplib,'FTP_TLS',return_value=ftp))
            stack.enter_context(patch.object(release.urllib.request,'urlopen',return_value=response or Response()))
            stack.enter_context(patch.object(release,'REPORT',report))
            stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
            release.main(True)
        return report.value

    def test_publish_only_public_association(self):
        ftp=FakeFTP(); result=self.run_release(ftp)
        self.assertEqual(ftp.files[release.TARGET],WANTED)
        self.assertEqual(result['state'],'deployed-verified')
        self.assertFalse(result['database_changes'])
        self.assertTrue(all(p.startswith('.well-known/') for p in ftp.writes))

    def test_existing_same_association_is_not_replaced(self):
        ftp=FakeFTP(WANTED);self.run_release(ftp)
        self.assertNotIn(release.TARGET,ftp.writes)
        self.assertTrue(any('before-' in p for p in ftp.writes))

    def test_existing_other_app_is_preserved(self):
        original=b'[{"target":{"package_name":"another.app"}}]'
        ftp=FakeFTP(original)
        with self.assertRaisesRegex(RuntimeError,'Existing Android association differs'):self.run_release(ftp)
        self.assertEqual(ftp.files[release.TARGET],original)
        self.assertEqual(ftp.writes,[])

    def test_bad_https_rolls_back_only_new_file(self):
        ftp=FakeFTP()
        with self.assertRaisesRegex(RuntimeError,'verification mismatch'):self.run_release(ftp,Response(b'[]'))
        self.assertNotIn(release.TARGET,ftp.files)
        self.assertTrue(any('rolled-back-' in p for p in ftp.files))

    def test_no_recovery_protection_no_changes(self):
        ftp=FakeFTP();ftp.files.clear()
        with self.assertRaisesRegex(RuntimeError,'Protected recovery'):self.run_release(ftp)
        self.assertEqual(ftp.writes,[])

if __name__=='__main__': unittest.main()
