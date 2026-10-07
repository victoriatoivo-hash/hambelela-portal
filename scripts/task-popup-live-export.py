"""Read only: export the current task/notification source, excluding secrets/data."""
import ftplib
import hashlib
import io
import json
import os
import zipfile

FILES = (
    'apps/operations/checklists.php', 'apps/operations/operations.php',
    'shared/notifications.php', 'shared/task-reminders.php',
    'shared/task-scheduling.php', 'shared/task-floating.php',
    'shared/header.php', 'shared/footer.php',
    'assets/js/portal.js', 'assets/js/task-essentials.js',
    'assets/js/portal-presence.js', 'assets/js/notifications-ui.js',
    'assets/js/notifications-page.js', 'assets/css/notifications-ui.css',
    'assets/css/notifications-page.css', 'assets/css/urgent-task-alert.css',
    'api/notifications.php', 'api/notifications-feed.php',
    'notifications-api.php', 'notifications.php',
)

ftp = ftplib.FTP(os.environ['FTP_SERVER'], timeout=45)
ftp.login(os.environ['FTP_USERNAME'], os.environ['FTP_PASSWORD'])
report = {}
try:
    with zipfile.ZipFile('task-popup-live-source.zip', 'w') as archive:
        for path in FILES:
            output = io.BytesIO()
            try:
                ftp.retrbinary('RETR ' + path, output.write)
            except ftplib.error_perm as error:
                if str(error).startswith('550'):
                    report[path] = None
                    continue
                raise
            content = output.getvalue()
            archive.writestr(path, content)
            report[path] = hashlib.sha256(content).hexdigest()
        archive.writestr('task-popup-live-hashes.json', json.dumps(report, indent=2))
finally:
    ftp.quit()
with open('task-popup-live-hashes.json', 'w') as handle:
    json.dump(report, handle, indent=2)
print('Read-only source export complete: ' + str(len(report)) + ' paths inspected.')
