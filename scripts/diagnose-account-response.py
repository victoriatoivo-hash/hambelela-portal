import ftplib, os, io, hashlib, subprocess, re
ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=30)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
try:
    path = 'apps/operations/my-account.php'
    data = io.BytesIO()
    ftp.retrbinary('RETR ' + path, data.write)
    live = data.getvalue()
    local = subprocess.check_output(['git', 'show', 'HEAD:' + path])
    print('Account page matches repository:', live == local)
    print('Live code SHA256:', hashlib.sha256(live).hexdigest())
    for path in ['apps/operations/error_log', 'error_log']:
        try:
            ftp.voidcmd('TYPE I')
            size = ftp.size(path)
            output = io.BytesIO()
            ftp.retrbinary('RETR ' + path, output.write, rest=max(0, (size or 0)-65536) or None)
            lines = output.getvalue().decode('utf-8', 'replace').splitlines()
            relevant = [line for line in lines if ('my-account.php' in line or 'access_secret' in line) and ('Fatal' in line or 'Warning' in line or 'Error' in line)]
            print(path, 'matching errors:')
            for line in relevant[-12:]:
                line = re.sub(r'[\w.+-]+@[\w.-]+', '[email]', line)
                print(line[:1200])
        except ftplib.error_perm:
            print(path, 'unavailable')
finally:
    ftp.quit()
