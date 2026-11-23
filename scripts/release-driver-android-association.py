"""Publish one public ownership statement, never PHP, DB, session or employee data."""
import argparse, ftplib, hashlib, io, json, os, pathlib, socket, ssl, subprocess, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[1]
TARGET = '.well-known/assetlinks.json'
URL = 'https://portal.hambelelaorganic.com/' + TARGET
REPORT = ROOT/'android-association-report.json'

def read(ftp, path):
    stream = io.BytesIO()
    try:
        ftp.retrbinary('RETR '+path, stream.write)
    except ftplib.error_perm as error:
        if str(error).startswith('550'):
            return None
        raise
    return stream.getvalue()

def main(deploy):
    sha = subprocess.check_output(['git','rev-parse','HEAD'], cwd=ROOT, text=True).strip()
    if os.environ.get('APPROVED_SHA') != sha:
        raise RuntimeError('Exact approved source SHA required')
    wanted = (ROOT/'android-driver/assetlinks.json').read_bytes()
    parsed = json.loads(wanted)
    if len(parsed)!=1 or parsed[0]['target']['package_name']!='com.hambelelaorganic.driver':
        raise RuntimeError('Unexpected Android association')
    if not deploy:
        print('PASS: public association only; no runtime or database changes')
        return
    state = {'sha':sha, 'path':TARGET, 'state':'preflight', 'database_changes':False}
    host = os.environ['FTP_SERVER']
    canonical = 's11745.sgp1.stableserver.net'
    if set(socket.gethostbyname_ex(host)[2]) & set(socket.gethostbyname_ex(canonical)[2]):
        host = canonical
    with ftplib.FTP_TLS(host, context=ssl.create_default_context(), timeout=45) as ftp:
        ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
        ftp.prot_p()
        protection = read(ftp,'.driver-release-staging/.htaccess') or b''
        if b'Require all denied' not in protection or b'Deny from all' not in protection:
            raise RuntimeError('Protected recovery directory required')
        old = read(ftp,TARGET)
        # Do not overwrite associations belonging to another app or publisher.
        if old is not None and json.loads(old) != parsed:
            raise RuntimeError('Existing Android association differs; nothing changed')
        state['previously_present'] = old is not None
        state['previous_sha256'] = hashlib.sha256(old).hexdigest() if old is not None else None
        if old is not None:
            backup = '.driver-release-staging/android-association-before-'+sha[:12]+'.json'
            ftp.storbinary('STOR '+backup,io.BytesIO(old))
            if read(ftp,backup)!=old: raise RuntimeError('Association backup verification failed')
        changed = False
        try:
            if old is None:
                try: ftp.cwd('.well-known'); ftp.cwd('..')
                except ftplib.error_perm: ftp.mkd('.well-known')
                temp = '.well-known/assetlinks-'+sha[:12]+'.tmp'
                ftp.storbinary('STOR '+temp,io.BytesIO(wanted))
                if read(ftp,temp)!=wanted: raise RuntimeError('Association upload mismatch')
                if read(ftp,TARGET) is not None: raise RuntimeError('Concurrent association publication; nothing replaced')
                ftp.rename(temp,TARGET); changed = True
            request = urllib.request.Request(URL,headers={'Cache-Control':'no-cache','Accept-Encoding':'identity','User-Agent':'Hambelela-Release-Verification/1.0'})
            with urllib.request.urlopen(request,timeout=30) as response:
                if response.url!=URL or response.status!=200 or response.headers.get_content_type()!='application/json':
                    raise RuntimeError('Association must be public HTTPS JSON without redirects')
                if json.loads(response.read())!=parsed: raise RuntimeError('Public certificate verification mismatch')
            state.update(state='deployed-verified',public_sha256=hashlib.sha256(wanted).hexdigest())
        except Exception:
            if changed:
                ftp.rename(TARGET,'.driver-release-staging/android-association-rolled-back-'+sha[:12]+'.json')
            state['state']='rolled-back' if changed else 'unchanged'
            raise
        finally:
            REPORT.write_text(json.dumps(state,indent=2))
    print(json.dumps(state,indent=2))

if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('--deploy',action='store_true')
    main(parser.parse_args().deploy)
