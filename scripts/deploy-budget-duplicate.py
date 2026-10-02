"""Reuse the verified delta uploader for only the supplier budget duplicate release."""
import importlib.util
import pathlib
import sys

spec = importlib.util.spec_from_file_location(
    "safe_delta", pathlib.Path(__file__).with_name("deploy-orders-inline-editing.py")
)
delta = importlib.util.module_from_spec(spec)
spec.loader.exec_module(delta)
delta.BASELINE = "e97628952221bf6185bafcdf1964ae8dac7cd4ef"
delta.FILES = (
    "apps/operations/budgeting-model.php",
    "apps/operations/budget-planning.php",
    "assets/css/budgeting.css",
)
delta.REPORT = "budget-duplicate-deployment-report.json"
delta.BACKUP = "budget-duplicate-deployment-backup.zip"

if __name__ == "__main__":
    if len(sys.argv) != 3 or sys.argv[1] not in ("preflight", "deploy"):
        raise SystemExit("Usage: deploy-budget-duplicate.py preflight|deploy APPROVED_SHA")
    delta.main(sys.argv[1], sys.argv[2])
