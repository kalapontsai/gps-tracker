<?php
/**
 * GPS 資料查看頁面
 * 顯示所有追蹤的 GPS 資料，含日期篩選
 * 預設空白，使用者篩選後才顯示
 */

// 取得篩選參數
$nickname = $_GET['nickname'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

// 處理清理請求
$cleanupMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'cleanup') {
        $keepDays = intval($_POST['keep_days'] ?? 7);
        $dbFile = __DIR__ . '/gps_data.sqlite';
        
        if (file_exists($dbFile)) {
            try {
                $pdo = new PDO('sqlite:' . $dbFile);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
                $stmt = $pdo->prepare("DELETE FROM gps_locations WHERE created_at < ?");
                $stmt->execute([$cutoffDate]);
                $deleted = $stmt->rowCount();
                
                $cleanupMessage = "已刪除 $deleted 筆超過 {$keepDays} 天的記錄";
            } catch (PDOException $e) {
                $cleanupMessage = "清理失敗: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS 追蹤記錄</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f5f5f5; }
        h1 { margin-bottom: 20px; color: #333; }
        .controls { margin-bottom: 20px; background: white; padding: 15px; border-radius: 8px; }
        .controls-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px; }
        .controls select, .controls button, .controls input { padding: 8px 12px; font-size: 14px; border: 1px solid #ddd; border-radius: 6px; }
        .controls label { font-size: 14px; color: #666; }
        .controls button { background: #667eea; color: white; border: none; cursor: pointer; }
        .controls button:hover { background: #5568d3; }
        .controls button.danger { background: #dc3545; }
        .controls button.danger:hover { background: #c82333; }
        .back-link { display: inline-block; margin-bottom: 10px; color: #667eea; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        #map { height: 500px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); background: #f0f0f0; }
        .empty-state { height: 500px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; border-radius: 8px; color: #999; font-size: 16px; }
        .info { margin-top: 20px; padding: 15px; background: white; border-radius: 8px; }
        .info h3 { margin-bottom: 10px; }
        .table-controls { margin-bottom: 10px; display: flex; gap: 10px; align-items: center; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        #tableContainer { overflow-x: auto; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f9f9f9; }
        .empty-table { text-align: center; color: #999; padding: 40px; }
        .cleanup-section { margin-top: 20px; padding: 15px; background: white; border-radius: 8px; border: 1px solid #ddd; }
        .cleanup-section h3 { margin-bottom: 10px; color: #dc3545; }
        .cleanup-form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .cleanup-form select, .cleanup-form input { padding: 8px 12px; font-size: 14px; border: 1px solid #ddd; border-radius: 6px; }
        .message { padding: 10px; margin-bottom: 10px; border-radius: 6px; }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <a href="index.php" class="back-link">← 返回首頁</a>
    
    <h1>GPS 追蹤記錄</h1>
    
    <?php if ($cleanupMessage): ?>
    <div class="message <?php echo strpos($cleanupMessage, '失敗') !== false ? 'error' : 'success'; ?>">
        <?php echo htmlspecialchars($cleanupMessage); ?>
    </div>
    <?php endif; ?>
    
    <div class="controls">
        <div class="controls-row">
            <label>起始日期：</label>
            <input type="date" id="startDate" value="<?php echo $startDate; ?>">
            <label>結束日期：</label>
            <input type="date" id="endDate" value="<?php echo $endDate; ?>">
            <button onclick="applyFilter()">篩選</button>
            <button onclick="clearFilter()">清除</button>
        </div>
        <div class="controls-row">
            <label>暱稱：</label>
            <select id="deviceFilter">
                <option value="">所有裝置</option>
            </select>
        </div>
    </div>
    
    <div id="map">
        <div class="empty-state">請選擇篩選條件查看地圖</div>
    </div>
    
    <div class="info">
        <h3>記錄列表</h3>
        <div class="table-controls">
            <label>篩選暱稱：</label>
            <select id="tableDeviceFilter">
                <option value="">所有</option>
            </select>
            <button onclick="applyTableFilter()">套用</button>
        </div>
        <div id="tableContainer">
            <div class="empty-table">請選擇篩選條件查看記錄</div>
        </div>
    </div>
    
    <div class="cleanup-section">
        <h3>清理舊記錄</h3>
        <form method="POST" class="cleanup-form">
            <input type="hidden" name="action" value="cleanup">
            <label>保留最近</label>
            <select name="keep_days">
                <option value="1">1 天</option>
                <option value="3">3 天</option>
                <option value="7" selected>7 天</option>
                <option value="14">14 天</option>
                <option value="30">30 天</option>
                <option value="90">90 天</option>
            </select>
            <button type="submit" class="danger">執行清理</button>
        </form>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        let map;
        let markers = [];
        let locations = [];
        let mapInitialized = false;
        
        // 初始化地圖
        function initMap() {
            if (mapInitialized) return;
            document.getElementById('map').innerHTML = '';
            map = L.map('map').setView([25.0330, 121.5654], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            mapInitialized = true;
        }
        
        // 取得篩選參數
        function getFilterParams() {
            const params = new URLSearchParams();
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            if (startDate) params.set('start_date', startDate);
            if (endDate) params.set('end_date', endDate);
            return params.toString();
        }
        
        // 套用篩選
        function applyFilter() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const deviceId = document.getElementById('deviceFilter').value;
            
            // 檢查是否有篩選條件
            if (!startDate && !endDate && !deviceId) {
                alert('請至少選擇一個篩選條件');
                return;
            }
            
            const params = getFilterParams();
            const url = params ? 'get_locations.php?' + params : 'get_locations.php';
            loadLocationsFromUrl(url);
            
            // 更新 URL
            const newUrl = params ? '?' + params : window.location.pathname;
            window.history.replaceState({}, '', newUrl);
        }
        
        // 清除篩選
        function clearFilter() {
            document.getElementById('startDate').value = '';
            document.getElementById('endDate').value = '';
            document.getElementById('deviceFilter').value = '';
            
            // 重置地圖為空白
            if (map) {
                map.remove();
                mapInitialized = false;
            }
            document.getElementById('map').innerHTML = '<div class="empty-state">請選擇篩選條件查看地圖</div>';
            
            // 重置表格
            document.getElementById('tableContainer').innerHTML = '<div class="empty-table">請選擇篩選條件查看記錄</div>';
            
            window.history.replaceState({}, '', window.location.pathname);
        }
        
        // 從 URL 載入位置資料
        async function loadLocationsFromUrl(url) {
            const response = await fetch(url);
            locations = await response.json();
            updateDeviceFilter();
            updateTableDeviceFilter();
            filterAndDisplay();
        }
        
        // 載入位置資料（不自動載入）
        async function loadLocations() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const deviceId = document.getElementById('deviceFilter').value;
            
            if (!startDate && !endDate && !deviceId) {
                // 無篩選條件時只更新下拉選單
                const response = await fetch('get_locations.php');
                locations = await response.json();
                updateDeviceFilter();
                updateTableDeviceFilter();
                return;
            }
            
            await loadLocationsFromUrl('get_locations.php');
        }
        
        // 載入資料填充下拉選單
        async function loadLocationsForDropdown() {
            const response = await fetch('get_locations.php');
            locations = await response.json();
            updateDeviceFilter();
            updateTableDeviceFilter();
        }
        
        // 更新地圖篩選下拉選單
        function updateDeviceFilter() {
            const deviceFilter = document.getElementById('deviceFilter');
            // 使用暱稱作為顯示，若無暱稱則顯示裝置 ID
            const deviceMap = {};
            locations.forEach(l => {
                const displayName = l.nickname || l.device_id.substring(0, 12);
                deviceMap[l.device_id] = displayName;
            });
            const devices = [...new Set(locations.map(l => l.device_id))];
            const currentValue = deviceFilter.value;
            deviceFilter.innerHTML = '<option value="">所有裝置</option>' + 
                devices.map(d => `<option value="${d}">${deviceMap[d]}</option>`).join('');
            deviceFilter.value = currentValue;
        }
        
        // 更新表格篩選下拉選單
        function updateTableDeviceFilter() {
            const deviceFilter = document.getElementById('tableDeviceFilter');
            const deviceMap = {};
            locations.forEach(l => {
                const displayName = l.nickname || l.device_id.substring(0, 12);
                deviceMap[l.device_id] = displayName;
            });
            const devices = [...new Set(locations.map(l => l.device_id))];
            const currentValue = deviceFilter.value;
            deviceFilter.innerHTML = '<option value="">所有</option>' + 
                devices.map(d => `<option value="${d}">${deviceMap[d]}</option>`).join('');
            deviceFilter.value = currentValue;
        }
        
        // 套用表格篩選
        function applyTableFilter() {
            filterAndDisplay();
        }
        
        // 解析時間戳記，處理多種格式
        function parseTimestamp(ts) {
            if (!ts) return null;
            // 處理不同格式: 
            // 2026-03-17T16:43:43
            // 2026-03-17T15:30:00+08:00
            // 2026-03-17T15:25:00 08:00
            // 2026-03-17T08:30:10.989861Z
            // 2026-03-17 15:25:00
            let dateStr = ts.replace('T', ' ').split(' ')[0]; // 取日期部分
            return dateStr;
        }
        
        function filterAndDisplay() {
            const deviceId = document.getElementById('deviceFilter').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const tableDeviceId = document.getElementById('tableDeviceFilter').value;
            
            let filtered = locations;
            
            // 篩選裝置（地圖）
            if (deviceId) {
                filtered = filtered.filter(l => l.device_id === deviceId);
            }
            
            // 篩選日期
            if (startDate) {
                filtered = filtered.filter(l => {
                    const locDate = parseTimestamp(l.timestamp);
                    return locDate >= startDate;
                });
            }
            if (endDate) {
                filtered = filtered.filter(l => {
                    const locDate = parseTimestamp(l.timestamp);
                    return locDate <= endDate;
                });
            }
            
            // 清除舊標記
            markers.forEach(m => map.removeLayer(m));
            markers = [];
            
            // 初始化地圖
            initMap();
            
            // 顯示新標記
            let lastLat, lastLng;
            filtered.slice(0, 50).forEach(loc => {
                const marker = L.marker([loc.latitude, loc.longitude])
                    .addTo(map)
                    .bindPopup(`
                        <b>時間：</b>${loc.timestamp}<br>
                        <b>暱稱：</b>${loc.nickname || '-'}<br>
                        <b>裝置：</b>${loc.device_id}<br>
                        <b>精準度：</b>${loc.accuracy}m
                    `);
                markers.push(marker);
                lastLat = loc.latitude;
                lastLng = loc.longitude;
            });
            
            // 移動視角
            if (lastLat && lastLng) {
                map.setView([lastLat, lastLng], 15);
            }
            
            // 更新表格（使用表格篩選條件）
            let tableFiltered = locations;
            const tableStartDate = document.getElementById('startDate').value;
            const tableEndDate = document.getElementById('endDate').value;
            
            if (tableDeviceId) {
                tableFiltered = tableFiltered.filter(l => l.device_id === tableDeviceId);
            }
            if (tableStartDate) {
                tableFiltered = tableFiltered.filter(l => {
                    const locDate = parseTimestamp(l.timestamp);
                    return locDate >= tableStartDate;
                });
            }
            if (tableEndDate) {
                tableFiltered = tableFiltered.filter(l => {
                    const locDate = parseTimestamp(l.timestamp);
                    return locDate <= tableEndDate;
                });
            }
            
            const tableContainer = document.getElementById('tableContainer');
            if (tableFiltered.length === 0) {
                tableContainer.innerHTML = '<div class="empty-table">查無記錄</div>';
            } else {
                tableContainer.innerHTML = `
                    <table id="locationTable">
                        <thead>
                            <tr>
                                <th>時間</th>
                                <th>暱稱</th>
                                <th>打卡</th>
                                <th>裝置</th>
                                <th>緯度</th>
                                <th>經度</th>
                                <th>精準度</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${tableFiltered.slice(0, 100).map(loc => `
                                <tr>
                                    <td>${loc.timestamp}</td>
                                    <td>${loc.nickname || '-'}</td>
                                    <td>${loc.check_in || '-'}</td>
                                    <td>${loc.device_id.substring(0, 12)}</td>
                                    <td>${loc.latitude.toFixed(6)}</td>
                                    <td>${loc.longitude.toFixed(6)}</td>
                                    <td>${loc.accuracy}m</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                    ${tableFiltered.length > 100 ? `<p style="margin-top:10px;color:#666;">顯示前 100 筆，總共 ${tableFiltered.length} 筆</p>` : ''}
                `;
            }
        }
        
        // 從 URL 載入篩選參數
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('start_date')) {
            document.getElementById('startDate').value = urlParams.get('start_date');
        }
        if (urlParams.has('end_date')) {
            document.getElementById('endDate').value = urlParams.get('end_date');
        }
        
        // 頁面載入時初始化
        // 預設載入最近10筆資料
        setTimeout(async function() {
            await loadLocationsForDropdown();
            // 預設顯示最近10筆
            locations = locations.slice(0, 10);
            filterAndDisplay();
        }, 100);
        
        document.getElementById('deviceFilter').addEventListener('change', function() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const deviceId = this.value;
            
            if (!startDate && !endDate && !deviceId) {
                clearFilter();
                return;
            }
            
            // 重新篩選
            const params = new URLSearchParams();
            if (startDate) params.set('start_date', startDate);
            if (endDate) params.set('end_date', endDate);
            if (deviceId) params.set('device_id', deviceId);
            
            loadLocationsFromUrl('get_locations.php?' + params.toString());
        });
    </script>
</body>
</html>
