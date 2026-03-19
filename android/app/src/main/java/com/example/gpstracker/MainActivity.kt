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
import android.widget.Button
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONObject
import java.util.concurrent.Executor

class MainActivity : AppCompatActivity() {

    private lateinit var startButton: Button
    private lateinit var stopButton: Button
    private lateinit var settingsButton: Button
    private lateinit var emergencyButton: Button
    private lateinit var checkInInput: android.widget.EditText
    private lateinit var checkInConfirmButton: Button

    private lateinit var executor: Executor
    private lateinit var biometricPrompt: BiometricPrompt
    private lateinit var promptInfo: BiometricPrompt.PromptInfo

    private lateinit var masterKey: MasterKey
    private lateinit var securePrefs: SharedPreferences

    private var isAuthenticated = false

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

        startButton = findViewById(R.id.startButton)
        stopButton = findViewById(R.id.stopButton)
        settingsButton = findViewById(R.id.settingsButton)
        emergencyButton = findViewById(R.id.emergencyButton)
        checkInInput = findViewById(R.id.checkInInput)
        checkInConfirmButton = findViewById(R.id.checkInConfirmButton)

        updateStatus()

        // 確認按鈕：立即發送打卡
        checkInConfirmButton.setOnClickListener {
            val checkInText = checkInInput.text.toString().trim()
            if (checkInText.isEmpty()) {
                Toast.makeText(this, "請輸入打卡內容", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            
            // 隱藏鍵盤
            val imm = getSystemService(Context.INPUT_METHOD_SERVICE) as android.view.inputmethod.InputMethodManager
            imm.hideSoftInputFromWindow(checkInInput.windowToken, 0)
            checkInInput.clearFocus()
            
            // 取得目前位置並立即發送
            sendCheckIn(checkInText)
        }

        startButton.setOnClickListener {
            // 點擊開始追蹤時，也儲存打卡文字
            saveCheckInText()
            requestLocationPermissions()
        }

        stopButton.setOnClickListener {
            stopLocationService()
        }

        settingsButton.setOnClickListener {
            startActivity(Intent(this, SettingsActivity::class.java))
        }

        emergencyButton.setOnClickListener {
            saveCheckInText()
            sendEmergencyAlert()
        }
    }

    private fun saveCheckInText() {
        val checkInText = checkInInput.text.toString().trim()
        if (checkInText.isNotEmpty()) {
            val prefs = getSharedPreferences("gps_tracker_prefs", MODE_PRIVATE)
            prefs.edit().putString("check_in_text", checkInText).commit()
            // 清空輸入框
            checkInInput.text.clear()
        }
    }

    private fun sendCheckIn(checkInText: String) {
        // 取得目前位置
        val lat = LocationService.currentLat
        val lng = LocationService.currentLng
        val accuracy = LocationService.currentAccuracy
        val timestamp = java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss", java.util.Locale.getDefault()).apply {
            timeZone = java.util.TimeZone.getDefault()
        }.format(java.util.Date())

        // 檢查是否有位置資料
        if (lat == 0.0 && lng == 0.0) {
            Toast.makeText(this, "無法取得位置資訊，請先開始追蹤", Toast.LENGTH_LONG).show()
            return
        }

        // 讀取伺服器網址和deviceId和暱稱
        val prefs = getSharedPreferences("gps_tracker_prefs", MODE_PRIVATE)
        val serverUrl = prefs.getString("server_url", "") ?: ""
        val deviceId = prefs.getString("device_id", "") ?: ""
        val nickname = prefs.getString("nickname", "") ?: ""

        if (serverUrl.isEmpty()) {
            Toast.makeText(this, "請先設定伺服器網址", Toast.LENGTH_LONG).show()
            return
        }

        // 發送打卡
        checkInConfirmButton.isEnabled = false
        checkInConfirmButton.text = "發送中..."

        Thread {
            try {
                val url = java.net.URL(serverUrl)
                val connection = url.openConnection() as java.net.HttpURLConnection
                connection.requestMethod = "POST"
                connection.setRequestProperty("Content-Type", "application/json")
                connection.doOutput = true
                connection.connectTimeout = 30000
                connection.readTimeout = 30000

                val jsonBody = """
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

                val outputStream = connection.outputStream
                outputStream.write(jsonBody.toByteArray())
                outputStream.flush()
                outputStream.close()

                val responseCode = connection.responseCode
                
                runOnUiThread {
                    if (responseCode == 200 || responseCode == 201) {
                        Toast.makeText(this@MainActivity, "打卡成功", Toast.LENGTH_SHORT).show()
                        // 清空輸入框
                        checkInInput.text.clear()
                    } else {
                        Toast.makeText(this@MainActivity, "打卡失敗: HTTP $responseCode", Toast.LENGTH_LONG).show()
                    }
                    checkInConfirmButton.isEnabled = true
                    checkInConfirmButton.text = "確認"
                }
            } catch (e: Exception) {
                runOnUiThread {
                    Toast.makeText(this@MainActivity, "打卡錯誤: ${e.message}", Toast.LENGTH_LONG).show()
                    checkInConfirmButton.isEnabled = true
                    checkInConfirmButton.text = "確認"
                }
            }
        }.start()
    }

    private fun sendEmergencyAlert() {
        // 取得目前位置或上一筆位置
        val lat = LocationService.currentLat
        val lng = LocationService.currentLng
        val accuracy = LocationService.currentAccuracy
        val timestamp = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.getDefault()).format(java.util.Date())

        // 檢查是否有位置資料
        if (lat == 0.0 && lng == 0.0) {
            Toast.makeText(this, "無法取得位置資訊，無法發送緊急求救", Toast.LENGTH_LONG).show()
            return
        }

        // 讀取緊急聯絡人
        val prefs = getSharedPreferences("gps_tracker_prefs", MODE_PRIVATE)
        val emergencyContacts = prefs.getStringSet("emergency_contacts", emptySet()) ?: emptySet()

        if (emergencyContacts.isEmpty()) {
            Toast.makeText(this, "請先設定緊急聯絡人", Toast.LENGTH_LONG).show()
            return
        }

        // 發送緊急求救
        emergencyButton.isEnabled = false
        emergencyButton.text = "發送中..."

        Thread {
            try {
                // 取得伺服器網址
                val serverUrl = prefs.getString("server_url", "") ?: ""
                if (serverUrl.isEmpty()) {
                    runOnUiThread {
                        Toast.makeText(this@MainActivity, "請先設定伺服器網址", Toast.LENGTH_LONG).show()
                        emergencyButton.isEnabled = true
                        emergencyButton.text = "緊急求救"
                    }
                    return@Thread
                }

                // 發送到伺服器
                val url = java.net.URL(serverUrl.replace("receive_gps.php", "emergency_sos.php"))
                val connection = url.openConnection() as java.net.HttpURLConnection
                connection.requestMethod = "POST"
                connection.setRequestProperty("Content-Type", "application/json")
                connection.doOutput = true
                connection.connectTimeout = 30000
                connection.readTimeout = 30000

                // 取得打卡文字（同步讀取）
                val checkInText = prefs.getString("check_in_text", "") ?: ""
                
                // 建立 JSON 陣列
                val contactsArray = emergencyContacts.joinToString(",", "[", "]") { "\"$it\"" }
                
                // 建立 JSON，根據是否有打卡文字決定是否包含
                val jsonBody = if (checkInText.isNotEmpty()) {
                    """
                    {
                        "lat": $lat,
                        "lng": $lng,
                        "accuracy": $accuracy,
                        "timestamp": "$timestamp",
                        "contacts": $contactsArray,
                        "check_in": "$checkInText"
                    }
                    """.trimIndent()
                } else {
                    """
                    {
                        "lat": $lat,
                        "lng": $lng,
                        "accuracy": $accuracy,
                        "timestamp": "$timestamp",
                        "contacts": $contactsArray
                    }
                    """.trimIndent()
                }

                val outputStream = connection.outputStream
                outputStream.write(jsonBody.toByteArray())
                outputStream.flush()
                outputStream.close()

                val responseCode = connection.responseCode
                
                // 讀取伺服器回應
                val responseBody = connection.inputStream.bufferedReader().readText()
                
                // 解析 JSON 回應
                val jsonResponse = try {
                    JSONObject(responseBody)
                } catch (e: Exception) {
                    null
                }
                
                val success = jsonResponse?.optBoolean("success", false) ?: false
                val message = jsonResponse?.optString("message", "") ?: ""
                val failedContacts = jsonResponse?.optJSONArray("failed_contacts")
                
                runOnUiThread {
                    if (success || responseCode == 200 || responseCode == 201) {
                        val successCount = jsonResponse?.optInt("success_count", 0) ?: 0
                        Toast.makeText(this@MainActivity, "緊急求救已發送給 $successCount 位聯絡人", Toast.LENGTH_LONG).show()
                        
                        // 如果有失敗的聯絡人，顯示訊息
                        if (failedContacts != null && failedContacts.length() > 0) {
                            val failedList = (0 until failedContacts.length()).joinToString(", ") { failedContacts.getString(it) }
                            Toast.makeText(this@MainActivity, "以下聯絡人發送失敗: $failedList", Toast.LENGTH_LONG).show()
                        }
                    } else {
                        // 顯示伺服器回傳的錯誤訊息
                        val errorMsg = if (message.isNotEmpty()) message else "HTTP 錯誤: $responseCode"
                        Toast.makeText(this@MainActivity, "發送失敗: $errorMsg", Toast.LENGTH_LONG).show()
                    }
                    emergencyButton.isEnabled = true
                    emergencyButton.text = "緊急求救"
                }
            } catch (e: Exception) {
                runOnUiThread {
                    Toast.makeText(this@MainActivity, "發送錯誤: ${e.message}", Toast.LENGTH_LONG).show()
                    emergencyButton.isEnabled = true
                    emergencyButton.text = "緊急求救"
                }
            }
        }.start()
    }

    private fun updateStatus() {
        val isRunning = LocationService.isRunning
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
        }
    }
}
