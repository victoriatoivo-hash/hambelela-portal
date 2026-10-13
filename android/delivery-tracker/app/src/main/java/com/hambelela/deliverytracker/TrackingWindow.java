package com.hambelela.deliverytracker;
final class TrackingWindow {
    static boolean authorized(long serverTime,long synchronizedElapsed,long elapsedNow,long start,long end){
        long age=elapsedNow-synchronizedElapsed;
        if(serverTime<=0||age<0||age>=3600000)return false;
        long now=serverTime+age/1000;
        return now>=start&&now<end;
    }
}
