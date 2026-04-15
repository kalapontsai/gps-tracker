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
import org.json.JSONObject
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
        private const val CHECK_DELAY_MS = 3000L
        private const val MAX_CONSECUTIVE_FAILURES = 3

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
        const val EXTRA_NEXT_UPLOAD_TIME = "next_upload_time"

        var isRunning = false
            private set

        var lastUploadSuccess = false
            private set
        var lastErrorMessage = ""
            private set

        var lastGpsReceivedTime = 0L
            private set

        var isGpsAvailable = false
            private set

        var currentLat = 0.0
            private set
        var currentLng = 0.0
            private set
        var currentAccuracy = 0f
            private set
        
        var nextUploadTime = 0L
            private set
    }

    private lateinit var fusedLocationClient: FusedLocationProviderClient
    private lateinit var locationCallback: LocationCallback
    private val serviceScope = CoroutineScope(Dispatchers.IO + SupervisorJob())

    private var consecutiveFailures = 0

    private var lastSuccessfulLat = 0.0
    private var lastSuccessfulLng = 0.0
    private var lastSuccessfulAccuracy = 0f

    private var uploadJob: Job? = null

    private val deviceId: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        var id = prefs.getString("device_id", null)
        if (id == null) {
            id = UUID.randomUUID().toString()
            prefs.edit().putString("device_id", id).apply()
        }
        id
    }

    private val nickname: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.getString("nickname", "") ?: ""
    }

    private val serverUrl: String by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.getString("server_url", "") ?: ""
    }

    private val uploadInterval: Long by lazy {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        val interval = prefs.getString("upload_interval", "60")?.toLongOrNull() ?: 60L
        interval * 1000
    }

    override fun onCreate() {
        super.onCreate()
        fusedLocationClient = LocationServices.getFusedLocationProviderClient(this)
        setupLocationCallback()
        createNotificationChannel()
        createErrorNotificationChannel()
        loadLastSuccessfulCoordinates()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        Log.d(TAG, "Service starting...")
        Log.d(TAG, "Server URL: $serverUrl")
        Log.d(TAG, "Upload interval: ${uploadInterval}ms")
        Log.d(TAG, "Consecutive failures: $consecutiveFailures")
        Log.d(TAG, "Last successful coords: $lastSuccessfulLat, $lastSuccessfulLng")

        if (serverUrl.isEmpty()) {
            showErrorNotification("請設定伺服器網址")
            Log.e(TAG, "Server URL not configured!")
        }

        startForeground(NOTIFICATION_ID, createNotification())
        isRunning = true

        startLocationUpdates()
        startPeriodicUpload()

        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        super.onDestroy()
        Log.d(TAG, "Service stopping...")
        stopLocationUpdates()
        stopPeriodicUpload()
        isRunning = false
        lastGpsReceivedTime = 0
        isGpsAvailable = false

        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager.cancel(NOTIFICATION_ID)
        notificationManager.cancel(ERROR_NOTIFICATION_ID)

        serviceScope.cancel()
    }

    private fun loadLastSuccessfulCoordinates() {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        lastSuccessfulLat = prefs.getFloat("last_lat", 0.0f).toDouble()
        lastSuccessfulLng = prefs.getFloat("last_lng", 0.0f).toDouble()
        lastSuccessfulAccuracy = prefs.getFloat("last_accuracy", 0f)
        Log.d(TAG, "Loaded last successful coords: $lastSuccessfulLat, $lastSuccessfulLng")
    }

    private fun saveLastSuccessfulCoordinates(lat: Double, lng: Double, accuracy: Float) {
        lastSuccessfulLat = lat
        lastSuccessfulLng = lng
        lastSuccessfulAccuracy = accuracy
        
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        prefs.edit()
            .putFloat("last_lat", lat.toFloat())
            .putFloat("last_lng", lng.toFloat())
            .putFloat("last_accuracy", accuracy)
            .apply()
        Log.d(TAG, "Saved last successful coords: $lat, $lng")
    }

    private fun setupLocationCallback() {
        locationCallback = object : LocationCallback() {
            override fun onLocationResult(locationResult: LocationResult) {
                if (!isRunning) {
                    return
                }

                locationResult.lastLocation?.let { location ->
                    if (!location.isFromMockProvider) {
                        Log.d(TAG, "Location update: ${location.latitude}, ${location.longitude}, accuracy: ${location.accuracy}m")

                        currentLat = location.latitude
                        currentLng = location.longitude
                        currentAccuracy = location.accuracy

                        lastGpsReceivedTime = System.currentTimeMillis()
                        isGpsAvailable = true

                        broadcastLocationUpdate(location.latitude, location.longitude, location.accuracy, true)
                    }
                }
            }

            override fun onLocationAvailability(locationAvailability: LocationAvailability) {
                if (!isRunning) {
                    return
                }
                
                val available = locationAvailability.isLocationAvailable
                if (available != isGpsAvailable) {
                    isGpsAvailable = available
                    broadcastStatusChange(available)
                }
            }
        }
    }

    private fun broadcastLocationUpdate(lat: Double, lng: Double, accuracy: Float, gpsAvailable: Boolean) {
        nextUploadTime = lastGpsReceivedTime + uploadInterval
        
        val intent = Intent(ACTION_LOCATION_UPDATE).apply {
            putExtra(EXTRA_LAT, lat)
            putExtra(EXTRA_LNG, lng)
            putExtra(EXTRA_ACCURACY, accuracy)
            putExtra(EXTRA_GPS_AVAILABLE, gpsAvailable)
            putExtra(EXTRA_UPLOAD_SUCCESS, lastUploadSuccess)
            putExtra(EXTRA_ERROR_MESSAGE, lastErrorMessage)
            putExtra(EXTRA_NEXT_UPLOAD_TIME, nextUploadTime)
        }
        sendBroadcast(intent)
    }

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

        val fastestInterval = uploadInterval / 2
        val priority = Priority.PRIORITY_HIGH_ACCURACY

        Log.d(TAG, "Using priority: $priority (GPS only)")

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

    private fun startPeriodicUpload() {
        uploadJob?.cancel()
        uploadJob = serviceScope.launch {
            while (isActive) {
                delay(uploadInterval)
                if (isRunning) {
                    performUpload()
                }
            }
        }
        Log.d(TAG, "Periodic upload started with interval: ${uploadInterval}ms")
    }

    private fun stopPeriodicUpload() {
        uploadJob?.cancel()
        uploadJob = null
        Log.d(TAG, "Periodic upload stopped")
    }

    private fun performUpload() {
        if (serverUrl.isEmpty()) {
            Log.w(TAG, "Server URL not configured")
            lastUploadSuccess = false
            lastErrorMessage = "未設定伺服器網址"
            showErrorNotification("請設定伺服器網址")
            return
        }

        val lat: Double
        val lng: Double
        val accuracy: Float
        val checkInText: String

        if (isGpsAvailable && currentLat != 0.0 && currentLng != 0.0) {
            lat = currentLat
            lng = currentLng
            accuracy = currentAccuracy
            checkInText = getAndClearCheckInText()
            Log.d(TAG, "Using current GPS coords: $lat, $lng")
        } else if (lastSuccessfulLat != 0.0 && lastSuccessfulLng != 0.0) {
            lat = lastSuccessfulLat
            lng = lastSuccessfulLng
            accuracy = lastSuccessfulAccuracy
            checkInText = "*"
            Log.d(TAG, "GPS unavailable, using fallback coords: $lat, $lng with check_in=*")
        } else {
            Log.w(TAG, "No coordinates available to upload")
            lastUploadSuccess = false
            lastErrorMessage = "無可用座標"
            handleUploadFailure("無可用座標")
            return
        }

        sendLocationToServer(lat, lng, accuracy, checkInText)
    }

    private fun getAndClearCheckInText(): String {
        val prefs = getSharedPreferences("gps_tracker_prefs", Context.MODE_PRIVATE)
        val checkInText = prefs.getString("check_in_text", "") ?: ""
        if (checkInText.isNotEmpty()) {
            prefs.edit().remove("check_in_text").apply()
            Log.d(TAG, "Check-in text found and will be sent: $checkInText")
        }
        return checkInText
    }

    private fun sendLocationToServer(lat: Double, lng: Double, accuracy: Float, checkInText: String) {
        serviceScope.launch {
            try {
                val client = OkHttpClient.Builder()
                    .connectTimeout(30, TimeUnit.SECONDS)
                    .writeTimeout(30, TimeUnit.SECONDS)
                    .build()

                val timestamp = SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", Locale.getDefault()).apply {
                    timeZone = TimeZone.getDefault()
                }.format(Date())

                val jsonBody = if (checkInText.isNotEmpty() && checkInText != "*") {
                    """
                    {
                        "device_id": "$deviceId",
                        "nickname": "$nickname",
                        "lat": $lat,
                        "lng": $lng,
                        "accuracy": $accuracy,
                        "timestamp": "$timestamp",
                        "check_in": "$checkInText"
                    }
                    """.trimIndent()
                } else {
                    """
                    {
                        "device_id": "$deviceId",
                        "nickname": "$nickname",
                        "lat": $lat,
                        "lng": $lng,
                        "accuracy": $accuracy,
                        "timestamp": "$timestamp",
                        "check_in": "$checkInText"
                    }
                    """.trimIndent()
                }

                val requestBody = jsonBody.toRequestBody("application/json".toMediaType())

                val request = Request.Builder()
                    .url(serverUrl)
                    .post(requestBody)
                    .build()

                client.newCall(request).execute().use { response ->
                    if (response.isSuccessful) {
                        Log.d(TAG, "Location sent, checking verification after ${CHECK_DELAY_MS}ms")
                        
                        saveLastSuccessfulCoordinates(lat, lng, accuracy)
                        
                        delay(CHECK_DELAY_MS)
                        verifyLocationRecorded(lat, lng)
                    } else {
                        Log.e(TAG, "Failed to send location: ${response.code}")
                        handleUploadFailure("HTTP 錯誤: ${response.code}")
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error sending location: ${e.message}")
                handleUploadFailure(e.message ?: "網路錯誤")
            }
        }
    }

    private fun verifyLocationRecorded(lat: Double, lng: Double) {
        if (serverUrl.isEmpty()) return

        try {
            val client = OkHttpClient.Builder()
                .connectTimeout(10, TimeUnit.SECONDS)
                .readTimeout(10, TimeUnit.SECONDS)
                .build()

            val checkUrl = serverUrl.replace("receive_gps.php", "get_locations.php")
                .replace("update_db.php", "get_locations.php")
            
            val request = Request.Builder()
                .url("$checkUrl?device_id=$deviceId&limit=1")
                .get()
                .build()

            client.newCall(request).execute().use { response ->
                if (response.isSuccessful) {
                    val body = response.body?.string() ?: ""
                    val json = JSONObject(body)
                    val locations = json.optJSONArray("locations")
                    
                    if (locations != null && locations.length() > 0) {
                        val latest = locations.getJSONObject(0)
                        val latestLat = latest.optDouble("latitude", 0.0)
                        val latestLng = latest.optDouble("longitude", 0.0)
                        
                        val isMatch = kotlin.math.abs(latestLat - lat) < 0.0001 &&
                                     kotlin.math.abs(latestLng - lng) < 0.0001
                        
                        if (isMatch) {
                            Log.d(TAG, "Location verified successfully on server")
                            handleUploadSuccess()
                        } else {
                            Log.w(TAG, "Location mismatch: server lat=$latestLat, sent lat=$lat")
                            // 上傳成功但驗證位置不符，不算作錯誤，只記錄 log
                            // 可能 server 有其他資料，不影響主要功能
                            logVerificationIssue("位置不符，座標可能已更新")
                            handleUploadSuccess()
                        }
                    } else {
                        Log.w(TAG, "No location record found on server")
                        // 上傳成功但找不到紀錄，可能是时间差或 server 问题
                        // 不算作錯誤，只记录并继续
                        logVerificationIssue("找不到紀錄")
                        handleUploadSuccess()
                    }
                } else {
                    Log.e(TAG, "Verification failed: ${response.code}")
                    // 驗證 API 錯誤（HTTP 500 等），不代表上傳失敗
                    // 只記錄 log，不影響主要功能
                    logVerificationIssue("驗證 API 錯誤: HTTP ${response.code}")
                    handleUploadSuccess()
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Verification error: ${e.message}")
            // 驗證例外，不影響主要功能
            logVerificationIssue("驗證例外: ${e.message}")
            handleUploadSuccess()
        }
    }

    private fun handleUploadFailure(errorMsg: String) {
        consecutiveFailures++
        lastUploadSuccess = false
        lastErrorMessage = errorMsg
        
        Log.w(TAG, "Upload failure #$consecutiveFailures: $errorMsg")
        
        // 同時發送錯誤通知 Email
        sendErrorEmail(errorMsg)
        
        if (consecutiveFailures > MAX_CONSECUTIVE_FAILURES) {
            showRetryNotification()
            sendErrorEmail("GPS 追蹤持續失敗：已連續失敗 $consecutiveFailures 次，請重新啟動 App")
        } else {
            showErrorNotification("上傳失敗 ($consecutiveFailures/$MAX_CONSECUTIVE_FAILURES): $errorMsg")
        }
    }

    private fun handleUploadSuccess() {
        if (consecutiveFailures > 0) {
            Log.d(TAG, "Resetting consecutive failures from $consecutiveFailures to 0")
        }
        consecutiveFailures = 0
        lastUploadSuccess = true
        lastErrorMessage = ""
        clearErrorNotification()
    }

    private fun logVerificationIssue(msg: String) {
        Log.d(TAG, "Verification issue (non-critical): $msg")
        // 可選擇是否要通知，但目前不影響主要功能
    }

    // 發送錯誤通知 Email
    private fun sendErrorEmail(errorMsg: String) {
        if (serverUrl.isEmpty()) {
            Log.w(TAG, "Server URL not configured, cannot send error email")
            return
        }

        serviceScope.launch {
            try {
                val client = OkHttpClient.Builder()
                    .connectTimeout(30, TimeUnit.SECONDS)
                    .readTimeout(30, TimeUnit.SECONDS)
                    .build()

                val jsonBody = """
                    {
                        "device_id": "$deviceId",
                        "nickname": "$nickname",
                        "error_message": "$errorMsg",
                        "fail_count": $consecutiveFailures,
                        "lat": $lastSuccessfulLat,
                        "lng": $lastSuccessfulLng
                    }
                """.trimIndent()

                val requestBody = jsonBody.toRequestBody("application/json".toMediaType())

                // error_notification.php 與 serverUrl 同目錄
                val errorNotifyUrl = serverUrl.replace("receive_gps.php", "error_notification.php")
                    .replace("update_db.php", "error_notification.php")

                val request = Request.Builder()
                    .url(errorNotifyUrl)
                    .post(requestBody)
                    .build()

                client.newCall(request).execute().use { response ->
                    if (response.isSuccessful) {
                        Log.d(TAG, "Error notification email sent successfully")
                    } else {
                        Log.e(TAG, "Error notification email failed: ${response.code}")
                    }
                }
            } catch (e: Exception) {
                Log.e(TAG, "Error sending error email: ${e.message}")
            }
        }
    }

    private fun showRetryNotification() {
        val intent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this, 0, intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, ERROR_CHANNEL_ID)
            .setContentTitle("GPS 追蹤持續失敗")
            .setContentText("請重新啟動 App 以恢復正常運作")
            .setSmallIcon(android.R.drawable.ic_dialog_alert)
            .setContentIntent(pendingIntent)
            .setAutoCancel(true)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .build()

        val notificationManager = getSystemService(NotificationManager::class.java)
        notificationManager.notify(ERROR_NOTIFICATION_ID, notification)
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

        val statusText = if (lastUploadSuccess) {
            "運作正常"
        } else if (consecutiveFailures > MAX_CONSECUTIVE_FAILURES) {
            "持續失敗，請重啟 App"
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