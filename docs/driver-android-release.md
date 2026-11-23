# Hambelela Driver Android 1.0.0 — 23 November 2026

The existing live Driver App is packaged as an installable Android APK. No screen redesign, duplicate database, embedded account credentials or change to portal business services is included.

## Release identity

- Package: `com.hambelelaorganic.driver`
- Version: `1.0.0` (code 1)
- Minimum: Android 8 / API 26. Target: Android 16 / API 36.
- Build source: `3340850d7d2542e477bd88d17cb557644ced74e9`
- Successful build and public ownership-association deployment: https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/45127529944
- APK: `Hambelela-Driver-1.0.0.apk`, 3,773,298 bytes.
- APK SHA-256: `911292a0c97ba24ca5f93284627c7bab22d590c8b9abfee088c056fff6aa40c1`
- Public signer SHA-256: `08:52:E6:54:22:28:C7:03:D9:6D:6E:E2:31:03:AA:69:E0:F3:76:39:61:F0:4A:C9:09:35:8A:94:C4:41:F6:EF`.

## Completed checks

- 10 package contract checks and 5 synthetic deployment-safety checks passed.
- Android release compilation, APK alignment and lint completed with **zero errors**.
- Local Android `apksigner` verification passed, with v2 and v3 signatures and the expected certificate.
- Live HTTPS association returns HTTP 200 `application/json` with the expected public certificate.
- Google's Digital Asset Links service returned `linked: true` for the exact portal, package and signing certificate.
- No existing `apps`, `assets`, `shared` or `database` files were changed.
- Private signing material is outside Git, with restricted local access. Its password is encrypted for the actual workspace owner's Windows account; no key/password was uploaded to GitHub.

The first ownership-file verification returned HTTP 403 and the new file was rolled back. Using the established named release verifier, the subsequent HTTPS verification passed, followed by the independent local and Google checks. No portal security rules were relaxed.

## Limitations / phone acceptance

The build was not installed or exercised on Moses's physical phone: no phone was connected. This release is marked **pre-release pending the real-phone acceptance check**, not because the existing portal is replaced or staged.

Use an up-to-date Chrome/TWA-capable browser. App login may share the portal session with Chrome, so check the signed-in identity on a shared phone. Internet remains required. This package does not add offline financial writes, background GPS or push notifications while closed.

Four non-blocking lint warnings remain: targeting API 36 rather than the newest SDK, the legacy full-backup-content recommendation (backup is already disabled), a redundant resource-version suffix, and no themed monochrome launcher icon. No lint errors were suppressed.

Before daily use, verify the correct driver dashboard, no address bar, assignments and automatic updates, navigation, camera/photo and signature, location/calling, and normal legitimate delivery completion on the phone. Do not generate false delivery/payment records for testing.

Installation guide: [driver-android-installation.md](driver-android-installation.md).
