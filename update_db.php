<?php
/**
 * 更新資料庫結構 - 新增 check_in 欄位
 */

$dbFile = __DIR__ . '/gps_data.sqlite';

if (!file_exists($dbFile)) {
    die("資料庫檔案不存在");
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 檢查欄位是否存在
    $result = $pdo->query("PRAGMA table_info(gps_locations)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    
    $hasCheckIn = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'check_in') {
            $hasCheckIn = true;
            break;
        }
    }
    
    if (!$hasCheckIn) {
        $pdo->exec("ALTER TABLE gps_locations ADD COLUMN check_in TEXT");
        echo "已成功新增 check_in 欄位";
    } else {
        echo "check_in 欄位已存在";
    }
    
} catch (PDOException $e) {
    echo "錯誤: " . $e->getMessage();
}
