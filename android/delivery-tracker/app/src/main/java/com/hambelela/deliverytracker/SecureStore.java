package com.hambelela.deliverytracker;

import android.content.Context;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import java.security.KeyStore;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;
import org.json.JSONArray;
import org.json.JSONObject;

/** No location, token or pairing code is stored in plaintext or Android backups. */
final class SecureStore extends SQLiteOpenHelper {
    private final SharedPreferences preferences;
    SecureStore(Context context) { super(context,"tracking-queue.db",null,1); preferences=context.getSharedPreferences("tracking-private",Context.MODE_PRIVATE); }
    public void onCreate(SQLiteDatabase db) { db.execSQL("CREATE TABLE queue(id INTEGER PRIMARY KEY AUTOINCREMENT, recorded INTEGER NOT NULL, payload TEXT NOT NULL)"); }
    public void onUpgrade(SQLiteDatabase db,int oldVersion,int newVersion) { throw new IllegalStateException("Unsupported queue upgrade"); }
    private SecretKey key() throws Exception {
        KeyStore ks=KeyStore.getInstance("AndroidKeyStore");ks.load(null);
        if(!ks.containsAlias("hambelela-tracking")) {
            KeyGenerator generator=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");
            generator.init(new KeyGenParameterSpec.Builder("hambelela-tracking",KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build());generator.generateKey();
        }
        return (SecretKey)ks.getKey("hambelela-tracking",null);
    }
    private String encrypt(String value) throws Exception {
        Cipher c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.ENCRYPT_MODE,key());
        return Base64.encodeToString(c.getIV(),Base64.NO_WRAP)+":"+Base64.encodeToString(c.doFinal(value.getBytes(java.nio.charset.StandardCharsets.UTF_8)),Base64.NO_WRAP);
    }
    private String decrypt(String value) throws Exception {
        String[] parts=value.split(":",2);Cipher c=Cipher.getInstance("AES/GCM/NoPadding");c.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Base64.decode(parts[0],Base64.NO_WRAP)));
        return new String(c.doFinal(Base64.decode(parts[1],Base64.NO_WRAP)),java.nio.charset.StandardCharsets.UTF_8);
    }
    synchronized JSONObject credentials() throws Exception { String value=preferences.getString("credentials",null);return value==null?null:new JSONObject(decrypt(value)); }
    synchronized void credentials(JSONObject value) throws Exception { preferences.edit().putString("credentials",encrypt(value.toString())).commit(); }
    synchronized void clear() { preferences.edit().remove("credentials").putBoolean("running",false).commit();getWritableDatabase().delete("queue",null,null); }
    boolean running() { return preferences.getBoolean("running",false); }
    void running(boolean value) { preferences.edit().putBoolean("running",value).commit(); }
    synchronized void enqueue(JSONObject point) throws Exception {
        long now=System.currentTimeMillis()/1000;
        SQLiteDatabase db=getWritableDatabase();db.delete("queue","recorded<?",new String[]{Long.toString(now-86400)});
        android.content.ContentValues values=new android.content.ContentValues();values.put("recorded",point.getLong("recorded_at"));values.put("payload",encrypt(point.toString()));db.insertOrThrow("queue",null,values);
        db.execSQL("DELETE FROM queue WHERE id NOT IN (SELECT id FROM queue ORDER BY id DESC LIMIT 8640)");
    }
    synchronized JSONObject batch() throws Exception {
        JSONArray points=new JSONArray();long last=0;
        try(Cursor cursor=getReadableDatabase().rawQuery("SELECT id,payload FROM queue ORDER BY id LIMIT 100",null)){while(cursor.moveToNext()){last=cursor.getLong(0);points.put(new JSONObject(decrypt(cursor.getString(1))));}}
        return new JSONObject().put("points",points).put("last",last);
    }
    synchronized void acknowledged(long through) {getWritableDatabase().delete("queue","id<=?",new String[]{Long.toString(through)});}
}
