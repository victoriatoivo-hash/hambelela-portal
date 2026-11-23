# Hambelela Driver — Android APK

Installable Android launcher for the **existing live Driver App**, not a redesign or a separate database. Uses Google's Trusted Web Activity implementation (`androidbrowserhelper` 2.7.4). The verified portal appears without a browser address bar, with the existing server-side login, roles, records and interface.

## Device requirements and behaviour

- Android 8.0 or later and an up-to-date TWA-capable browser (Chrome recommended).
- Internet is required. This package does not add offline writes, background GPS or push delivery while the app is closed.
- Opens `https://portal.hambelelaorganic.com/index.php`. Sign in with the driver's existing account; the server determines the permitted dashboard and assignments. No PIN or bypass is embedded.
- Camera/file picker, signatures, location consent, calling, directions and downloads use the existing web implementation and browser facilities. Permission prompts still require the phone user's consent.
- It may reuse the portal session already saved in Chrome. On a shared phone, sign out of any other employee's account before signing in as the driver.
- If the website association cannot be verified, Android safely shows browser controls rather than bypassing domain verification. External sites may show browser controls or open their respective apps.
- No intent filter claims every portal link; installing the driver app will not hijack the Owner's portal links.

## Build and signing

`gradle -p android-driver :app:assembleRelease :app:lintRelease` using JDK 17, Gradle 8.13, Android SDK 36 and AGP 8.13.2.

The dedicated build workflow produces an **unsigned** APK, lint report and Android's standard `apksigner.jar`. It uploads no private key or customer data. Signing is done locally with `sign-apk.ps1`; private material stays outside Git, with user-only file permissions and a Windows DPAPI-protected password. Preserve the same signing key for every update. Losing it requires uninstall/reinstall under a new signing identity.

The workflow publishes only `.well-known/assetlinks.json` to the existing hosting account after the build passes. This contains a **public certificate fingerprint**, not a secret. Existing differing associations cause a safe stop. No PHP, app screens, database, permissions or financial records are changed.

Official implementation: https://developer.android.com/develop/ui/views/layout/webapps/guide-trusted-web-activities-version2

## Required phone acceptance check

Before treating this as device-verified, install on the actual phone and check: launch without address bar; correct driver login; current assignments; Accept/Start; signature and photo upload; payment/Complete flow on legitimate work; map/location consent; call and external navigation; sign-out; app resume; offline message. Do not create financial records just to test. APK signature/build checks are not substitutes for this phone check.
