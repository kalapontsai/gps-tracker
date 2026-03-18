<?php
/**
 * 取得 GPS 位置資料 API
 * 回傳 JSON 格式
 * 支援日期篩選
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dbFile = __DIR__ . '/gps_data.sqlite';

if (!file_exists($dbFile)) {
    echo json_encode([]);
    exit;
}

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$deviceId = $_GET['device_id'] ?? '';

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 建立查詢
    $sql = "SELECT * FROM gps_locations WHERE 1=1";
    $params = [];
    
    if (!empty($startDate)) {
        $sql .= " AND date(timestamp) >= date(?)";
        $params[] = $startDate;
    }
    
    if (!empty($endDate)) {
        $sql .= " AND date(timestamp) <= date(?)";
        $params[] = $endDate;
    }
    
    if (!empty($deviceId)) {
        $sql .= " AND device_id = ?";
        $params[] = $deviceId;
    }
    
    $sql .= " ORDER BY timestamp DESC LIMIT 100";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($locations);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
