package com.hambelela.deliverytracker;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.location.*;
import android.os.*;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.atomic.AtomicBoolean;
import org.json.JSONObject;

/** Visible, user-started foreground GPS. No accessibility, covert tracking or OS bypass. */
public final class TrackingService extends Service implements LocationListener {
    static final String CHANNEL="driver-tracking";
    private final Handler handler=new Handler(Looper.getMainLooper());
    private final ExecutorService network=Executors.newSingleThreadExecutor();
    private final AtomicBoolean sending=new AtomicBoolean();
    private SecureStore store;private LocationManager locations;private boolean listening=false;
    private long serverTime=0,syncedElapsed=0,windowStart=0,windowEnd=0,lastRecorded=0;
    private int movingSeconds=15,stationarySeconds=60;private Location previous;
    private boolean stopped=false;
    public IBinder onBind(Intent intent){return null;}
    public void onCreate(){super.onCreate();store=new SecureStore(this);locations=(LocationManager)getSystemService(LOCATION_SERVICE);
        ((NotificationManager)getSystemService(NOTIFICATION_SERVICE)).createNotificationChannel(new NotificationChannel(CHANNEL,"Company Driver GPS",NotificationManager.IMPORTANCE_LOW));}
    private Notification notification(String message){
        PendingIntent open=PendingIntent.getActivity(this,1,new Intent(this,SetupActivity.class),PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        PendingIntent stop=PendingIntent.getService(this,2,new Intent(this,TrackingService.class).setAction("STOP"),PendingIntent.FLAG_IMMUTABLE|PendingIntent.FLAG_UPDATE_CURRENT);
        return new Notification.Builder(this,CHANNEL).setSmallIcon(android.R.drawable.ic_menu_mylocation).setContentTitle("Hambelela Driver GPS").setContentText(message).setContentIntent(open).setOngoing(true).setOnlyAlertOnce(true).addAction(new Notification.Action.Builder(null,"Stop tracking",stop).build()).build();
    }
    public int onStartCommand(Intent intent,int flags,int startId){
        if(intent!=null&&"STOP".equals(intent.getAction())){store.running(false);stopSelf();return START_NOT_STICKY;}
        if(!store.running()||checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED){stopSelf();return START_NOT_STICKY;}
        try{startForeground(100,notification("Connecting · no location collected yet"));}
        catch(SecurityException e){store.running(false);stopSelf();return START_NOT_STICKY;}
        handler.removeCallbacks(tick);handler.post(tick);return START_STICKY;
    }
    private long now(){return serverTime+(SystemClock.elapsedRealtime()-syncedElapsed)/1000;}
    private boolean authorized(){return serverTime>0&&SystemClock.elapsedRealtime()>=syncedElapsed&&SystemClock.elapsedRealtime()-syncedElapsed<3600000&&now()>=windowStart&&now()<windowEnd;}
    private boolean gpsAvailable(){return checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)==PackageManager.PERMISSION_GRANTED&&locations.isProviderEnabled(LocationManager.GPS_PROVIDER);}
    private void updateListening(){
        boolean allowed=authorized()&&gpsAvailable();
        if(allowed&&!listening){
            try{locations.requestLocationUpdates(LocationManager.GPS_PROVIDER,movingSeconds*1000L,0,this,Looper.getMainLooper());listening=true;}
            catch(SecurityException|IllegalArgumentException e){listening=false;}
        }else if(!allowed&&listening){locations.removeUpdates(this);listening=false;previous=null;}
        String label=authorized()?(listening?"Work-hours tracking active · visible to Owner":"GPS unavailable · enable precise location"):(serverTime==0?"Connecting · no location collected":"Off duty or connection expired · GPS stopped");
        ((NotificationManager)getSystemService(NOTIFICATION_SERVICE)).notify(100,notification(label));
    }
    private final Runnable tick=new Runnable(){public void run(){if(stopped)return;updateListening();send();handler.postDelayed(this,15000);}};
    private void send(){
        if(!sending.compareAndSet(false,true))return;
        network.execute(()->{
            try{
                JSONObject credentials=store.credentials();if(credentials==null){handler.post(()->{store.running(false);stopSelf();});return;}
                JSONObject batch=store.batch();JSONObject request=new JSONObject().put("action","ingest").put("device_id",credentials.getString("device_id")).put("points",batch.getJSONArray("points")).put("gps_available",gpsAvailable()).put("app_version","1.0.0");
                Intent battery=registerReceiver(null,new IntentFilter(Intent.ACTION_BATTERY_CHANGED));if(battery!=null){int scale=battery.getIntExtra(BatteryManager.EXTRA_SCALE,100);if(scale>0)request.put("battery",battery.getIntExtra(BatteryManager.EXTRA_LEVEL,0)*100/scale);}
                JSONObject response=PortalClient.send(request,credentials.getString("token"));store.acknowledged(batch.getLong("last"));
                long receivedElapsed=SystemClock.elapsedRealtime();
                handler.post(()->{
                    if(stopped)return;
                    serverTime=response.optLong("server_time",0);syncedElapsed=receivedElapsed;JSONObject window=response.optJSONObject("authorized_window");windowStart=window==null?0:window.optLong("start",0);windowEnd=window==null?0:window.optLong("end",0);
                    JSONObject settings=response.optJSONObject("settings");if(settings!=null){int next=Math.max(10,Math.min(60,settings.optInt("moving_seconds",15)));stationarySeconds=Math.max(30,Math.min(300,settings.optInt("stationary_seconds",60)));if(next!=movingSeconds&&listening){locations.removeUpdates(this);listening=false;}movingSeconds=next;}updateListening();
                });
            }catch(PortalClient.AuthorizationException e){handler.post(()->{store.clear();stopSelf();});}
            catch(Exception e){/* Retain encrypted queue; freshness is computed on server, never invented. */}
            finally{sending.set(false);}
        });
    }
    public void onLocationChanged(Location location){
        if(!authorized()||location.isFromMockProvider()||!location.hasAccuracy()||location.getAccuracy()>100)return;
        long age=(SystemClock.elapsedRealtimeNanos()-location.getElapsedRealtimeNanos())/1000000000L;
        if(age<0||age>30)return;
        long recorded=now()-age;if(recorded<windowStart||recorded>=windowEnd)return;
        int interval=previous!=null&&previous.distanceTo(location)<Math.max(15,location.getAccuracy())?stationarySeconds:movingSeconds;
        if(recorded-lastRecorded<interval)return;
        try{store.enqueue(new JSONObject().put("event_id",UUID.randomUUID().toString()).put("recorded_at",recorded).put("latitude",location.getLatitude()).put("longitude",location.getLongitude()).put("accuracy",location.getAccuracy()));lastRecorded=recorded;previous=new Location(location);send();}
        catch(Exception e){((NotificationManager)getSystemService(NOTIFICATION_SERVICE)).notify(100,notification("Storage unavailable · open GPS setup"));}
    }
    public void onProviderEnabled(String provider){updateListening();}
    public void onProviderDisabled(String provider){updateListening();}
    public void onStatusChanged(String provider,int status,Bundle extras){}
    public void onDestroy(){stopped=true;handler.removeCallbacksAndMessages(null);if(locations!=null)locations.removeUpdates(this);network.shutdownNow();stopForeground(STOP_FOREGROUND_REMOVE);super.onDestroy();}
}
