<?php
/**
 * 重建資料庫
 */

$dbFile = __DIR__ . '/gps_data.sqlite';

if (file_exists($dbFile)) {
    unlink($dbFile);
    echo "舊資料庫已刪除";
} else {
    echo "資料庫不存在";
}

// 建立新資料庫
try {
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 建立 gps_locations 表
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
    
    echo "，新資料庫已建立（含 check_in 欄位）";
    
} catch (PDOException $e) {
    echo "錯誤: " . $e->getMessage();
}
