"""Validate and stage the allowlisted driver release through the existing FTPS account.
No credentials, records, backups, offline router or local identity adapters enter this package.
"""
import ftplib, hashlib, io, json, os, pathlib, socket, ssl, subprocess, zipfile

root = pathlib.Path(__file__).resolve().parents[2]
manifest = json.loads((root / 'scripts/driver-app-release/manifest.json').read_text())
sha = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
if sha != os.environ.get('APPROVED_SHA'):
    raise RuntimeError('Run only the approved checked-out commit.')

def digest(data):
    return hashlib.sha256(data.replace(b'\r\n', b'\n')).hexdigest()

paths = list(manifest['files']) + list(manifest['migrations']) + [
    'scripts/driver-app-release/manifest.json', 'scripts/driver-app-release/apply.php',
    'scripts/driver-app-release/backup.php',
]
archive = root / ('driver-release-' + sha + '.zip')
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as z:
    for path in paths:
        if '..' in pathlib.PurePosixPath(path).parts or path.startswith('/'):
            raise RuntimeError('Unsafe release path.')
        data = (root / path).read_bytes()
        expected = manifest['files'].get(path, {}).get('after', manifest['migrations'].get(path))
        if expected and digest(data) != expected:
            raise RuntimeError('Unexpected bytes: ' + path)
        if path.endswith('.php'):
            subprocess.run(['php', '-n', '-l'], input=data, stdout=subprocess.DEVNULL, check=True)
        if path.endswith('.js'):
            subprocess.run(['node', '--check', str(root / path)], check=True)
        z.writestr(path, data)
payload = archive.read_bytes()
checksum = hashlib.sha256(payload).hexdigest()
print('Approved commit:', sha)
print('Package SHA256:', checksum)
print('Allowlisted package files:', len(paths))
if os.environ.get('UPLOAD_RELEASE') != '1':
    raise SystemExit(0)
host = os.environ['FTP_SERVER']
canonical = 's11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical
with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=60) as ftp:
    ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    folder = '.driver-release-staging'
    try:
        ftp.mkd(folder)
    except ftplib.error_perm as e:
        if not str(e).startswith('550'):
            raise
    # This contains public repository code only. Deny HTTP access before upload.
    ftp.storbinary('STOR ' + folder + '/.htaccess', io.BytesIO(b'Require all denied\nDeny from all\n'))
    dest = folder + '/' + archive.name
    ftp.storbinary('STOR ' + dest, io.BytesIO(payload))
    received = io.BytesIO()
    ftp.retrbinary('RETR ' + dest, received.write)
    if hashlib.sha256(received.getvalue()).hexdigest() != checksum:
        raise RuntimeError('Staged package readback failed.')
print('Staged and verified:', dest)
print('Application remains unchanged until the reviewed cPanel CLI apply step.')
