package com.hambelela.deliverytracker;

import android.Manifest;
import android.app.Activity;
import android.content.*;
import android.content.pm.PackageManager;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.*;
import android.provider.Settings;
import android.text.InputType;
import android.view.*;
import android.widget.*;
import org.json.JSONObject;
import java.util.concurrent.Executors;

public final class SetupActivity extends Activity {
    private LinearLayout layout;private SecureStore store;private Typeface font;private TextView status;
    protected void onCreate(Bundle saved){super.onCreate(saved);getWindow().setFlags(WindowManager.LayoutParams.FLAG_SECURE,WindowManager.LayoutParams.FLAG_SECURE);store=new SecureStore(this);
        font=Typeface.createFromAsset(getAssets(),"jost-regular.ttf");ScrollView scroll=new ScrollView(this);layout=new LinearLayout(this);layout.setOrientation(LinearLayout.VERTICAL);layout.setPadding(24,32,24,32);layout.setBackgroundColor(0xffF8F7F2);scroll.addView(layout);setContentView(scroll);
        text("Hambelela Driver GPS",22);text("Company-phone tracking · v1.0.1",14);
        text("Your location is shared with the Hambelela Owner during configured work hours, including between deliveries. GPS stops outside those hours. A visible Android notification remains while the service is running. You can stop tracking at any time. Deliveries and HR still use your normal portal login.",14);
        status=text("Set up this company phone with the Owner.",14);
        EditText device=input("Device ID",false),code=input("One-time pairing code",true);
        CheckBox notice=new CheckBox(this);notice.setText("I understand the company work-hours tracking notice.");notice.setTypeface(font);layout.addView(notice);
        button("Pair company phone",v->{
            if(!notice.isChecked()){feedback("Tracking notice","Please read and confirm the tracking notice.");return;}
            String id=PairingInput.normalize(device.getText().toString()),pair=PairingInput.normalize(code.getText().toString());
            if(!PairingInput.validDevice(id)){device.setError("Enter the 32-character Device ID from the Owner portal.");feedback("Device ID needed","Copy the Device ID into the first field. The one-time pairing code belongs in the second field.");return;}
            if(!PairingInput.validCode(pair)){code.setError("Enter the 48-character one-time pairing code.");feedback("Pairing code needed","Copy the complete one-time pairing code into the second field. Do not enter a Google key or employee password.");return;}
            device.setError(null);code.setError(null);v.setEnabled(false);
            status.setText("Pairing with the portal…");
            android.app.AlertDialog progress=new android.app.AlertDialog.Builder(this).setTitle("Pairing company phone").setMessage("Connecting securely to the portal. Please wait…").setCancelable(false).create();progress.show();
            java.util.concurrent.ExecutorService pairing=Executors.newSingleThreadExecutor();
            pairing.execute(()->{try{
                JSONObject response=PortalClient.send(new JSONObject().put("action","pair").put("device_id",id).put("pairing_code",pair),null);
                store.credentials(new JSONObject().put("device_id",response.getString("device_id")).put("token",response.getString("token")));
                runOnUiThread(()->{progress.dismiss();if(isFinishing()||isDestroyed())return;code.setText("");feedback("Phone paired","Pairing is saved. Allow precise location and notifications, then tap Start work-hours tracking.");});
            }catch(Exception e){String message=pairingError(e);runOnUiThread(()->{progress.dismiss();if(!isFinishing()&&!isDestroyed())feedback("Pairing not completed",message);});
            }finally{pairing.shutdown();runOnUiThread(()->v.setEnabled(true));}});
        });
        button("1. Allow precise location",v->requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION},10));
        if(Build.VERSION.SDK_INT>=33)button("2. Allow tracking notification",v->requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS},11));
        button("Background location settings",v->{text("For restart after reboot, select Permissions → Location → Allow all the time if available. Do not grant unrelated permissions.",13);startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS,Uri.parse("package:"+getPackageName())));});
        button("Start work-hours tracking",v->{try{if(store.credentials()==null){status.setText("Pair this phone first.");return;}}catch(Exception e){status.setText("Credentials unavailable. Pair again.");return;}if(checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED){status.setText("Precise location permission is required.");return;}
            if(Build.VERSION.SDK_INT>=33&&checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)!=PackageManager.PERMISSION_GRANTED){status.setText("Enable notifications so tracking remains visible.");return;}
            store.running(true);startForegroundService(new Intent(this,TrackingService.class));status.setText("Started. Check the persistent notification and Owner dashboard. Physical phone tests are still required.");});
        button("Stop tracking",v->{store.running(false);stopService(new Intent(this,TrackingService.class));status.setText("Stopped. No new GPS locations are collected.");});
        button("Open my Delivery portal",v->startActivity(new Intent(Intent.ACTION_VIEW,Uri.parse(PortalClient.PORTAL+"/apps/delivery/index.php"))));
        text("No Google API key or employee password belongs in this app. Only the company device pairing code is needed. Offline points are encrypted, expire for upload after 24 hours and are removed when the queue is next processed. After one hour without server contact GPS stops until the connection returns.",12);
        button("GPS data notice",v->startActivity(new Intent(Intent.ACTION_VIEW,Uri.parse(PortalClient.PORTAL+"/apps/delivery/tracking-notice.php"))));
        try{if(store.credentials()!=null)status.setText("This phone is already paired. You can start work-hours tracking without entering another code.");}catch(Exception e){status.setText("Saved pairing cannot be read. Ask the Owner for a fresh pairing code.");}
    }
    private void feedback(String title,String message){status.setText(message);new android.app.AlertDialog.Builder(this).setTitle(title).setMessage(message).setPositiveButton("OK",null).show();}
    private static String pairingError(Exception error){
        if(error instanceof PortalClient.AuthorizationException)return "The portal rejected this pairing code. It may have expired, already been used or been replaced. Ask the Owner to generate a fresh code for this phone and enter both fields again.";
        if(error instanceof java.net.SocketTimeoutException)return "The portal did not respond in time. Check the phone's internet connection and try again. If the code was used during this attempt, ask the Owner for a fresh one.";
        if(error instanceof java.net.UnknownHostException)return "The phone cannot reach the portal. Check Wi-Fi or mobile data, then try again.";
        if(error instanceof javax.net.ssl.SSLException)return "A secure connection could not be established. Check the phone's date and time and internet connection. Do not bypass security warnings.";
        if(error instanceof PortalClient.PortalResponseException)return error.getMessage();
        return "Pairing could not be saved on this phone. Check the connection and ask the Owner for a fresh code. If it continues, report this message without sharing the code.";
    }
    private TextView text(String message,int size){TextView view=new TextView(this);view.setText(message);view.setTextSize(size);view.setTextColor(0xff26311F);view.setTypeface(font);view.setPadding(0,8,0,12);layout.addView(view);return view;}
    private EditText input(String hint,boolean secret){EditText view=new EditText(this);view.setHint(hint);view.setSingleLine(true);view.setTypeface(font);view.setTextSize(14);view.setInputType(InputType.TYPE_CLASS_TEXT|(secret?InputType.TYPE_TEXT_VARIATION_PASSWORD:InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS));layout.addView(view);return view;}
    private void button(String label,View.OnClickListener action){Button button=new Button(this);button.setText(label);button.setAllCaps(false);button.setTypeface(font);button.setTextSize(14);button.setTextColor(0xffFFFFFF);button.setBackgroundTintList(android.content.res.ColorStateList.valueOf(0xff59694A));button.setOnClickListener(action);LinearLayout.LayoutParams params=new LinearLayout.LayoutParams(-1,-2);params.setMargins(0,8,0,4);layout.addView(button,params);}
}
