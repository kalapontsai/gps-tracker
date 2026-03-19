<?php
/**
 * 緊急求救 SOS API
 * 使用方式：POST 到此檔案
 * 
 * 參數（JSON）：
 * - lat: 緯度
 * - lng: 經度
 * - accuracy: 精準度（公尺）
 * - timestamp: 時間戳記
 * - contacts: 緊急聯絡人 Email 陣列
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

if (empty($input['contacts']) || !is_array($input['contacts'])) {
    echo json_encode([
        'success' => false,
        'message' => '缺少緊急聯絡人'
    ]);
    exit;
}

// 取得資料
$lat = floatval($input['lat']);
$lng = floatval($input['lng']);
$accuracy = isset($input['accuracy']) ? floatval($input['accuracy']) : 0;
$timestamp = isset($input['timestamp']) ? $input['timestamp'] : date('Y-m-d H:i:s');
$contacts = $input['contacts'];
$checkIn = isset($input['check_in']) ? $input['check_in'] : '';

// 記錄收到 SOS 請求
error_log("Emergency SOS received: lat=$lat, lng=$lng, contacts=" . implode(',', $contacts) . ", check_in=" . $checkIn);

// 驗證座標範圍
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode([
        'success' => false,
        'message' => '座標範圍無效'
    ]);
    exit;
}

// 產生 Google Maps 連結
$mapsUrl = "https://www.google.com/maps?q=$lat,$lng";

// 建立訊息內容
$subject = "【緊急求救】GPS 追蹤器 - 位置回報";
$message = "這是一封緊急求救訊息！\n\n";
$message .= "=================================\n";
$message .= "       緊 急 求 救 訊 息\n";
$message .= "=================================\n\n";
$message .= "時間：$timestamp\n\n";

// 加入打卡資訊（如果有）
if (!empty($checkIn)) {
    $message .= "打卡內容：$checkIn\n\n";
}

$message .= "位置資訊：\n";
$message .= "- 緯度：$lat\n";
$message .= "- 經度：$lng\n";
$message .= "- 準確度：約 $accuracy 公尺\n\n";
$message .= "Google Maps 連結：\n";
$message .= "$mapsUrl\n\n";
$message .= "--\n";
$message .= "此訊息由 GPS 追蹤器自動發送";

// 發送郵件
$successCount = 0;
$failedContacts = [];

foreach ($contacts as $email) {
    $email = trim($email);
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failedContacts[] = "$email (無效格式)";
        error_log("Emergency SOS: Invalid email format - $email");
        continue;
    }
    
    // 使用 mail() 發送，重試機制
    $headers = "From: GPS Tracker <noreply@elhomeo.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    
    // 添加緊急性質
    $headers .= "X-Priority: 1\r\n";
    $headers .= "X-MSMail-Priority: High\r\n";
    
    $sendSuccess = false;
    $maxRetries = 3;
    $lastError = "";
    
    for ($retry = 1; $retry <= $maxRetries; $retry++) {
        error_log("Emergency SOS: Attempting to send to $email (attempt $retry/$maxRetries)");
        
        $sendResult = @mail($email, $subject, $message, $headers);
        
        if ($sendResult) {
            $sendSuccess = true;
            error_log("Emergency SOS: Successfully sent to $email on attempt $retry");
            break;
        } else {
            $lastError = error_get_last()['message'] ?? 'Unknown error';
            error_log("Emergency SOS: Failed attempt $retry to $email - $lastError");
            
            // 重試前等待
            if ($retry < $maxRetries) {
                sleep(2); // 等待 2 秒後重試
            }
        }
    }
    
    if ($sendSuccess) {
        $successCount++;
    } else {
        $failedContacts[] = "$email (重試 $maxRetries 次後仍失敗: $lastError)";
        error_log("Emergency SOS: All $maxRetries attempts failed for $email - $lastError");
    }
}

// 記錄 SOS 事件到資料庫
$dbFile = __DIR__ . '/gps_data.sqlite';
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 建立 SOS 記錄表（如果不存在）
    $pdo->exec("CREATE TABLE IF NOT EXISTS sos_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        accuracy REAL,
        timestamp TEXT NOT NULL,
        contacts TEXT,
        success_count INTEGER,
        failed_contacts TEXT,
        result TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    
    // 新增 SOS 記錄
    $stmt = $pdo->prepare("INSERT INTO sos_events (latitude, longitude, accuracy, timestamp, contacts, success_count, failed_contacts, result) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $resultText = "$successCount 成功, " . count($failedContacts) . " 失敗";
    $stmt->execute([$lat, $lng, $accuracy, $timestamp, implode(',', $contacts), $successCount, implode('; ', $failedContacts), $resultText]);
    
    error_log("Emergency SOS: Event logged - $resultText");
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
}

// 回應結果
if ($successCount > 0) {
    echo json_encode([
        'success' => true,
        'message' => "緊急求救已發送給 $successCount 位聯絡人",
        'data' => [
            'lat' => $lat,
            'lng' => $lng,
            'timestamp' => $timestamp,
            'success_count' => $successCount,
            'failed_contacts' => $failedContacts
        ]
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '無法發送緊急求救訊息',
        'errors' => $failedContacts
    ]);
}
