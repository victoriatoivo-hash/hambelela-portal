"""Source-only verification for the approved acknowledgment scheduling delta."""
import ftplib, io, os, zipfile, hashlib, json
FILES=['shared/acknowledgments/Service.php','shared/acknowledgments/integration.php','apps/acknowledgments/api.php','apps/acknowledgments/index.php','assets/js/acknowledgments.js','assets/js/acknowledgments-popup.js','assets/css/acknowledgments.css','shared/notifications.php','assets/js/portal.js','scripts/epi-v2-deadline-watchdog.php']
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
manifest={}
with zipfile.ZipFile('ack-live-source.zip','w') as archive:
 for path in FILES:
  content=io.BytesIO();ftp.retrbinary('RETR '+path,content.write);data=content.getvalue()
  archive.writestr(path,data);manifest[path]=hashlib.sha256(data).hexdigest()
 archive.writestr('manifest.json',json.dumps(manifest,indent=2))
ftp.quit()
