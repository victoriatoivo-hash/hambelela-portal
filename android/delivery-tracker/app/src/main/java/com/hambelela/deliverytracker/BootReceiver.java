package com.hambelela.deliverytracker;
import android.Manifest;
import android.content.*;
import android.content.pm.PackageManager;
import android.os.Build;

public final class BootReceiver extends BroadcastReceiver {
    public void onReceive(Context context,Intent intent){
        if(!Intent.ACTION_BOOT_COMPLETED.equals(intent.getAction()))return;
        SecureStore store=new SecureStore(context);
        if(!store.running()||context.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED)return;
        if(Build.VERSION.SDK_INT>=29&&context.checkSelfPermission(Manifest.permission.ACCESS_BACKGROUND_LOCATION)!=PackageManager.PERMISSION_GRANTED)return;
        try{context.startForegroundService(new Intent(context,TrackingService.class));}catch(RuntimeException ignored){/* Owner dashboard will show stale/offline. Driver must reopen after OS restrictions. */}
    }
}
