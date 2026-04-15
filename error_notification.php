<?php
/**
 * 錯誤通知 Email API
 * GPS 追蹤器上傳失敗時，發送錯誤資訊到緊急聯絡人
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '僅接受 POST 請求']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// 驗證必要欄位
if (empty($input['device_id']) || empty($input['error_message'])) {
    echo json_encode(['success' => false, 'message' => '缺少必要參數']);
    exit;
}

$deviceId = trim($input['device_id']);
$nickname = isset($input['nickname']) ? trim($input['nickname']) : '';
$errorMessage = trim($input['error_message']);
$failCount = isset($input['fail_count']) ? intval($input['fail_count']) : 0;
$latitude = isset($input['lat']) ? floatval($input['lat']) : 0;
$longitude = isset($input['lng']) ? floatval($input['lng']) : 0;
$contacts = isset($input['contacts']) && is_array($input['contacts']) ? $input['contacts'] : [];

$timestamp = date('Y-m-d H:i:s');

// 如果沒有提供 contacts，從資料庫查詢
if (empty($contacts)) {
    $dbFile = __DIR__ . '/gps_data.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 從 settings 表取得緊急聯絡人
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'emergency_contacts'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && $row['value']) {
            $contacts = json_decode($row['value'], true) ?? [];
        }
    } catch (PDOException $e) {
        error_log("Error fetching contacts: " . $e->getMessage());
    }
    
    // 如果還是沒有，使用預設聯絡人（可設定）
    if (empty($contacts)) {
        // 嘗試從 config 讀取預設郵件
        $configFile = __DIR__ . '/config.json';
        if (file_exists($configFile)) {
            $config = json_decode(file_get_contents($configFile), true);
            if (!empty($config['error_notification_email'])) {
                $contacts = [$config['error_notification_email']];
            }
        }
    }
}

error_log("Error notification: device=$deviceId, nickname=$nickname, error=$errorMessage, fail_count=$failCount, contacts=" . implode(',', $contacts));

// 如果有座標，產生 Google Maps 連結
$mapsUrl = '';
if ($latitude != 0 && $longitude != 0) {
    $mapsUrl = "https://www.google.com/maps?q=$latitude,$longitude";
}

// 建立郵件內容
$subject = "【GPS 追蹤器錯誤通知】" . ($nickname ? "$nickname - " : "") . "連續失敗 $failCount 次";
$message = "GPS 追蹤器錯誤通知\n\n";
$message .= "=================================\n";
$message .= "       GPS 追 蹤 錯 誤 通 知\n";
$message .= "=================================\n\n";
$message .= "時間：$timestamp\n";
$message .= "暱稱：$nickname\n";
$message .= "裝置：$deviceId\n";
$message .= "連續失敗：$failCount 次\n\n";
$message .= "錯誤內容：\n$errorMessage\n\n";

if ($mapsUrl) {
    $message .= "最後已知位置：\n";
    $message .= "- 緯度：$latitude\n";
    $message .= "- 經度：$longitude\n";
    $message .= "Google Maps：$mapsUrl\n\n";
}

$message .= "--\n";
$message .= "此訊息由 GPS 追蹤器自動發送\n";
$message .= "請確認裝置運作正常";

// 發送郵件
$successCount = 0;
$failedContacts = [];

if (empty($contacts)) {
    error_log("No contacts configured for error notification");
    echo json_encode(['success' => false, 'message' => '未設定緊急聯絡人']);
    exit;
}

foreach ($contacts as $email) {
    $email = trim($email);
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failedContacts[] = "$email (無效格式)";
        continue;
    }
    
    $headers = "From: GPS Tracker <noreply@elhomeo.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    $headers .= "X-Priority: 3\r\n";
    
    $sendResult = @mail($email, $subject, $message, $headers);
    
    if ($sendResult) {
        $successCount++;
        error_log("Error notification sent to $email");
    } else {
        $failedContacts[] = $email;
        error_log("Failed to send error notification to $email");
    }
}

if ($successCount > 0) {
    echo json_encode([
        'success' => true,
        'message' => "錯誤通知已發送給 $successCount 位聯絡人",
        'sent_count' => $successCount,
        'failed' => $failedContacts
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => '無法發送錯誤通知',
        'errors' => $failedContacts
    ]);
}