# GPS 追蹤器專案 (媽媽的關心)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

一個 Android 背景 GPS 追蹤應用程式，配合 PHP 後端接收與儲存位置資料。

## 檔案結構

```
gps-tracker/
├── android/                          # Android Studio 專案
│   ├── app/
│   │   ├── src/main/
│   │   │   ├── java/com/example/gpstracker/
│   │   │   │   ├── MainActivity.kt      # 主頁面（含打卡功能）
│   │   │   │   ├── LocationService.kt   # 背景追蹤服務
│   │   │   │   ├── SettingsActivity.kt   # 設定頁面
│   │   │   │   └── BootReceiver.kt      # 開機自動啟動
│   │   │   ├── res/
│   │   │   │   └── layout/
│   │   │   └── AndroidManifest.xml
│   │   └── build.gradle
│   └── build.gradle
│
├── receive_gps.php                   # 接收 GPS 資料 API
├── get_locations.php                 # 取得位置資料 API
├── view_locations.php                # 地圖檢視頁面（含日期篩選）
├── list_locations.php                # 條列式記錄（含日期篩選）
├── emergency_sos.php                # 緊急求救 API
├── rebuild_db.php                   # 資料庫重建腳本
└── index.php                        # 首頁
```

## 功能特色

### App 端
- **打卡功能** - 輸入打卡文字立即發送 GPS 位置到伺服器
- **緊急求救** - 大紅色圓形按鈕，一鍵發送位置給緊急聯絡人
- **背景追蹤** - 持續記錄 GPS 位置
- **設定項目**：
  - 伺服器網址
  - 上傳頻率（秒）
  - 暱稱
  - 密碼 / 指紋鎖定
  - 緊急聯絡人（不限數量）
  - 網路定位備援
  - 開機自動啟動
- **開機自動啟動** - 手機重開機後自動開始追蹤
- **可調式上傳頻率** - 預設 60 秒，可設定 10 秒以上
- **顯示裝置 ID** - 設定頁面顯示專屬裝置識別碼

### Server 端
- **SQLite 資料庫** - 輕量級儲存
- **打卡記錄** - 儲存打卡文字
- **緊急求救** - 發送 Email 給緊急聯絡人（包含 GPS 位置、Google Maps 連結）
- **重試機制** - 發送失敗時最多重試 3 次
- **地圖檢視** - Leaflet 地圖顯示
- **日期篩選** - 可按日期範圍篩選記錄

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
5. **設定緊急聯絡人**（用於緊急求救功能）
6. 可選擇設定密碼和開啟指紋辨識
7. 可選擇開啟「網路定位備援」（室內使用）
8. 可選擇開啟「開機自動啟動」
9. 點擊「開始追蹤」

---

## PHP 後端設定

### 1. 上傳 PHP 檔案

將以下檔案上傳到您的網頁伺服器：
- `receive_gps.php` - 接收 GPS 資料
- `get_locations.php` - API
- `view_locations.php` - 地圖查看
- `list_locations.php` - 列表查看
- `emergency_sos.php` - 緊急求救 API
- `index.php` - 首頁
- `rebuild_db.php` - 資料庫重建（如需要）

### 2. 權限設定

確保資料夾有寫入權限（SQLite 需要）：
```bash
chmod 755 your-web-folder
```

### 3. 查看追蹤記錄

- 首頁：`https://your-domain.com/index.php`
- 地圖檢視：`https://your-domain.com/view_locations.php`
- 列表檢視：`https://your-domain.com/list_locations.php`

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
    "timestamp": "2026-03-19T12:00:00",
    "check_in": "打卡文字（可選）"
}
```

### 緊急求救 POST /emergency_sos.php

```json
{
    "lat": 25.033123,
    "lng": 121.565432,
    "accuracy": 10.5,
    "timestamp": "2026-03-19T12:00:00",
    "contacts": ["email1@example.com", "email2@example.com"]
}
```

回傳：
```json
{
    "success": true,
    "message": "緊急求救已發送給 2 位聯絡人",
    "data": {
        "lat": 25.033123,
        "lng": 121.565432,
        "success_count": 2
    }
}
```

### 取得位置 GET /get_locations.php

參數：
- `start_date` - 起始日期（YYYY-MM-DD）
- `end_date` - 結束日期（YYYY-MM-DD）
- `device_id` - 裝置 ID
- `limit` - 筆數限制（預設 100）

---

## 緊急求救功能說明

1. 在設定頁面新增緊急聯絡人 Email（不限數量）
2. 首頁的大紅色圓形按鈕為「緊急求救」
3. 按下後會發送 Email 給所有緊急聯絡人
4. 郵件內容包含：
   - 發生時間
   - GPS 座標
   - Google Maps 連結
   - 打卡文字（如果有）
5. 發送失敗會自動重試最多 3 次

---

## 打卡功能說明

1. 在首頁輸入打卡文字
2. 點擊「確認」按鈕
3. 立即取得目前 GPS 位置並發送到伺服器
4. 不需要等待下一次定時發送
5. 伺服器會記錄打卡文字在 `check_in` 欄位

---

## 注意事項

1. **HTTPS**：正式環境請使用 HTTPS
2. **SSL 憑證**：Android 預設不接受自簽憑證
3. **電量**：背景追蹤會消耗較多電量，建議開啟「網路定位備援」可加快定位速度
4. **流量**：每分鐘上傳一次，約 200-500 bytes/次
5. **省電**：完全依賴系統事件驅動，無定時檢查
