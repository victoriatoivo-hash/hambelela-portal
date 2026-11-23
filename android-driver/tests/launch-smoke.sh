#!/usr/bin/env bash
set -euo pipefail
# Fresh AOSP emulator: no employee account, no customer data and no network.
adb shell svc wifi disable
adb shell svc data disable
# Reproduce the defect in the exact published package before testing the fix.
curl --fail --location --retry 2 -o /tmp/driver-original.apk https://github.com/victoriatoivo-hash/hambelela-portal/releases/download/driver-android-v1.0.0/Hambelela-Driver-1.0.0.apk
echo '911292a0c97ba24ca5f93284627c7bab22d590c8b9abfee088c056fff6aa40c1  /tmp/driver-original.apk' | sha256sum -c -
adb install /tmp/driver-original.apk
adb logcat -c
adb shell am start -W -n com.hambelelaorganic.driver/com.google.androidbrowserhelper.trusted.LauncherActivity
sleep 5
adb logcat -d -b crash > android-original-crash.log
grep -q 'ManageDataLauncherActivity' android-original-crash.log
grep -q 'IllegalArgumentException' android-original-crash.log
echo 'CONFIRMED: published 1.0.0 crashes toggling its undeclared activity.'
adb uninstall com.hambelelaorganic.driver
adb install -r android-driver/app/build/outputs/apk/debug/app-debug.apk
adb logcat -c
adb shell am start -W -n com.hambelelaorganic.driver/com.google.androidbrowserhelper.trusted.LauncherActivity
sleep 5
adb logcat -d -b crash > android-launch-crash.log
if grep -q 'com.hambelelaorganic.driver' android-launch-crash.log; then
    cat android-launch-crash.log
    exit 1
fi
# AOSP includes a basic browser: confirm the existing browser fallback opens.
adb shell uiautomator dump /sdcard/driver-launch.xml
adb pull /sdcard/driver-launch.xml driver-launch.xml
grep -q 'package="org.chromium.webview_shell"' driver-launch.xml
echo 'PASS: corrected APK launches the browser fallback without crashing.'
# Exercise a phone with its browser disabled as a separate startup case.
adb shell am force-stop com.hambelelaorganic.driver
adb shell pm disable-user --user 0 org.chromium.webview_shell
adb logcat -c
adb shell am start -W -n com.hambelelaorganic.driver/com.google.androidbrowserhelper.trusted.LauncherActivity
sleep 5
adb logcat -d -b crash > android-no-browser-crash.log
if grep -q 'com.hambelelaorganic.driver' android-no-browser-crash.log; then
    cat android-no-browser-crash.log
    exit 1
fi
adb shell uiautomator dump /sdcard/driver-no-browser.xml
adb pull /sdcard/driver-no-browser.xml driver-no-browser.xml
grep -q 'package="com.hambelelaorganic.driver"' driver-no-browser.xml
grep -qi 'browser' driver-no-browser.xml
echo 'PASS: no-browser startup stays open and explains the missing browser.'
