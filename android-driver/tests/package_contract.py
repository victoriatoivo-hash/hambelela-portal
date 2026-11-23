"""No credentials or live records: validate the narrow Android wrapper contract."""
import json, pathlib, re, unittest, xml.etree.ElementTree as ET
ROOT = pathlib.Path(__file__).resolve().parents[1]
A = '{http://schemas.android.com/apk/res/android}'

class PackageContract(unittest.TestCase):
    def setUp(self):
        self.manifest = ET.parse(ROOT/'app/src/main/AndroidManifest.xml').getroot()
        self.app = self.manifest.find('application')
        self.launcher = self.app.find('activity')

    def test_actual_https_dashboard(self):
        meta = {m.get(A+'name'):m.get(A+'value') for m in self.launcher.findall('meta-data')}
        self.assertEqual(meta['android.support.customtabs.trusted.DEFAULT_URL'], 'https://portal.hambelelaorganic.com/index.php')

    def test_no_sensitive_native_permissions(self):
        self.assertEqual([p.get(A+'name') for p in self.manifest.findall('uses-permission')], ['android.permission.INTERNET'])

    def test_no_backup_or_cleartext(self):
        self.assertEqual(self.app.get(A+'allowBackup'), 'false')
        self.assertEqual(self.app.get(A+'usesCleartextTraffic'), 'false')

    def test_only_launcher_entry_not_all_portal_links(self):
        self.assertEqual(len(self.app.findall('activity')), 1)
        actions = [a.get(A+'name') for a in self.launcher.findall('intent-filter/action')]
        self.assertEqual(actions, ['android.intent.action.MAIN'])

    def test_no_custom_webview_or_javascript_bridge(self):
        self.assertEqual(list((ROOT/'app/src').rglob('*.java')), [])
        self.assertEqual(list((ROOT/'app/src').rglob('*.kt')), [])

    def test_domain_association(self):
        links = json.loads((ROOT/'assetlinks.json').read_text())
        self.assertEqual(len(links), 1)
        self.assertEqual(links[0]['relation'], ['delegate_permission/common.handle_all_urls'])
        self.assertEqual(links[0]['target']['namespace'], 'android_app')
        self.assertEqual(links[0]['target']['package_name'], 'com.hambelelaorganic.driver')
        self.assertRegex(links[0]['target']['sha256_cert_fingerprints'][0], r'^(?:[0-9A-F]{2}:){31}[0-9A-F]{2}$')

    def test_app_association_matches_domain(self):
        values = ET.parse(ROOT/'app/src/main/res/values/strings.xml').getroot()
        value = values.find("string[@name='asset_statements']").text.replace('\\"','"')
        self.assertEqual(json.loads(value)[0]['target']['site'], 'https://portal.hambelelaorganic.com')

    def test_no_private_keys_in_source(self):
        self.assertFalse(any(p.suffix in ('.p12','.jks','.keystore','.dpapi') for p in ROOT.rglob('*')))

    def test_existing_brand_icon(self):
        self.assertEqual((ROOT/'app/src/main/res/drawable/driver_icon.png').read_bytes(), (ROOT.parent/'assets/pwa/hambelela-512.png').read_bytes())

    def test_pinned_dependencies_and_separate_signing(self):
        build = (ROOT/'app/build.gradle').read_text()
        self.assertIn("androidbrowserhelper:2.7.4", build)
        self.assertNotIn('signingConfig ', build)
        self.assertIn('targetSdk 36', build)
        self.assertIn('minSdk 26', build)

if __name__ == '__main__':
    unittest.main()
