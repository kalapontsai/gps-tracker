package com.example.gpstracker

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED ||
            intent.action == "android.intent.action.QUICKBOOT_POWERON") {
            
            // 檢查是否應該自動啟動（可以根據 SharedPreferences 設定）
            val prefs = context.getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
            val autoStart = prefs.getBoolean("auto_start", false)
            
            if (autoStart) {
                val serviceIntent = Intent(context, LocationService::class.java)
                
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    context.startForegroundService(serviceIntent)
                } else {
                    context.startService(serviceIntent)
                }
            }
        }
    }
}
