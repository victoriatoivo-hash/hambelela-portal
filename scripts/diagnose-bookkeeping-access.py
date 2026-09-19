"""Read-only diagnosis: why can't the Marketing & Sales Assistant see/use Bookkeeping live?

Never writes to the live server. FTP is used only to download (RETR) portal code files;
no configuration/secret files are read and the database is not touched. The repository
is public, so output is limited to code provenance and simulated permission results.
"""
import ftplib, io, os, subprocess, tempfile, urllib.error, urllib.request

SETTINGS_RELEASE = "7fa04c0848a428889c7d5f82f745789e07024ed6"
FILES = [
    "shared/employee-features.php", "shared/auth.php", "shared/workplace-access.php",
    "shared/sidebar.php", "shared/ess-sidebar.php", "shared/ess-navigation.php",
    "shared/ess-mobile-navigation.php", "shared/ess-dashboard.php", "shared/header.php",
    "index.php", "apps/operations/bookkeeping.php", "apps/operations/operations.php",
    "apps/marketing/index.php", "shared/marketing.php", "apps/operations/my-account.php",
    "profile.php", "login.php",
    # Orders actor attribution / Packed By audit (read only)
    "apps/operations/orders-board.php", "apps/operations/orders-board-action.php",
    "apps/operations/orders-board-data.php", "apps/operations/order-attribution-service.php",
    "assets/js/orders-board.js", "assets/js/orders-essentials.js",
    "assets/css/orders-board.css", "assets/css/orders-essentials.css",
    # Mobile usability audit (read only)
    "apps/operations/consignments.php", "apps/operations/packing-list-data.php", "assets/js/packing-list.js",
    "assets/js/packing-essentials.js", "assets/css/packing-board.css", "assets/css/packing-essentials.css",
    "apps/operations/checklists.php", "assets/css/task-essentials.css", "assets/css/task-correction.css",
    "assets/js/task-import.js", "assets/css/bookkeeping-essentials.css", "assets/js/bookkeeping-essentials.js",
    "assets/css/ess-dashboard.css", "assets/js/ess-dashboard.js", "assets/css/portal-column-resize.css",
    "assets/js/portal-column-resize.js", "assets/css/portal-view-bar.css",
]


def git(*args):
    return subprocess.run(["git", *args], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True).stdout.strip()


def blob_id(data):
    return subprocess.run(["git", "hash-object", "--stdin"], input=data, stdout=subprocess.PIPE, check=True).stdout.decode().strip()


def read(ftp, path):
    out = io.BytesIO()
    try:
        ftp.retrbinary("RETR " + path, out.write)
    except ftplib.error_perm as exc:
        if str(exc).startswith("550"):
            return None
        raise
    return out.getvalue()


def section(title):
    print("\n" + "=" * 70 + "\n" + title + "\n" + "=" * 70, flush=True)


live_dir = tempfile.mkdtemp(prefix="live-")
ftp = ftplib.FTP(os.environ["FTP_SERVER"], timeout=30)
ftp.login(os.environ["FTP_USERNAME"], os.environ["FTP_PASSWORD"])
try:
    section("1. LIVE FILE PROVENANCE (live blob vs repository history)")
    for path in FILES:
        data = read(ftp, path)
        if data is None:
            print(f"{path}: NOT PRESENT LIVE")
            continue
        target = os.path.join(live_dir, path)
        os.makedirs(os.path.dirname(target), exist_ok=True)
        with open(target, "wb") as fh:
            fh.write(data)
        live_blob = blob_id(data)
        rel_blob = git("rev-parse", "--verify", "-q", f"{SETTINGS_RELEASE}:{path}")
        main_blob = git("rev-parse", "--verify", "-q", f"origin/main:{path}")
        match = None
        for commit in git("log", "--all", "--format=%H", "--", path).splitlines():
            if git("rev-parse", "--verify", "-q", f"{commit}:{path}") == live_blob:
                match = commit
                break
        subject = git("log", "-1", "--format=%h %ad %s", "--date=short", match) if match else "NO MATCHING COMMIT"
        print(f"{path}\n    live==settings_release:{live_blob == rel_blob}  live==main:{live_blob == main_blob}\n    first matching commit: {subject}")
        if not match:
            base = SETTINGS_RELEASE if rel_blob else "origin/main"
            base_file = os.path.join(tempfile.mkdtemp(), "base.php")
            with open(base_file, "w") as fh:
                fh.write(git("show", f"{base}:{path}") + "\n")
            diff = subprocess.run(["diff", "-u", base_file, target], stdout=subprocess.PIPE, text=True).stdout
            print(f"    diff vs {base[:8]} (first 150 lines):")
            print("\n".join("      " + line for line in diff.splitlines()[:150]))
finally:
    ftp.quit()

section("2-8. PHP SIMULATION AGAINST THE LIVE CODE")
env = dict(os.environ, LIVE_DIR=live_dir)
code = subprocess.run(["php", "scripts/diagnose-bookkeeping-access.php"], env=env).returncode
print("php diagnosis exit code:", code, flush=True)

section("7b. UNAUTHENTICATED HTTP RESPONSE FOR THE BOOKKEEPING ROUTE")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect)
request = urllib.request.Request("https://portal.hambelelaorganic.com/apps/operations/bookkeeping.php",
                                 headers={"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) diagnostic"})
try:
    response = opener.open(request, timeout=20)
    print("status:", response.status, "location:", response.headers.get("Location"))
except urllib.error.HTTPError as exc:
    print("status:", exc.code, "location:", exc.headers.get("Location"))
except Exception as exc:  # network issues are reported, not fatal
    print("request failed:", type(exc).__name__)

subprocess.run(["rm", "-rf", live_dir])
