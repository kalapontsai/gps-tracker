<?php
/**
 * GPS 資料接收 API
 * 使用方式：POST 到此檔案
 * 
 * 參數：
 * - lat: 緯度
 * - lng: 經度
 * - accuracy: 精準度（公尺）
 * - device_id: 裝置識別碼
 * - nickname: 暱稱
 * - timestamp: 時間戳記
 */

// 設定回應為 JSON
header('Content-Type: application/json; charset=utf-8');

// 允許跨域請求（開發測試用）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// 處理預檢請求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 僅接受 POST 請求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => '僅接受 POST 請求'
    ]);
    exit;
}

// 取得 POST 資料
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// 驗證必要欄位
if (empty($input['lat']) || empty($input['lng'])) {
    echo json_encode([
        'success' => false,
        'message' => '缺少經緯度資料'
    ]);
    exit;
}

// 取得資料
$lat = floatval($input['lat']);
$lng = floatval($input['lng']);
$accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : 0;
$deviceId = isset($input['device_id']) ? $input['device_id'] : 'unknown';
$nickname = isset($input['nickname']) ? $input['nickname'] : '';
$timestamp = isset($input['timestamp']) ? $input['timestamp'] : date('c');
$checkIn = isset($input['check_in']) ? $input['check_in'] : '';

// 記錄收到的打卡資料
if (!empty($checkIn)) {
    error_log("Check-in received: $checkIn");
}

// 驗證座標範圍
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode([
        'success' => false,
        'message' => '座標範圍無效'
    ]);
    exit;
}

// 資料庫設定（SQLite 簡單範例）
$dbFile = __DIR__ . '/gps_data.sqlite';

// 建立資料表（如果不存在）
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS gps_locations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id TEXT NOT NULL,
        nickname TEXT,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        accuracy REAL,
        timestamp TEXT NOT NULL,
        check_in TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 新增位置資料
    $stmt = $pdo->prepare("INSERT INTO gps_locations (device_id, nickname, latitude, longitude, accuracy, timestamp, check_in) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$deviceId, $nickname, $lat, $lng, $accuracy, $timestamp, $checkIn]);
    
    $locationId = $pdo->lastInsertId();
    
    // 回應成功
    echo json_encode([
        'success' => true,
        'message' => '位置資料已儲存',
        'data' => [
            'id' => $locationId,
            'lat' => $lat,
            'lng' => $lng,
            'device_id' => $deviceId,
            'nickname' => $nickname,
            'timestamp' => $timestamp
        ]
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => '資料庫錯誤: ' . $e->getMessage()
    ]);
}
