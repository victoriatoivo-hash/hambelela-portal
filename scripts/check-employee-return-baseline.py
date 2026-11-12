"""Read only named release files. Log Git blob IDs, never contents or settings."""
import ftplib
import hashlib
import io
import os
import socket
import ssl

targets = (
    'shared/auth.php', 'login.php', 'change-access-code.php',
    'shared/employee-device-session.php', 'shared/portal-presence-source.php',
    'apps/operations/portal-presence.php', 'apps/operations/kpi-employee-data.php',
    'assets/js/portal-presence.js',
)
host = os.environ['FTP_SERVER']
canonical = 's11745.sgp1.stableserver.net'
if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
    host = canonical
with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=30) as ftp:
    ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
    ftp.prot_p()
    for path in targets:
        buf = io.BytesIO()
        try:
            ftp.retrbinary('RETR ' + path, buf.write)
        except ftplib.error_perm as exc:
            if path == 'shared/employee-device-session.php' and str(exc).startswith('550'):
                print('BASELINE', path, 'not-readable-or-absent')
                continue
            raise
        data = buf.getvalue().replace(b'\r\n', b'\n')
        blob = hashlib.sha1(b'blob ' + str(len(data)).encode('ascii') + b'\0' + data).hexdigest()
        print('BASELINE', path, blob)
