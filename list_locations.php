<?php
/**
 * GPS 資料列表頁面
 * 條列式顯示 GPS 資料，含日期篩選
 */

// 取得篩選參數
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$deviceId = $_GET['device_id'] ?? '';

$dbFile = __DIR__ . '/gps_data.sqlite';
$locations = [];
$totalCount = 0;
$deviceCount = 0;

if (file_exists($dbFile)) {
    try {
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // 建立查詢
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
        if (!empty($deviceId)) {
            $sql .= " AND device_id LIKE ?";
            $params[] = $deviceId . '%';
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT 500";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 取得總數
        $countSql = str_replace('SELECT *', 'SELECT COUNT(*) as count', explode('ORDER BY', $sql)[0]);
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalCount = $stmtCount->fetch()['count'];
        
        // 取得裝置數量
        $deviceStmt = $pdo->query("SELECT COUNT(DISTINCT device_id) as count FROM gps_locations");
        $deviceCount = $deviceStmt->fetch()['count'];
        
    } catch (PDOException $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS 追蹤記錄列表</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #667eea;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .filter {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .filter-group label {
            font-size: 14px;
            color: #666;
            font-weight: bold;
        }
        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .filter-group button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
        }
        .filter-group button:hover {
            background: #5568d3;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            flex: 1;
        }
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        .stat-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        .data-list {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f9f9f9;
            font-weight: bold;
            color: #333;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .device-id {
            font-family: monospace;
            background: #eee;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .accuracy-good { color: #28a745; }
        .accuracy-medium { color: #ffc107; }
        .accuracy-bad { color: #dc3545; }
        .empty {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .error {
            background: #fee;
            color: #c00;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← 返回首頁</a>
        
        <h1>GPS 追蹤記錄列表</h1>
        
        <?php if (isset($error)): ?>
            <div class="error">錯誤: <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="filter">
            <form method="GET" action="list_locations.php">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>起始日期</label>
                        <input type="date" name="start_date" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label>結束日期</label>
                        <input type="date" name="end_date" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label>裝置 ID</label>
                        <input type="text" name="device_id" value="<?php echo htmlspecialchars($deviceId); ?>" placeholder="輸入裝置 ID">
                    </div>
                    <div class="filter-group">
                        <button type="submit">篩選</button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <h3>總筆數</h3>
                <div class="value"><?php echo $totalCount; ?></div>
            </div>
            <div class="stat-card">
                <h3>日期範圍</h3>
                <div class="value"><?php echo $startDate; ?> ~ <?php echo $endDate; ?></div>
            </div>
            <div class="stat-card">
                <h3>裝置數量</h3>
                <div class="value"><?php echo $deviceCount; ?></div>
            </div>
        </div>
        
        <div class="data-list">
            <?php if (empty($locations)): ?>
                <div class="empty">
                    <p>沒有找到符合的資料</p>
                    <p>請確認 GPS 裝置已發送資料到此伺服器</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>時間</th>
                            <th>暱稱</th>
                            <th>打卡</th>
                            <th>裝置 ID</th>
                            <th>緯度</th>
                            <th>經度</th>
                            <th>精準度</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($locations as $loc): ?>
                            <?php 
                                $accClass = $loc['accuracy'] <= 20 ? 'accuracy-good' : ($loc['accuracy'] <= 100 ? 'accuracy-medium' : 'accuracy-bad');
                                $displayNickname = !empty($loc['nickname']) ? htmlspecialchars($loc['nickname']) : '-';
                                $displayCheckIn = !empty($loc['check_in']) ? htmlspecialchars($loc['check_in']) : '-';
                            ?>
                            <tr>
                                <td><?php echo $loc['timestamp']; ?></td>
                                <td><?php echo $displayNickname; ?></td>
                                <td><?php echo $displayCheckIn; ?></td>
                                <td><span class="device-id"><?php echo htmlspecialchars(substr($loc['device_id'], 0, 16)); ?></span></td>
                                <td><?php echo number_format($loc['latitude'], 6); ?></td>
                                <td><?php echo number_format($loc['longitude'], 6); ?></td>
                                <td class="<?php echo $accClass; ?>"><?php echo $loc['accuracy']; ?>m</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
