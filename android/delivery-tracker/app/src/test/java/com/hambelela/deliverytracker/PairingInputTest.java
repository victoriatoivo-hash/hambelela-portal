package com.hambelela.deliverytracker;

import org.junit.Test;
import static org.junit.Assert.*;

public class PairingInputTest {
    @Test public void copiedWhitespaceAndCaseAreNormalized(){assertEquals("abcdef",PairingInput.normalize(" AB\nCD\u00a0EF\u200b "));}
    @Test public void correctLengthsRemainSeparate(){assertTrue(PairingInput.validDevice("a".repeat(32)));assertFalse(PairingInput.validCode("a".repeat(32)));assertTrue(PairingInput.validCode("b".repeat(48)));assertFalse(PairingInput.validDevice("b".repeat(48)));}
    @Test public void unrelatedCredentialsAndPartialCodesAreRejected(){assertFalse(PairingInput.validCode("AIza-not-a-pairing-code"));assertFalse(PairingInput.validDevice(""));assertFalse(PairingInput.validCode("c".repeat(47)));assertFalse(PairingInput.validCode("g".repeat(48)));}
}
