package com.hambelela.deliverytracker;

import javax.net.ssl.HttpsURLConnection;
import java.net.URL;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;
import java.nio.charset.StandardCharsets;
import org.json.JSONObject;

final class PortalClient {
    static final String PORTAL="https://portal.hambelelaorganic.com";
    static final class AuthorizationException extends Exception {}
    static JSONObject send(JSONObject request,String token) throws Exception {
        // Fixed destination prevents credential redirection to another server.
        HttpsURLConnection connection=(HttpsURLConnection)new URL(PORTAL+"/apps/delivery/tracking-device.php").openConnection();
        connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(15000);connection.setReadTimeout(15000);
        connection.setRequestMethod("POST");connection.setDoOutput(true);connection.setRequestProperty("Content-Type","application/json");connection.setRequestProperty("Accept","application/json");
        if(token!=null)connection.setRequestProperty("X-Tracking-Token",token);
        try {
            byte[] payload=request.toString().getBytes(StandardCharsets.UTF_8);connection.setFixedLengthStreamingMode(payload.length);
            try(java.io.OutputStream out=connection.getOutputStream()){out.write(payload);}
            int status=connection.getResponseCode();if(status==401||status==403)throw new AuthorizationException();
            if(status!=200)throw new java.io.IOException("Portal temporarily unavailable ("+status+")");
            if(!String.valueOf(connection.getContentType()).toLowerCase(java.util.Locale.ROOT).contains("application/json"))throw new java.io.IOException("Unexpected portal response");
            try(InputStream in=connection.getInputStream();ByteArrayOutputStream out=new ByteArrayOutputStream()){
                byte[] buffer=new byte[4096];int count;while((count=in.read(buffer))!=-1){if(out.size()+count>65536)throw new java.io.IOException("Response too large");out.write(buffer,0,count);}
                return new JSONObject(out.toString("UTF-8"));
            }
        } finally {connection.disconnect();}
    }
}
