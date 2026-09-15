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
    for path in ['apps/operations/error_log', 'error_log']:
        try:
            ftp.voidcmd('TYPE I')
            size = ftp.size(path)
            output = io.BytesIO()
            ftp.retrbinary('RETR ' + path, output.write, rest=max(0, (size or 0)-65536) or None)
            lines = output.getvalue().decode('utf-8', 'replace').splitlines()
            relevant = [line for line in lines if ('my-account.php' in line or 'access_secret' in line) and ('Fatal' in line or 'Warning' in line or 'Error' in line)]
            # Emit only fixed labels and counts, never log text or user data.
            print(path, 'matching error count:', len(relevant))
            # Names come only from repository code, not arbitrary log content.
            known_functions = set(re.findall(r'\b([a-zA-Z_][a-zA-Z_0-9]*)\s*\(', local.decode('utf-8', 'replace')))
            for name in sorted(known_functions):
                count = sum(('undefined function ' + name + '(').lower() in line.lower() for line in relevant)
                if count:
                    print('undefined_repository_function', name, count)
            for label, pattern in {
                'undefined_function': 'undefined function',
                'type_error': 'TypeError',
                'database_error': 'SQLSTATE',
                'headers_already_sent': 'headers already sent',
                'undefined_variable': 'Undefined variable',
                'missing_column': 'Unknown column',
                'unbuffered_query': 'unbuffered',
                'missing_table': "doesn't exist",
                'parse_error': 'syntax error',
            }.items():
                print(label, sum(pattern.lower() in line.lower() for line in relevant))
        except ftplib.error_perm:
            print(path, 'unavailable')
finally:
    ftp.quit()
