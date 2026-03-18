# GPS 追蹤器專案

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

一個 Android 背景 GPS 追蹤應用程式，配合 PHP 後端接收與儲存位置資料。

## 檔案結構

```
gps-tracker/
├── android/                          # Android Studio 專案
│   ├── app/
│   │   ├── src/main/
│   │   │   ├── java/com/example/gpstracker/
│   │   │   │   ├── MainActivity.kt      # 主頁面（含鎖定畫面）
│   │   │   │   ├── LocationService.kt   # 背景追蹤服務
│   │   │   │   ├── SettingsActivity.kt  # 設定頁面
│   │   │   │   └── BootReceiver.kt      # 開機自動啟動
│   │   │   ├── res/
│   │   │   │   ├── layout/
│   │   │   │   │   ├── activity_main.xml
│   │   │   │   │   ├── activity_settings.xml
│   │   │   │   │   └── activity_lock.xml
│   │   │   │   └── values/
│   │   │   └── AndroidManifest.xml
│   │   └── build.gradle
│   └── build.gradle
│
├── receive_gps.php                   # 接收 GPS 資料 API
├── get_locations.php                  # 取得位置資料 API
├── view_locations.php                 # 查看追蹤記錄頁面（含地圖 + 日期篩選）
├── list_locations.php                 # 條列式記錄（含日期篩選）
├── cleanup.php                        # 清理 30 天前舊資料
└── update_db.php                      # 資料庫更新腳本
```

## 功能特色

### App 端
- **設定 UI**：網址、頻率、暱稱、密碼、指紋
- **鎖定畫面**：密碼或指紋驗證
- **背景追蹤**：持續記錄 GPS 位置
- **開機自動啟動**：手機重開機後自動開始追蹤
- **可調式上傳頻率**：預設 60 秒，可設定 10 秒以上
- **網路定位備援**：室內也可定位（較不精準）
- **顯示裝置 ID**：設定頁面顯示專屬裝置識別碼
- **即時狀態顯示**：App 首頁顯示 GPS 訊號、座標、準確度、上傳狀態

### Server 端
- **SQLite 資料庫**：輕量級儲存
- **30 天資料保留**：自動刪除舊資料
- **地圖檢視**：Leaflet 地圖顯示
- **日期篩選**：可按日期範圍篩選記錄
- **條列檢視**：表格方式顯示所有記錄

---

## Android 端設定

### 1. 編譯 APK

```bash
cd android
./gradlew assembleDebug
```

APK 輸出位置：`android/app/build/outputs/apk/debug/app-debug.apk`

### 2. 權限說明

- `ACCESS_FINE_LOCATION` - 精確 GPS
- `ACCESS_COARSE_LOCATION` - 網路定位
- `ACCESS_BACKGROUND_LOCATION` - 背景追蹤（Android 10+）
- `POST_NOTIFICATIONS` - 通知（Android 13+）

### 3. 首次使用

1. 安裝 APK 後打開 App
2. 設定伺服器網址（如 `https://your-domain.com/receive_gps.php`）
3. 設定上傳頻率（秒）
4. 設定暱稱（顯示於網頁）
5. 可選擇設定密碼和開啟指紋辨識
6. 可選擇開啟「網路定位備援」（室內使用）
7. 可選擇開啟「開機自動啟動」
8. 點擊「開始追蹤」

---

## PHP 後端設定

### 1. 上傳 PHP 檔案

將以下檔案上傳到您的網頁伺服器：
- `receive_gps.php` - 接收資料
- `get_locations.php` - API
- `view_locations.php` - 地圖查看（含日期篩選）
- `list_locations.php` - 列表查看（含日期篩選）
- `cleanup.php` - 清理舊資料（可設定排程）
- `update_db.php` - 資料庫更新

### 2. 權限設定

確保資料夾有寫入權限（SQLite 需要）：
```bash
chmod 755 your-web-folder
```

### 3. 查看追蹤記錄

- 地圖檢視：`https://your-domain.com/view_locations.php`
- 列表檢視：`https://your-domain.com/list_locations.php`
- 首頁：`https://your-domain.com/index.php`

---

## API 說明

### 接收位置 POST /receive_gps.php

```json
{
    "device_id": "裝置識別碼",
    "nickname": "暱稱",
    "lat": 25.033123,
    "lng": 121.565432,
    "accuracy": 10.5,
    "timestamp": "2026-03-16T12:00:00Z"
}
```

### 取得位置 GET /get_locations.php

參數：
- `start_date` - 起始日期（YYYY-MM-DD）
- `end_date` - 結束日期（YYYY-MM-DD）
- `device_id` - 裝置 ID
- `limit` - 筆數限制（預設 100）

回傳：
```json
[
    {
        "id": 1,
        "device_id": "abc123...",
        "nickname": "我的手機",
        "latitude": 25.033123,
        "longitude": 121.565432,
        "accuracy": 10.5,
        "timestamp": "2026-03-16T12:00:00Z"
    }
]
```

---

## 資料保留設定

### 自動清理

系統會自動刪除 30 天前的資料（每次收到新資料時執行）。

### 手動清理

```bash
curl https://your-domain.com/cleanup.php
```

### 自動排程

可在伺服器設定 crontab 每天執行：
```bash
0 3 * * * curl -s https://your-domain.com/cleanup.php
```

---

## 注意事項

1. **HTTPS**：正式環境請使用 HTTPS
2. **SSL 憑證**：Android 預設不接受自簽憑證
3. **電量**：背景追蹤會消耗較多電量，建議開啟「網路定位備援」可加快定位速度
4. **流量**：每分鐘上傳一次，約 200-500 bytes/次
5. **省電**：已移除定時檢查，完全依賴系統事件驅動
