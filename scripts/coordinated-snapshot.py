"""Read-only live source snapshot for an isolated coordinated release."""
import ftplib, io, os, zipfile, hashlib, json
FILES=['index.php','shared/header.php','shared/footer.php','shared/sidebar.php','shared/auth.php','shared/employee-features.php','shared/notifications.php','apps/operations/packing-list-action.php','apps/operations/consignments.php','assets/js/packing-list.js','assets/js/orders-board.js','assets/css/orders-board.css','shared/epi/Performance.php']
ftp=ftplib.FTP_TLS(os.environ['FTP_SERVER'],timeout=60)
ftp.login(os.environ['FTP_USERNAME'],os.environ['FTP_PASSWORD']);ftp.prot_p()
manifest={}
with zipfile.ZipFile('coordinated-live-source.zip','w') as archive:
 for path in FILES:
  content=io.BytesIO();ftp.retrbinary('RETR '+path,content.write);data=content.getvalue()
  archive.writestr(path,data);manifest[path]=hashlib.sha256(data).hexdigest()
 archive.writestr('manifest.json',json.dumps(manifest,indent=2))
ftp.quit()
