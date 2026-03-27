<?php
/**
 * GPS Tracker 主頁面
 * 密碼保護機制
 */

// 設定 Token（請修改為您想要的密碼）
define('ACCESS_TOKEN', 'gps123');

// 啟用 Session
session_start();

// 處理登出
if (isset($_GET['logout'])) {
    unset($_SESSION['gps_token']);
    header('Location: index.php');
    exit;
}

// 驗證 Token
$isAuthenticated = false;
if (isset($_SESSION['gps_token']) && $_SESSION['gps_token'] === md5(ACCESS_TOKEN)) {
    $isAuthenticated = true;
}

// 處理登入
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token'])) {
    if (md5($_POST['token']) === md5(ACCESS_TOKEN)) {
        $_SESSION['gps_token'] = md5(ACCESS_TOKEN);
        $isAuthenticated = true;
    } else {
        $loginError = '密碼錯誤，請重試';
    }
}

// 如果未驗證，顯示登入表單
if (!$isAuthenticated):
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Tracker - 驗證</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 15px;
            text-align: center;
        }
        input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            margin-bottom: 15px;
        }
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
        }
        button {
            width: 100%;
            padding: 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            cursor: pointer;
        }
        button:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1>GPS Tracker</h1>
        <p style="text-align:center;margin-bottom:20px;color:#666;">請輸入密碼存取</p>
        
        <?php if ($loginError): ?>
        <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="password" name="token" placeholder="請輸入密碼" required autofocus>
            <button type="submit">驗證</button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Tracker</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 10px;
            font-size: 32px;
        }
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 40px;
        }
        .menu {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .menu-item {
            display: block;
            padding: 20px 25px;
            background: #f8f9fa;
            border-radius: 12px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .menu-item:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .menu-item h3 {
            margin-bottom: 5px;
            font-size: 18px;
        }
        .menu-item p {
            font-size: 14px;
            opacity: 0.7;
        }
        .api-info {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        .api-info h4 {
            margin-bottom: 10px;
            color: #333;
        }
        .api-info code {
            display: block;
            background: #2d2d2d;
            color: #50fa7b;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 10px;
            overflow-x: auto;
        }
        .status {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 20px;
            color: #28a745;
            font-weight: bold;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            background: #28a745;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .logout {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #666;
            text-decoration: none;
        }
        .logout:hover {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="status">
            <span class="status-dot"></span>
            系統運作中
        </div>
        
        <h1>GPS Tracker</h1>
        <p class="subtitle">追蹤你的裝置位置</p>
        
        <div class="menu">
            <a href="view_locations.php" class="menu-item">
                <h3>🗺️ 查看地圖</h3>
                <p>顯示所有追蹤裝置的即時位置</p>
            </a>
            
            <a href="list_locations.php" class="menu-item">
                <h3>📋 記錄列表</h3>
                <p>條列式顯示 GPS 資料，含日期篩選</p>
            </a>

            <a href="pull_location.html" class="menu-item">
                <h3>📍 網頁回報位置</h3>
                <p>透過瀏覽器向伺服器回報目前位置</p>
            </a>
        </div>
        
        <div class="api-info">
            <h4>API 使用方式</h4>
            
            <p style="margin-bottom: 10px; font-weight: bold;">📥 發送 GPS 資料 (POST)</p>
            <code>curl -X POST https://your-domain.com/receive_gps.php \
  -H "Content-Type: application/json" \
  -d '{"device_id":"your-device-id","nickname":"名稱","lat":25.033,"lng":121.5654,"accuracy":10}'</code>
            
            <p style="margin: 15px 0 10px 0; font-weight: bold;">📊 取得 GPS 資料 (GET)</p>
            <code>curl https://your-domain.com/get_locations.php</code>
            
            <p style="margin: 15px 0 10px 0; font-weight: bold;">📊 篩選資料 (GET)</p>
            <code>curl "https://your-domain.com/get_locations.php?start_date=2026-03-17&end_date=2026-03-17&device_id=xxx"</code>
            
            <p style="margin: 15px 0 10px 0; font-weight: bold;">🔗 網頁查看</p>
            <code>https://your-domain.com/view_locations.php</code>
        </div>
        
        <a href="?logout=1" class="logout">登出</a>
    </div>
</body>
</html>
