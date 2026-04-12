<?php
/**
 * GPS 資料列表頁面
 * 條列式顯示 GPS 資料，含日期篩選
 */

// 取得篩選參數
$nickname = $_GET['nickname'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 20);
$perPageOptions = [10, 20, 50, 100];

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
        if (!empty($nickname)) {
            $sql .= " AND nickname LIKE ?";
            $params[] = '%' . $nickname . '%';
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = ($page - 1) * $perPage;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 取得總數
        $countParams = [];
        $countSql = "SELECT COUNT(*) as count FROM gps_locations WHERE 1=1";
        
        if (!empty($startDate)) {
            $countSql .= " AND timestamp >= ?";
            $countParams[] = $startDate . ' 00:00:00';
        }
        if (!empty($endDate)) {
            $countSql .= " AND timestamp <= ?";
            $countParams[] = $endDate . ' 23:59:59';
        }
        if (!empty($nickname)) {
            $countSql .= " AND nickname LIKE ?";
            $countParams[] = '%' . $nickname . '%';
        }
        
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($countParams);
        $totalCount = $stmtCount->fetch()['count'];
        
        $totalPages = ceil($totalCount / $perPage);
        
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
            flex-wrap: wrap;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            flex: 1;
            min-width: 120px;
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
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        @media (max-width: 640px) {
            body { padding: 10px; }
            .filter { padding: 12px; }
            .filter-row { gap: 10px; }
            .filter-group { min-width: 45%; }
            .filter-group input, .filter-group select { padding: 8px; font-size: 13px; }
            th, td { padding: 10px 8px; font-size: 13px; }
            .stats { flex-direction: column; gap: 10px; }
            .stat-card { min-width: auto; }
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
        }
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination .current {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination-info {
            text-align: center;
            margin-top: 15px;
            color: #666;
            font-size: 14px;
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
                        <label>暱稱</label>
                        <input type="text" name="nickname" value="<?php echo htmlspecialchars($nickname); ?>" placeholder="輸入暱稱">
                    </div>
                    <div class="filter-group">
                        <label>每頁筆數</label>
                        <select name="per_page">
                            <?php foreach ($perPageOptions as $opt): ?>
                                <option value="<?php echo $opt; ?>" <?php echo $perPage == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                            <?php endforeach; ?>
                        </select>
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
                
                <?php if ($totalPages > 1): ?>
                <div class="pagination-info">
                    顯示第 <?php echo ($page - 1) * $perPage + 1; ?> - <?php echo min($page * $perPage, $totalCount); ?> 筆，共 <?php echo $totalCount; ?> 筆
                </div>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">第一頁</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">上一頁</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">下一頁</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">最後頁</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
