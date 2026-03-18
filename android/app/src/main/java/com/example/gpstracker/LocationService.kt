package com.example.gpstracker

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import com.google.android.gms.location.*
import kotlinx.coroutines.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.text.SimpleDateFormat
import java.util.*
import java.util.concurrent.TimeUnit

class LocationService : Service() {

    companion object {
        private const val TAG = "LocationService"
        private const val NOTIFICATION_ID = 1
        private const val ERROR_NOTIFICATION_ID = 2
        private const val CHANNEL_ID = "gps_tracker_channel"
        private const val ERROR_CHANNEL_ID = "gps_tracker_error_channel"

        // 廣播 Action
        const val ACTION_LOCATION_UPDATE = "com.example.gpstracker.LOCATION_UPDATE"
        const val ACTION_STATUS_CHANGE = "com.example.gpstracker.STATUS_CHANGE"

        // Intent Extra 鍵值
        const val EXTRA_LAT = "lat"
        const val EXTRA_LNG = "lng"
        const val EXTRA_ACCURACY = "accuracy"
        const val EXTRA_GPS_AVAILABLE = "gps_available"
        const val EXTRA_UPLOAD_SUCCESS = "upload_success"
        const val EXTRA_ERROR_MESSAGE = "error_message"

        var isRunning = false
            private set

        // 追蹤上傳狀態
        var lastUploadSuccess = false
            private set
        var lastErrorMessage = ""
            private set

        // 最後成功接收 GPS 的時間
        var lastGpsReceivedTime = 0L
            private set

        // 目前 GPS 狀態
        var isGpsAvailable = false
            private set

        // 目前位置
        var currentLat = 0.0
            private set
        var currentLng = 0.0
            private set
        var currentAccuracy = 0f
            private set
    }

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private val serviceScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    // 裝置識別碼
    private val deviceId: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        var id = prefs.getString("device_id", null)
        if (id == null) {
            id = UUID.randomUUID().toString()
            prefs.edit().putString("device_id", id).apply()
        }
        id
    }

    // 暱稱
    private val nickname: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.getString("nickname", "") ?: ""
    }

    // 從 SharedPreferences 讀取設定
    private val serverUrl: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.getString("server_url", "") ?: ""
    }

    private val uploadInterval: Long by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        val interval = prefs.getString("upload_interval", "60")?.toLongOrNull() ?: 60L
        interval * 1000 // 轉換為毫秒
    }

    // 是否使用網路定位
    private val useNetworkLocation: Boolean by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.getBoolean("network_location", false)
    }

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        setupLocationCallback()
        createNotificationChannel()
        createErrorNotificationChannel()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Log.d(TAG, "Service starting...")
        Log.d(TAG, "Server URL: $serverUrl")
        Log.d(TAG, "Upload interval: ${uploadInterval}ms")

        // 檢查伺服器網址
        if (serverUrl.isEmpty()) {
            showErrorNotification("請設定伺服器網址")
            Log.e(TAG, "Server URL not configured!")
        }

        startForeground(NOTIFICATION_ID, createNotification())
        isRunning = true

        startLocationUpdates()

        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        Log.d(TAG, "Service stopping...")
        stopLocationUpdates()
        isRunning = false
        lastGpsReceivedTime = 0
        isGpsAvailable = false

        // 清除所有通知
        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager.cancel(NOTIFICATION_ID)
        notificationManager.cancel(ERROR_NOTIFICATION_ID)

        serviceScope.cancel()
    }

    private fun setupLocationCallback() {
        locationCallback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                // 如果服務已停止，則不處理
                if (!isRunning) {
                    return
                }

                locationResult.lastLocation?.let { location ->
                    Log.d(TAG, "Location update: ${location.latitude}, ${location.longitude}")

                    // 更新位置變數
                    currentLat = location.latitude
                    currentLng = location.longitude
                    currentAccuracy = location.accuracy

                    // 更新最後收到 GPS 的時間
                    lastGpsReceivedTime = System.currentTimeMillis()
                    isGpsAvailable = true

                    // 廣播位置更新
                    broadcastLocationUpdate(location.latitude, location.longitude, location.accuracy, true)

                    sendLocationToServer(location.latitude, location.longitude, location.accuracy)
                }
            }

            override fun onLocationAvailability(locationAvailability: LocationAvailability) {
                // 如果服務已停止，則不處理
                if (!isRunning) {
                    return
                }
                
                // 只在狀態真正改變時廣播，避免頻繁廣播耗電
                val available = locationAvailability.isLocationAvailable
                if (available != isGpsAvailable) {
                    isGpsAvailable = available
                    broadcastStatusChange(available)
                }
            }
        }
    }

    // 廣播位置更新
    private fun broadcastLocationUpdate(lat: Double, lng: Double, accuracy: Float, gpsAvailable: Boolean) {
        val intent = Intent(ACTION_LOCATION_UPDATE).apply {
            putExtra(EXTRA_LAT, lat)
            putExtra(EXTRA_LNG, lng)
            putExtra(EXTRA_ACCURACY, accuracy)
            putExtra(EXTRA_GPS_AVAILABLE, gpsAvailable)
            putExtra(EXTRA_UPLOAD_SUCCESS, lastUploadSuccess)
            putExtra(EXTRA_ERROR_MESSAGE, lastErrorMessage)
        }
        sendBroadcast(intent)
    }

    // 廣播狀態變更
    private fun broadcastStatusChange(gpsAvailable: Boolean) {
        val intent = Intent(ACTION_STATUS_CHANGE).apply {
            putExtra(EXTRA_GPS_AVAILABLE, gpsAvailable)
            putExtra(EXTRA_UPLOAD_SUCCESS, lastUploadSuccess)
            putExtra(EXTRA_ERROR_MESSAGE, lastErrorMessage)
        }
        sendBroadcast(intent)
    }

    private fun startLocationUpdates() {
        if (ActivityCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
            != PackageManager.PERMISSION_GRANTED) {
            Log.e(TAG, "Location permission not granted")
            showErrorNotification("缺少定位權限")
            return
        }

        // 使用使用者設定的間隔
        val fastestInterval = uploadInterval / 2

        // 根據設定選擇定位模式
        val priority = if (useNetworkLocation) {
            Priority.PRIORITY_BALANCED_POWER_ACCURACY  // 混合 GPS + 網路定位
        } else {
            Priority.PRIORITY_HIGH_ACCURACY  // 僅 GPS
        }

        Log.d(TAG, "Using priority: $priority, network location: $useNetworkLocation")

        val locationRequest = LocationRequest.Builder(
            priority,
            uploadInterval
        ).apply {
            setMinUpdateIntervalMillis(fastestInterval)
            setMinUpdateDistanceMeters(10f)
            setWaitForAccurateLocation(false)
        }.build()

        fusedLocationClient.requestLocationUpdates(
            locationRequest,
            locationCallback,
            Looper.getMainLooper()
        )

        Log.d(TAG, "Location updates started with interval: ${uploadInterval}ms")
    }

    private fun stopLocationUpdates() {
        fusedLocationClient.removeLocationUpdates(locationCallback)
        Log.d(TAG, "Location updates stopped")
    }

    private fun sendLocationToServer(lat: Double, lng: Double, accuracy: Float) {
        // 檢查是否有設定伺服器網址
        if (serverUrl.isEmpty()) {
            Log.w(TAG, "Server URL not configured")
            lastUploadSuccess = false
            lastErrorMessage = "未設定伺服器網址"
            showErrorNotification("請設定伺服器網址")
            return
        }

        serviceScope.launch {
            try {
                val client = OkHttpClient.Builder()
                    .connectTimeout(30, TimeUnit.SECONDS)
                    .writeTimeout(30, TimeUnit.SECONDS)
                    .build()

                val timestamp = java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", Locale.getDefault()).apply {
                    timeZone = TimeZone.getDefault()
                }.format(Date())

                val jsonBody = """
                    {
                        "device_id": "$deviceId",
                        "nickname": "$nickname",
                        "lat": $lat,
                        "lng": $lng,
                        "accuracy": $accuracy,
                        "timestamp": "$timestamp"
                    }
                """.trimIndent()

                val requestBody = jsonBody.toRequestBody("application/json".toMediaType())

                val request = Request.Builder()
                    .url(serverUrl)
                    .post(requestBody)
                    .build()

                client.newCall(request).execute().use { response ->
                    if (response.isSuccessful) {
                        Log.d(TAG, "Location sent successfully")
                        lastUploadSuccess = true
                        lastErrorMessage = ""
                        clearErrorNotification()
                    } else {
                        Log.e(TAG, "Failed to send location: ${response.code}")
                        lastUploadSuccess = false
                        lastErrorMessage = "HTTP 錯誤: ${response.code}"
                        showErrorNotification("上傳失敗: ${response.code}")
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error sending location: ${e.message}")
                lastUploadSuccess = false
                lastErrorMessage = e.message ?: "網路錯誤"
                showErrorNotification("上傳錯誤: ${e.message}")
            }
        }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "GPS 追蹤服務",
                NotificationManager.IMPORTANCE_LOW
            ).apply {
                description = "用於背景 GPS 追蹤的通知"
                setShowBadge(false)
            }

            val notificationManager = getSystemService(NotificationManager::class.java)
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun createErrorNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                ERROR_CHANNEL_ID,
                "GPS 錯誤通知",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                description = "GPS 追蹤錯誤通知"
                setShowBadge(true)
            }

            val notificationManager = getSystemService(NotificationManager::class.java)
            notificationManager.createNotificationChannel(channel)
        }
    }

    private fun showErrorNotification(message: String) {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, ERROR_CHANNEL_ID)
            .setContentTitle("GPS 追蹤異常")
            .setContentText(message)
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager.notify(ERROR_NOTIFICATION_ID, notification)
    }

    private fun clearErrorNotification() {
        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager.cancel(ERROR_NOTIFICATION_ID)
    }

    private fun createNotification(): Notification {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        // 根據上傳狀態顯示不同文字
        val statusText = if (lastUploadSuccess) {
            "運作正常"
        } else if (lastErrorMessage.isNotEmpty()) {
            "異常: $lastErrorMessage"
        } else {
            "正在記錄您的位置"
        }

        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle("GPS 追蹤中")
            .setContentText(statusText)
            .setSmallIcon(android.R.drawable.ic_menu_mylocation)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
    }
}
