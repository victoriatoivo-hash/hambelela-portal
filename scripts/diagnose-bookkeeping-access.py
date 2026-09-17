"""Read-only check: does the live server already have Bookkeeping enabled
for the Marketing & Sales Assistant role? Never writes to the live server."""
import ftplib, os, io, hashlib, subprocess

FILES = ["shared/employee-features.php", "shared/ess-navigation.php", "apps/operations/bookkeeping.php"]


def digest(data):
    return hashlib.sha256(data).hexdigest() if data is not None else None


def local_blob(path):
    result = subprocess.run(["git", "show", "HEAD:" + path], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    return result.stdout if result.returncode == 0 else None


def read(ftp, path):
    out = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, out.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return out.getvalue()


ftp = ftplib.FTP(os.environ["FTP_SERVER"], timeout=30)
ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
try:
    for path in FILES:
        live = read(ftp, path)
        local = local_blob(path)
        matches = live is not None and local is not None and live == local
        print(path, "| live_present:", live is not None, "| matches_repo_head:", matches,
              "| live_sha256:", digest(live), "| repo_sha256:", digest(local))
        if live is not None and local is not None and not matches and path.endswith(".php"):
            live_lines = live.decode("utf-8", "replace").splitlines()
            local_lines = local.decode("utf-8", "replace").splitlines()
            live_ms = next((l for l in live_lines if "marketing_sales" in l), None)
            local_ms = next((l for l in local_lines if "marketing_sales" in l), None)
            print("  live marketing_sales line:", live_ms)
            print("  repo marketing_sales line:", local_ms)
finally:
    ftp.quit()
