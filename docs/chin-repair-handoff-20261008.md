# Standalone MIV repair — live deployment, 8 October 2026

Deployment completed successfully through the dedicated delta workflow in Actions run 37782006877, using merged revision `1999f651519562459b594fcb20f45326dd4abcf0` (PR #83). The upload changed three files: `api.php`, `bootstrap.php`, and `extract.php`. All six allowlisted deployment files were checked against the reviewed bundle. No setup keys, private configuration, database files, login files or portal files were uploaded.

The live main URL now redirects to the login page and returns HTTP 200. Both unauthenticated orders and extraction API requests return HTTP 401. The PHP 500 / undefined str_ends_with error is repaired. Authenticated live quotes, order creation, payments and mobile totals-bar checks remain pending the user's existing MIV sign-in; these functions passed the PHP 7.4 and interface tests, but those source tests are not a claim of authenticated live verification. No live test orders or payments were created.

## Runtime and destination

cPanel's PHP Selector confirms that chin.hambelelaorganic.com uses PHP 7.4 (account default), with pdo_sqlite enabled. No hosting PHP version or extension settings were changed.

The Domains interface confirms `/home/hambele1/chin.hambelelaorganic.com` as the site's document root. The existing FTP account originally pointed to `/home/hambele1/hambelelaorganic.com/chin.hambelelaorganic.com`. Following the user's action-time approval, cPanel's Ftp set_homedir operation corrected the account to the actual document root. The account and password were retained. The browser could not display the raw API response; the resulting path was independently verified in FTP Accounts, and Actions check run 37780023231 verified a fresh FTPS login, the exact marker and all repair files.

The marker already existed in the real document root with exactly `chin.hambelelaorganic.com` (25 bytes, no newline). It was not rewritten. The deploy script never changes directories or removes the destination checks. Transport uses certificate-verified FTPS; the expired plain-FTP exception was not extended. `CHIN_FTP_SERVER` is exactly `s11745.sgp1.stableserver.net`.

## Exact repair and preservation

Source remains `standalone/miv-bundle.json` at compatibility commit `cf3741b445a1ebab5e6433340bc113efe7b35565`. Compatibility changes replace str_ends_with, never return types and null-coalescing assignment syntax. The six-file allowlist is:

- `bootstrap.php`
- `extract.php`
- `index.php`
- `api.php`
- `assets/js/miv-shipping.js`
- `assets/css/miv-shipping.css`

Only changed bytes are uploaded. The script accepts only reviewed predecessor/current bootstrap and extraction sources, checks uploaded bytes and rolls back every touched file on an upload failure. No rollback was necessary. The standalone login, session and private SQLite database remain independent of the portal.

## Scanner evidence and clearance

Original rejected ZIP: `MIV-Repair-Update.zip`, 27,370 bytes.
SHA256: `a97f5fc0aa40c6406e0220c944347a5ab1353d56b10f3c92d666b9938db675cd`
Detection: `Sanesecurity.Foxhole.JS_Zip_11.UNOFFICIAL`

Both original downloaded copies had that hash. ZIP CRC checks passed. All 11 application members matched the reviewed bundle byte for byte; the remaining marker member had a trailing LF. Windows Defender 4.18.26080.4 reported no threats when scanning the unchanged archive with remediation disabled. The original downloaded archives were subsequently no longer present in Downloads; no archive was rebuilt or disguised.

[Sanesecurity's Foxhole documentation](https://sanesecurity.com/foxhole-databases/) describes filename/extension-based JavaScript archive rules with medium false-positive risk. This explained a possible archive-policy detection; it was not treated as hosting clearance.

The user then explicitly confirmed that FastComet clearance was already obtained. No support message was sent on the user's behalf. Source continuity checks verified that all six current deployment files were unchanged from the previously ZIP-verified compatibility revision. The exact six-file manifest digest, derived from filename/content hashes rather than ZIP-container bytes, is:

`aa3c23c4e82c6f5eaab7a11e86d7d0b94119e7945c6bf7f4a066d4dd4695afee`

After those checks, the repository variable `CHIN_SCANNER_CLEARANCE_SHA256` was set to that digest. Any change to the allowlisted payload invalidates the gate. This variable records the user's confirmed clearance; it is not a new antivirus scan or a general scanner bypass.

## Verification

Actions run 37782006877 passed PHP 7.4.33 lint/runtime tests for setup, login, independent sessions, CSRF, order creation, duplicate references, split and delivery-only payments, idempotent retries, overpayment protection and setup lock. Interface tests passed quote arithmetic, goods-price exclusion, shipping-only balances, saved quote behavior, delivery stages and payment controls. Nine deployment safety tests passed.

Chromium geometry tests passed fixed totals-bar placement while scrolling and no horizontal overflow at 320x568, 375x667, 640x360, 768x1024 and 1280x800. These are source tests. The live login page is visibly restored and has been left open for the user's existing sign-in. Positive authenticated live tests remain outstanding until that session is available.
