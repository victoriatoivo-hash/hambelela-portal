# Android startup correction — 1.0.1

## Finding

The published 1.0.0 APK used Android Browser Helper 2.7.4's LauncherActivity but omitted its ManageDataLauncherActivity declaration. During startup the library calls PackageManager.setComponentEnabledSetting for that component. Android throws IllegalArgumentException when the component does not exist.

The exact public 1.0.0 APK, verified against its published SHA-256, reproduced this crash on a fresh Android 10 / API 29 emulator. This is an application packaging defect, not evidence that the driver's phone or delivery records are faulty.

## Correction and scope

- Declare the library's site-settings activity and its fixed verified portal origin.
- Increment versionCode to 2 and versionName to 1.0.1; retain the existing package and signing identity for in-place updates.
- Preserve the main launcher, server authentication, permissions, browser engine, approved screens and all business functionality.
- Update only the two existing portal APK download links. No database migration or record changes.
- Add regression tests for the required component and upgrade version, plus an emulator comparison with the original APK and a separate disabled-browser case.

## Device limitation

The user's physical Hisense T2 Pro is not connected to the build environment. Emulator checks cannot certify that particular phone's browser/firmware. A final open-app check on the phone remains necessary after installing the update. Chrome or another supported browser and internet are still required.

Do not uninstall the existing app before updating. Do not disable Play Protect or share the driver's PIN.

## Verified release evidence

- Build source: `580fa8cf26c838dc20a03224012eb89286647ad9`.
- [Successful Android build and emulator run](https://github.com/victoriatoivo-hash/hambelela-portal/actions/runs/45132451923).
- Original signed 1.0.0 crash reproduced; corrected debug build launched the emulator browser fallback without crashing; disabling the browser showed the library's unavailable dialog without a crash.
- Release and debug compilation, release lint and APK alignment passed. Twelve package contract checks and five association-deployment safety checks passed.
- Eight rendered portal download checks, 29 existing profile-client checks and 22 owner read-only bridge checks passed; both changed PHP files lint under PHP 7.4.
- Release APK signed with the original certificate; Android apksigner verified v2 and v3 signatures.
- Signed APK SHA-256: `735fdfdd64b52d35073f87491d9cbc81cd0b96fb800b374b188d6b52c39ec142`.
- [Download 1.0.1](https://github.com/victoriatoivo-hash/hambelela-portal/releases/download/driver-android-v1.0.1/Hambelela-Driver-1.0.1.apk). Phone acceptance remains pending; no real employee login or delivery was used in emulator testing.
