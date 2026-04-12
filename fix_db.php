<?php
/**
 * 更新資料庫結構 - 新增 check_in 欄位
 */

$dbFile = __DIR__ . '/gps_data.sqlite';

if (!file_exists($dbFile)) {
    die("資料庫檔案不存在: $dbFile");
}

try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 嘗試直接新增欄位
    $pdo->exec("ALTER TABLE gps_locations ADD COLUMN check_in TEXT");
    echo "check_in 欄位已新增";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column name') !== false) {
        echo "check_in 欄位已存在";
    } else {
        echo "錯誤: " . $e->getMessage();
    }
}
