<?php
/**
 * GPS 資料匯出 (JSON)
 * 依照目前篩選條件匯出資料
 */

$nickname = $_GET['nickname'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

$dbFile = __DIR__ . '/gps_data.sqlite';
$locations = [];

if (file_exists($dbFile)) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $sql = "SELECT * FROM gps_locations WHERE 1=1";
        $params = [];
        
        if (!empty($startDate)) {
            $sql .= " AND timestamp >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        if (!empty($endDate)) {
            $sql .= " AND timestamp <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        if (!empty($nickname)) {
            $sql .= " AND nickname LIKE ?";
            $params[] = '%' . $nickname . '%';
        }
        
        $sql .= " ORDER BY timestamp DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $locations = [];
    }
}

// 輸出 JSON
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename=gps_locations_' . date('Ymd_His') . '.json');

echo json_encode([
    'export_time' => date('Y-m-d H:i:s'),
    'conditions' => [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'nickname' => $nickname
    ],
    'total_count' => count($locations),
    'locations' => $locations
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
