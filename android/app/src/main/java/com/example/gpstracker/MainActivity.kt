package com.example.gpstracker

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.widget.Button
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.concurrent.Executor

class MainActivity : AppCompatActivity() {

    private lateinit var statusText: TextView
    private lateinit var startButton: Button
    private lateinit var stopButton: Button
    private lateinit var settingsButton: Button
    
    // GPS 狀態 Views
    private lateinit var gpsStatusCard: View
    private lateinit var signalIndicator: View
    private lateinit var signalStatusText: TextView
    private lateinit var coordinateText: TextView
    private lateinit var accuracyText: TextView
    private lateinit var uploadStatusText: TextView

    private lateinit var executor: Executor
    private lateinit var biometricPrompt: BiometricPrompt
    private lateinit var promptInfo: BiometricPrompt.PromptInfo

    private lateinit var masterKey: MasterKey
    private lateinit var securePrefs: SharedPreferences

    private var isAuthenticated = false
    
    // 廣播接收器
    private val locationReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            when (intent?.action) {
                LocationService.ACTION_LOCATION_UPDATE -> {
                    val lat = intent.getDoubleExtra(LocationService.EXTRA_LAT, 0.0)
                    val lng = intent.getDoubleExtra(LocationService.EXTRA_LNG, 0.0)
                    val accuracy = intent.getFloatExtra(LocationService.EXTRA_ACCURACY, 0f)
                    val gpsAvailable = intent.getBooleanExtra(LocationService.EXTRA_GPS_AVAILABLE, false)
                    val uploadSuccess = intent.getBooleanExtra(LocationService.EXTRA_UPLOAD_SUCCESS, false)
                    val errorMessage = intent.getStringExtra(LocationService.EXTRA_ERROR_MESSAGE) ?: ""
                    val nextUploadTime = intent.getLongExtra(LocationService.EXTRA_NEXT_UPLOAD_TIME, 0L)
                    
                    updateGpsStatus(lat, lng, accuracy, gpsAvailable, uploadSuccess, errorMessage, nextUploadTime)
                }
                LocationService.ACTION_STATUS_CHANGE -> {
                    val gpsAvailable = intent.getBooleanExtra(LocationService.EXTRA_GPS_AVAILABLE, false)
                    val uploadSuccess = intent.getBooleanExtra(LocationService.EXTRA_UPLOAD_SUCCESS, false)
                    val errorMessage = intent.getStringExtra(LocationService.EXTRA_ERROR_MESSAGE) ?: ""
                    val nextUploadTime = LocationService.nextUploadTime
                    
                    updateGpsSignalStatus(gpsAvailable, uploadSuccess, errorMessage, nextUploadTime)
                }
            }
        }
    }

    private val locationPermissionRequest = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        when {
            permissions.getOrDefault(android.Manifest.permission.ACCESS_FINE_LOCATION, false) -> {
                checkBackgroundLocationPermission()
            }
            permissions.getOrDefault(android.Manifest.permission.ACCESS_COARSE_LOCATION, false) -> {
                Toast.makeText(this, "僅有粗略位置權限，精準度可能較低", Toast.LENGTH_SHORT).show()
                checkBackgroundLocationPermission()
            }
            else -> {
                Toast.makeText(this, "需要位置權限才能追蹤", Toast.LENGTH_LONG).show()
            }
        }
    }

    private val backgroundLocationRequest = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { isGranted ->
        if (isGranted) {
            startLocationService()
        } else {
            Toast.makeText(this, "需要背景位置權限才能持續追蹤", Toast.LENGTH_LONG).show()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        
        setupSecurePrefs()
        
        // 檢查是否需要驗證
        if (needsAuthentication()) {
            setContentView(R.layout.activity_lock)
            setupBiometric()
            showAuthentication()
        } else {
            isAuthenticated = true
            showMainScreen()
        }
    }

    private fun setupSecurePrefs() {
        masterKey = MasterKey.Builder(this)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        securePrefs = EncryptedSharedPreferences.create(
            this,
            "secure_prefs",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    private fun needsAuthentication(): Boolean {
        return securePrefs.getString("password", null) != null
    }

    private fun setupBiometric() {
        executor = ContextCompat.getMainExecutor(this)
        
        biometricPrompt = BiometricPrompt(this, executor,
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                    super.onAuthenticationError(errorCode, errString)
                    // 指紋錯誤時顯示密碼輸入
                    showPasswordInput()
                }

                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    super.onAuthenticationSucceeded(result)
                    isAuthenticated = true
                    showMainScreen()
                }

                override fun onAuthenticationFailed() {
                    super.onAuthenticationFailed()
                    Toast.makeText(this@MainActivity, "驗證失敗", Toast.LENGTH_SHORT).show()
                }
            })

        promptInfo = BiometricPrompt.PromptInfo.Builder()
            .setTitle("GPS Tracker")
            .setSubtitle("驗證您的身份")
            .setNegativeButtonText("使用密碼")
            .build()
    }

    private fun showAuthentication() {
        val biometricEnabled = securePrefs.getBoolean("biometric_enabled", false)
        val biometricManager = BiometricManager.from(this)
        val canAuthenticate = biometricManager.canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)

        if (biometricEnabled && canAuthenticate == BiometricManager.BIOMETRIC_SUCCESS) {
            biometricPrompt.authenticate(promptInfo)
        } else {
            showPasswordInput()
        }
    }

    private fun showPasswordInput() {
        setContentView(R.layout.activity_lock)
        
        val passwordInput = findViewById<android.widget.EditText>(R.id.passwordInput)
        val unlockButton = findViewById<Button>(R.id.unlockButton)

        unlockButton.setOnClickListener {
            val inputPassword = passwordInput.text.toString()
            val storedPassword = securePrefs.getString("password", "")

            if (inputPassword == storedPassword) {
                isAuthenticated = true
                showMainScreen()
            } else {
                Toast.makeText(this, "密碼錯誤", Toast.LENGTH_SHORT).show()
                passwordInput.text.clear()
            }
        }
    }

    private fun showMainScreen() {
        setContentView(R.layout.activity_main)

        statusText = findViewById(R.id.statusText)
        startButton = findViewById(R.id.startButton)
        stopButton = findViewById(R.id.stopButton)
        settingsButton = findViewById(R.id.settingsButton)
        
        // GPS 狀態 Views
        gpsStatusCard = findViewById(R.id.gpsStatusCard)
        signalIndicator = findViewById(R.id.signalIndicator)
        signalStatusText = findViewById(R.id.signalStatusText)
        coordinateText = findViewById(R.id.coordinateText)
        accuracyText = findViewById(R.id.accuracyText)
        uploadStatusText = findViewById(R.id.uploadStatusText)

        updateStatus()
        
        // 如果服務正在運行，註冊廣播接收器
        if (LocationService.isRunning) {
            registerLocationReceiver()
            // 顯示目前狀態
            updateGpsStatus(
                LocationService.currentLat,
                LocationService.currentLng,
                LocationService.currentAccuracy,
                LocationService.isGpsAvailable,
                LocationService.lastUploadSuccess,
                LocationService.lastErrorMessage,
                LocationService.nextUploadTime
            )
        }

        startButton.setOnClickListener {
            requestLocationPermissions()
        }

        stopButton.setOnClickListener {
            stopLocationService()
        }

        settingsButton.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }
    }
    
    private fun registerLocationReceiver() {
        val filter = IntentFilter().apply {
            addAction(LocationService.ACTION_LOCATION_UPDATE)
            addAction(LocationService.ACTION_STATUS_CHANGE)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(locationReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            registerReceiver(locationReceiver, filter)
        }
        gpsStatusCard.visibility = View.VISIBLE
    }
    
    private fun unregisterLocationReceiver() {
        try {
            unregisterReceiver(locationReceiver)
        } catch (e: Exception) {
            // 接收器可能未註冊
        }
        gpsStatusCard.visibility = View.GONE
    }
    
    private fun updateGpsStatus(lat: Double, lng: Double, accuracy: Float, gpsAvailable: Boolean, uploadSuccess: Boolean, errorMessage: String, nextUploadTime: Long) {
        // 顯示 GPS 狀態卡片
        gpsStatusCard.visibility = View.VISIBLE
        
        // 更新座標
        coordinateText.text = String.format("座標: %.6f, %.6f", lat, lng)
        
        // 更新準確度
        accuracyText.text = String.format("準確度: %.1f 公尺", accuracy)
        
        // 更新訊號狀態
        updateGpsSignalStatus(gpsAvailable, uploadSuccess, errorMessage, nextUploadTime)
    }
    
    private fun updateGpsSignalStatus(gpsAvailable: Boolean, uploadSuccess: Boolean, errorMessage: String, nextUploadTime: Long) {
        if (gpsAvailable) {
            signalIndicator.setBackgroundResource(R.drawable.signal_indicator)
            signalIndicator.background.setTint(getColor(android.R.color.holo_green_dark))
            signalStatusText.text = "GPS 訊號正常"
            signalStatusText.setTextColor(getColor(android.R.color.holo_green_dark))
        } else {
            signalIndicator.setBackgroundResource(R.drawable.signal_indicator)
            signalIndicator.background.setTint(getColor(android.R.color.holo_red_dark))
            signalStatusText.text = "等待 GPS 訊號..."
            signalStatusText.setTextColor(getColor(android.R.color.holo_red_dark))
        }
        
        // 更新上傳狀態
        val nextTimeStr = if (nextUploadTime > 0) {
            val sdf = java.text.SimpleDateFormat("HH:mm:ss", java.util.Locale.getDefault())
            "下次: ${sdf.format(java.util.Date(nextUploadTime))}"
        } else {
            "下次: --"
        }
        
        if (uploadSuccess) {
            uploadStatusText.text = "上傳狀態: 成功 | $nextTimeStr"
            uploadStatusText.setTextColor(getColor(android.R.color.holo_green_dark))
        } else if (errorMessage.isNotEmpty()) {
            uploadStatusText.text = "上傳狀態: $errorMessage"
            uploadStatusText.setTextColor(getColor(android.R.color.holo_red_dark))
        } else {
            uploadStatusText.text = "上傳狀態: 等待中... | $nextTimeStr"
            uploadStatusText.setTextColor(getColor(android.R.color.darker_gray))
        }
    }

    private fun updateStatus() {
        val isRunning = LocationService.isRunning
        statusText.text = if (isRunning) {
            "狀態：正在追蹤 GPS"
        } else {
            "狀態：已停止"
        }
        startButton.isEnabled = !isRunning
        stopButton.isEnabled = isRunning
    }

    private fun requestLocationPermissions() {
        val permissions = mutableListOf(
            android.Manifest.permission.ACCESS_FINE_LOCATION,
            android.Manifest.permission.ACCESS_COARSE_LOCATION
        )

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.POST_NOTIFICATIONS) 
                != PackageManager.PERMISSION_GRANTED) {
                permissions.add(android.Manifest.permission.POST_NOTIFICATIONS)
            }
        }

        locationPermissionRequest.launch(permissions.toTypedArray())
    }

    private fun checkBackgroundLocationPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            if (ContextCompat.checkSelfPermission(this, android.Manifest.permission.ACCESS_BACKGROUND_LOCATION) 
                != PackageManager.PERMISSION_GRANTED) {
                Toast.makeText(this, "需要背景位置權限以持續追蹤", Toast.LENGTH_LONG).show()
                backgroundLocationRequest.launch(android.Manifest.permission.ACCESS_BACKGROUND_LOCATION)
                return
            }
        }
        startLocationService()
    }

    private val handler = Handler(Looper.getMainLooper())

    private fun startLocationService() {
        val intent = Intent(this, LocationService::class.java)
        
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent)
        } else {
            startService(intent)
        }
        
        Toast.makeText(this, "GPS 追蹤已啟動", Toast.LENGTH_SHORT).show()
        
        // 延遲更新狀態，確保 service 已啟動
        handler.postDelayed({
            updateStatus()
        }, 500)
    }

    private fun stopLocationService() {
        val intent = Intent(this, LocationService::class.java)
        stopService(intent)
        Toast.makeText(this, "GPS 追蹤已停止", Toast.LENGTH_SHORT).show()
        
        // 延遲更新狀態，確保 service 已停止
        handler.postDelayed({
            updateStatus()
        }, 500)
    }

    override fun onResume() {
        super.onResume()
        if (isAuthenticated) {
            updateStatus()
            if (LocationService.isRunning) {
                registerLocationReceiver()
            }
        }
    }
    
    override fun onPause() {
        super.onPause()
        unregisterLocationReceiver()
    }
}
