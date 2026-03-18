package com.example.gpstracker

import android.content.Intent
import android.content.SharedPreferences
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.text.InputType
import android.widget.*
import androidx.appcompat.app.AppCompatActivity
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.concurrent.Executor

class SettingsActivity : AppCompatActivity() {

    private lateinit var serverUrlInput: EditText
    private lateinit var intervalInput: EditText
    private lateinit var nicknameInput: EditText
    private lateinit var deviceIdText: TextView
    private lateinit var passwordInput: EditText
    private lateinit var confirmPasswordInput: EditText
    private lateinit var biometricSwitch: Switch
    private lateinit var networkLocationSwitch: Switch
    private lateinit var autoStartSwitch: Switch
    private lateinit var testButton: Button
    private lateinit var saveButton: Button
    private lateinit var testResultText: TextView

    private lateinit var masterKey: MasterKey

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_settings)

        initViews()
        setupEncryptedPrefs()
        loadSettings()
        setupBiometric()
        setupTestButton()
        setupSaveButton()
    }

    private fun initViews() {
        serverUrlInput = findViewById(R.id.serverUrlInput)
        intervalInput = findViewById(R.id.intervalInput)
        nicknameInput = findViewById(R.id.nicknameInput)
        deviceIdText = findViewById(R.id.deviceIdText)
        passwordInput = findViewById(R.id.passwordInput)
        confirmPasswordInput = findViewById(R.id.confirmPasswordInput)
        biometricSwitch = findViewById(R.id.biometricSwitch)
        networkLocationSwitch = findViewById(R.id.networkLocationSwitch)
        autoStartSwitch = findViewById(R.id.autoStartSwitch)
        testButton = findViewById(R.id.testButton)
        saveButton = findViewById(R.id.saveButton)
        testResultText = findViewById(R.id.testResultText)

        // 密碼輸入為密碼類型
        passwordInput.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
        confirmPasswordInput.inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
    }

    private fun setupEncryptedPrefs() {
        masterKey = MasterKey.Builder(this)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()

        // 使用 EncryptedSharedPreferences 儲存敏感資料
        prefs = EncryptedSharedPreferences.create(
            this,
            "secure_prefs",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    private fun loadSettings() {
        // 載入一般設定
        val generalPrefs = getSharedPreferences("gps_tracker_prefs", MODE_PRIVATE)
        serverUrlInput.setText(generalPrefs.getString("server_url", ""))
        intervalInput.setText(generalPrefs.getString("upload_interval", "60"))
        nicknameInput.setText(generalPrefs.getString("nickname", ""))
        
        // 顯示 Device ID
        var deviceId = generalPrefs.getString("device_id", null)
        if (deviceId == null) {
            deviceId = java.util.UUID.randomUUID().toString()
            generalPrefs.edit().putString("device_id", deviceId).apply()
        }
        deviceIdText.text = deviceId

        // 載入安全性設定
        val hasPassword = prefs.getString("password", null) != null
        val biometricEnabled = prefs.getBoolean("biometric_enabled", false)

        if (hasPassword) {
            passwordInput.hint = "輸入新密碼（留空保持不變）"
            confirmPasswordInput.hint = "確認新密碼"
        }

        biometricSwitch.isChecked = biometricEnabled

        // 載入網路定位設定
        val networkLocationEnabled = generalPrefs.getBoolean("network_location", false)
        networkLocationSwitch.isChecked = networkLocationEnabled

        // 載入開機自動啟動設定
        val autoStartEnabled = generalPrefs.getBoolean("auto_start", false)
        autoStartSwitch.isChecked = autoStartEnabled

        // 檢查裝置是否支援指紋
        val biometricManager = BiometricManager.from(this)
        val canAuthenticate = biometricManager.canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)
        biometricSwitch.isEnabled = canAuthenticate == BiometricManager.BIOMETRIC_SUCCESS

        if (canAuthenticate != BiometricManager.BIOMETRIC_SUCCESS) {
            biometricSwitch.isChecked = false
            biometricSwitch.isEnabled = false
            Toast.makeText(this, "裝置不支援指紋辨識", Toast.LENGTH_SHORT).show()
        }
    }

    private fun setupBiometric() {
        // 指紋開關變更時儲存
        biometricSwitch.setOnCheckedChangeListener { _, isChecked ->
            prefs.edit().putBoolean("biometric_enabled", isChecked).apply()
        }
    }

    private fun setupTestButton() {
        testButton.setOnClickListener {
            val serverUrl = serverUrlInput.text.toString().trim()
            
            if (serverUrl.isEmpty()) {
                testResultText.text = "請先輸入伺服器網址"
                testResultText.setTextColor(getColor(android.R.color.holo_red_dark))
                return@setOnClickListener
            }
            
            if (!serverUrl.startsWith("http://") && !serverUrl.startsWith("https://")) {
                testResultText.text = "網址必須以 http:// 或 https:// 開頭"
                testResultText.setTextColor(getColor(android.R.color.holo_red_dark))
                return@setOnClickListener
            }
            
            testResultText.text = "測試中..."
            testResultText.setTextColor(getColor(android.R.color.darker_gray))
            
            // 執行測試
            Thread {
                try {
                    val url = java.net.URL(serverUrl)
                    val connection = url.openConnection() as java.net.HttpURLConnection
                    connection.requestMethod = "POST"
                    connection.setRequestProperty("Content-Type", "application/json")
                    connection.doOutput = true
                    connection.connectTimeout = 10000
                    connection.readTimeout = 10000
                    
                    // 發送測試資料
                    val testData = """{"device_id":"test","lat":25.0330,"lng":121.5654,"accuracy":10}"""
                    val outputStream = connection.outputStream
                    outputStream.write(testData.toByteArray())
                    outputStream.flush()
                    outputStream.close()
                    
                    val responseCode = connection.responseCode
                    runOnUiThread {
                        if (responseCode == 200 || responseCode == 201) {
                            testResultText.text = "連線成功！"
                            testResultText.setTextColor(getColor(android.R.color.holo_green_dark))
                        } else {
                            testResultText.text = "連線失敗: HTTP $responseCode"
                            testResultText.setTextColor(getColor(android.R.color.holo_red_dark))
                        }
                    }
                } catch (e: Exception) {
                    runOnUiThread {
                        testResultText.text = "連線錯誤: ${e.message}"
                        testResultText.setTextColor(getColor(android.R.color.holo_red_dark))
                    }
                }
            }.start()
        }
    }

    private fun setupSaveButton() {
        saveButton.setOnClickListener {
            val serverUrl = serverUrlInput.text.toString().trim()
            val interval = intervalInput.text.toString().trim()
            val nickname = nicknameInput.text.toString().trim()
            val password = passwordInput.text.toString()
            val confirmPassword = confirmPasswordInput.text.toString()

            // 驗證
            if (serverUrl.isEmpty()) {
                Toast.makeText(this, "請輸入伺服器網址", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            if (!serverUrl.startsWith("http://") && !serverUrl.startsWith("https://")) {
                Toast.makeText(this, "網址必須以 http:// 或 https:// 開頭", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            if (interval.isEmpty() || interval.toIntOrNull() == null || interval.toInt() < 10) {
                Toast.makeText(this, "上傳頻率必須 >= 10 秒", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            // 密碼處理
            if (password.isNotEmpty()) {
                if (password != confirmPassword) {
                    Toast.makeText(this, "兩次密碼不一致", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                if (password.length < 4) {
                    Toast.makeText(this, "密碼至少 4 碼", Toast.LENGTH_SHORT).show()
                    return@setOnClickListener
                }
                prefs.edit().putString("password", password).apply()
            }

            // 儲存一般設定
            val generalPrefs = getSharedPreferences("gps_tracker_prefs", MODE_PRIVATE)
            generalPrefs.edit()
                .putString("server_url", serverUrl)
                .putString("upload_interval", interval)
                .putString("nickname", nickname)
                .putBoolean("network_location", networkLocationSwitch.isChecked)
                .putBoolean("auto_start", autoStartSwitch.isChecked)
                .apply()

            // 如果服務正在運行，重新啟動以套用新設定
            if (LocationService.isRunning) {
                val serviceIntent = Intent(this, LocationService::class.java)
                stopService(serviceIntent)
                
                Handler(android.os.Looper.getMainLooper()).postDelayed({
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        startForegroundService(serviceIntent)
                    } else {
                        startService(serviceIntent)
                    }
                }, 500)
            }

            Toast.makeText(this, "設定已儲存", Toast.LENGTH_SHORT).show()
            finish()
        }
    }

    companion object {
        lateinit var prefs: SharedPreferences
            private set
    }
}
