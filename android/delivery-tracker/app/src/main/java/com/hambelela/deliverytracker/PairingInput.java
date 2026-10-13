package com.hambelela.deliverytracker;

import java.util.Locale;

/** Hex codes may acquire spaces/line breaks when copied; never change their value. */
final class PairingInput {
    static String normalize(String value){return value.replaceAll("[\\s\\u00a0\\u200b\\ufeff]", "").toLowerCase(Locale.ROOT);}
    static boolean validDevice(String value){return value.matches("[a-f0-9]{32}");}
    static boolean validCode(String value){return value.matches("[a-f0-9]{48}");}
}
