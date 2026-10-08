# Standalone MIV repair handoff — 8 October 2026

The app is still returning HTTP 500. No application files have been uploaded in this repair session. The live PHP version and authenticated functionality remain unverified because the dedicated FTP account cannot see the app and the hosting browser controller cannot start.

## Ready repair

Source remains `standalone/miv-bundle.json` at compatibility commit `cf3741b445a1ebab5e6433340bc113efe7b35565`. No policy, portal, account, database, private configuration or setup-key changes are included.

The revised delta deployer updates only:

- `bootstrap.php`
- `extract.php`
- `index.php`
- `api.php`
- `assets/js/miv-shipping.js`
- `assets/css/miv-shipping.css`

It requires the exact destination marker bytes, accepts only the committed predecessor/current bootstrap and extraction sources, verifies uploaded bytes, and restores every touched file on an upload failure. Unknown manual edits stop deployment for review. It never uploads setup keys, schema, login code or the private directory. Transport is certificate-verified FTPS only; the expired plain FTP exception has been removed.

## Connection and destination

`CHIN_FTP_SERVER` was reset to exactly `s11745.sgp1.stableserver.net`. Verified FTPS authentication succeeded in read-only inspection run 37760454347. The account reports `/`, with three root entries. It cannot retrieve `.chin-deploy-target`, `bootstrap.php` or `extract.php`, and cannot enter a named `chin.hambelelaorganic.com` child. No MIV index, login, API or assets are visible.

The physical jailed directory cannot be obtained from FTP PWD. In FastComet/cPanel, verify that `chin-deploy@hambelelaorganic.com` is jailed to `/home/hambele1/chin.hambelelaorganic.com`, not a new empty account directory. Correct that mapping if needed. Only after independently confirming the document root, place `.chin-deploy-target` there with exactly `chin.hambelelaorganic.com` (23 bytes, no newline). Do not plant the marker in the empty directory to bypass the check.

Then run the workflow's read-only `inspect` and `check` modes. Compare live code hashes; review any unknown manual changes before changing the approved predecessor hashes. Do not weaken certificate verification or use plain FTP.

## Rejected archive investigation

Exact rejected ZIP: `MIV-Repair-Update.zip`, 27,370 bytes.

SHA256: `a97f5fc0aa40c6406e0220c944347a5ab1353d56b10f3c92d666b9938db675cd`

Both downloaded copies have this hash. ZIP CRC checks pass. All 11 application members match the current bundle byte for byte; the remaining member is the destination marker. Windows Defender 4.18.26080.4 reported no threats for the unchanged archive with remediation disabled.

[Sanesecurity's Foxhole documentation](https://sanesecurity.com/foxhole-databases/) says its JavaScript archive signatures inspect filenames/extensions and block most JavaScript inside small ZIP/RAR archives, with medium false-positive risk. This supports a possible format-policy detection; it does not establish FastComet clearance for this archive.

FastComet must review `Sanesecurity.Foxhole.JS_Zip_11.UNOFFICIAL` against the exact archive hash and provide clearance before the payload is uploaded. Do not rename, obscure, repackage or upload individual files to circumvent the scanner. No support message was sent on the user's behalf.

After that review, record clearance for the six-file source manifest by setting repository variable `CHIN_SCANNER_CLEARANCE_SHA256` to:

`aa3c23c4e82c6f5eaab7a11e86d7d0b94119e7945c6bf7f4a066d4dd4695afee`

This is the deterministic digest of the six filename/content hashes, not an antivirus verdict. Set it only after actual hosting clearance; any payload change invalidates it.

## Verification

GitHub run 37760242025 passed PHP 7.4.33 lint/runtime tests for setup, login, independent sessions, CSRF, order creation, duplicate references, split and delivery-only payments, idempotent retries, overpayment protection and setup lock. Interface tests passed quote arithmetic, goods-price exclusion, shipping-only balances, saved quote behavior, delivery stages and payment controls.

Local Chromium geometry tests passed fixed viewport placement throughout scrolling and no horizontal overflow at 320x568, 375x667, 640x360, 768x1024 and 1280x800. These are source tests, not live verification.

After verified destination access and hosting clearance, merge/push the exact repair revision to main and dispatch `deploy-chin.yml` with mode `deploy`. First inspect the actual hosting PHP version using cPanel or existing hosting logs; do not change the portal or hosting PHP version. Then verify the live login using the existing account, delivery-only and normal quotes, order creation, payment behavior, and mobile totals bar. Preserve all real records and use separately identified test records only with an appropriate cleanup plan. Do not claim completion while HTTP 500 persists.
