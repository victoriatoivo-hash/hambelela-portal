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
# Without a browser the supported-browser explanation must remain visible,
# rather than crashing or silently closing to the phone launcher.
adb shell uiautomator dump /sdcard/driver-launch.xml
adb pull /sdcard/driver-launch.xml driver-launch.xml
grep -q 'package="com.hambelelaorganic.driver"' driver-launch.xml
grep -qi 'browser' driver-launch.xml
echo 'PASS: actual Android startup stays open and explains the missing browser.'
