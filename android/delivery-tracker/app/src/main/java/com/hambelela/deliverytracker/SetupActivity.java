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
        text("Hambelela Driver GPS",22);text("Company-phone tracking",14);
        text("Your location is shared with the Hambelela Owner during configured work hours, including between deliveries. GPS stops outside those hours. A visible Android notification remains while the service is running. You can stop tracking at any time. Deliveries and HR still use your normal portal login.",14);
        status=text("Set up this company phone with the Owner.",14);
        EditText device=input("Device ID",false),code=input("One-time pairing code",true);
        CheckBox notice=new CheckBox(this);notice.setText("I understand the company work-hours tracking notice.");notice.setTypeface(font);layout.addView(notice);
        button("Pair company phone",v->{if(!notice.isChecked()){status.setText("Please read and confirm the tracking notice.");return;}String id=device.getText().toString().trim(),pair=code.getText().toString().trim();if(!id.matches("[a-f0-9]{32}")||!pair.matches("[a-f0-9]{48}")){status.setText("Enter the device ID and unexpired code from Owner → Driver Tracking.");return;}
            v.setEnabled(false);Executors.newSingleThreadExecutor().execute(()->{try{JSONObject response=PortalClient.send(new JSONObject().put("action","pair").put("device_id",id).put("pairing_code",pair),null);store.credentials(new JSONObject().put("device_id",response.getString("device_id")).put("token",response.getString("token")));runOnUiThread(()->{code.setText("");status.setText("Paired. Grant precise location and start tracking.");});}catch(Exception e){runOnUiThread(()->status.setText("Pairing failed. Check connection and ask Owner for a fresh code."));}finally{runOnUiThread(()->v.setEnabled(true));}});});
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
    }
    private TextView text(String message,int size){TextView view=new TextView(this);view.setText(message);view.setTextSize(size);view.setTextColor(0xff26311F);view.setTypeface(font);view.setPadding(0,8,0,12);layout.addView(view);return view;}
    private EditText input(String hint,boolean secret){EditText view=new EditText(this);view.setHint(hint);view.setSingleLine(true);view.setTypeface(font);view.setTextSize(14);view.setInputType(InputType.TYPE_CLASS_TEXT|(secret?InputType.TYPE_TEXT_VARIATION_PASSWORD:InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS));layout.addView(view);return view;}
    private void button(String label,View.OnClickListener action){Button button=new Button(this);button.setText(label);button.setAllCaps(false);button.setTypeface(font);button.setTextSize(14);button.setTextColor(0xffFFFFFF);button.setBackgroundTintList(android.content.res.ColorStateList.valueOf(0xff59694A));button.setOnClickListener(action);LinearLayout.LayoutParams params=new LinearLayout.LayoutParams(-1,-2);params.setMargins(0,8,0,4);layout.addView(button,params);}
}
