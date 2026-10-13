package com.hambelela.deliverytracker;
import org.junit.Test;
import static org.junit.Assert.*;
public class TrackingWindowTest {
 @Test public void activeWindow(){assertTrue(TrackingWindow.authorized(1000,10000,15000,1000,2000));}
 @Test public void exactEndStops(){assertFalse(TrackingWindow.authorized(1000,10000,20000,1000,1010));}
 @Test public void beforeStartStops(){assertFalse(TrackingWindow.authorized(1000,10000,10000,1001,2000));}
 @Test public void noConnectionNoGps(){assertFalse(TrackingWindow.authorized(0,10000,10000,0,10000));}
 @Test public void staleLeaseStops(){assertFalse(TrackingWindow.authorized(1000,10000,3610000,1000,10000));}
 @Test public void elapsedRollbackStops(){assertFalse(TrackingWindow.authorized(1000,10000,9000,1000,2000));}
 @Test public void ownerPauseStops(){assertFalse(TrackingWindow.authorized(1000,10000,11000,0,0));}
}
