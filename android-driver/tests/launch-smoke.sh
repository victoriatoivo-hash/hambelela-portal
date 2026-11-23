#!/usr/bin/env bash
set -euo pipefail
# Fresh AOSP emulator: no employee account, no customer data and no network.
adb shell svc wifi disable
adb shell svc data disable
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
