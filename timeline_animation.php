<?php
/**
 * GPS Tracker - 時間軸動畫頁面
 * 以動畫方式在地圖上依時間順序顯示軌跡點與線
 * 三大元件：地圖、日曆、時鐘
 */

// 啟用 Session
session_start();

// 驗證 Token
if (!isset($_SESSION['gps_token']) || $_SESSION['gps_token'] !== md5('gps123')) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS 時間軸動畫</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
            background: #1a1a2e; 
            color: #eee;
            min-height: 100vh;
        }
        a.back-link { 
            display: inline-block; 
            padding: 10px 20px;
            color: #667eea; 
            text-decoration: none; 
        }
        a.back-link:hover { text-decoration: underline; }
        
        .header { 
            padding: 10px 20px; 
            background: #16213e;
            border-bottom: 1px solid #0f3460;
        }
        .header h1 { 
            font-size: 20px; 
            color: #667eea;
        }
        
        /* 主佈局：地圖 + 控制面板 */
        .main-container {
            display: flex;
            height: calc(100vh - 60px);
        }
        
        /* 地圖區 */
        .map-container {
            flex: 1;
            position: relative;
        }
        #map { 
            height: 100%; 
            background: #0f3460;
        }
        
        /* 控制面板 */
        .control-panel {
            width: 350px;
            background: #16213e;
            border-left: 1px solid #0f3460;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        
        .panel-section {
            padding: 15px;
            border-bottom: 1px solid #0f3460;
        }
        .panel-section h3 {
            font-size: 14px;
            color: #667eea;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        /* 篩選條件 */
        .filter-group { margin-bottom: 12px; }
        .filter-group label {
            display: block;
            font-size: 12px;
            color: #888;
            margin-bottom: 5px;
        }
        .filter-group select,
        .filter-group input[type="text"],
        .filter-group input[type="number"] {
            width: 100%;
            padding: 10px;
            background: #0f3460;
            border: 1px solid #1a4a7a;
            border-radius: 6px;
            color: #eee;
            font-size: 14px;
        }
        .filter-group select:focus,
        .filter-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* 日曆 */
        .calendar-container {
            background: #0f3460;
            border-radius: 8px;
            padding: 10px;
        }
        .flatpickr-calendar {
            background: #0f3460 !important;
            border: 1px solid #1a4a7a !important;
            box-shadow: none !important;
        }
        .flatpickr-day {
            background: #16213e !important;
            border-color: #1a4a7a !important;
            color: #eee !important;
        }
        .flatpickr-day:hover {
            background: #667eea !important;
        }
        .flatpickr-day.selected {
            background: #667eea !important;
            border-color: #667eea !important;
        }
        .flatpickr-months .flatpickr-month,
        .flatpickr-current-month .flatpickr-monthDropdown-months,
        .flatpickr-current-month input.cur-year {
            color: #eee !important;
        }
        .flatpickr-weekday {
            color: #888 !important;
        }
        
        /* 時鐘 */
        .clock-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            padding: 15px;
            background: #0f3460;
            border-radius: 8px;
        }
        .time-display {
            font-family: 'Courier New', monospace;
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
            letter-spacing: 2px;
        }
        .time-display .colon {
            animation: blink 1s step-end infinite;
        }
        @keyframes blink {
            50% { opacity: 0; }
        }
        
        .time-range {
            font-size: 12px;
            color: #888;
            text-align: center;
            margin-top: 10px;
        }
        
        /* 播放控制 */
        .playback-controls {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 15px;
        }
        .playback-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            border: 2px solid #667eea;
            background: transparent;
            color: #667eea;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .playback-btn:hover {
            background: #667eea;
            color: #fff;
        }
        .playback-btn.primary {
            width: 60px;
            height: 60px;
            font-size: 24px;
            background: #667eea;
            color: #fff;
        }
        .playback-btn.primary:hover {
            background: #5568d3;
        }
        .playback-btn:disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        
        /* 進度條 */
        .progress-container {
            padding: 15px;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #0f3460;
            border-radius: 4px;
            overflow: hidden;
            cursor: pointer;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea, #764ba2);
            width: 0%;
            transition: width 0.1s linear;
        }
        .progress-time {
            display: flex;
            justify-content: space-between;
            margin-top: 5px;
            font-size: 11px;
            color: #888;
        }
        
        /* 地圖比例 */
        .scale-control {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .scale-control input[type="range"] {
            flex: 1;
            -webkit-appearance: none;
            background: #0f3460;
            height: 6px;
            border-radius: 3px;
        }
        .scale-control input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            background: #667eea;
            border-radius: 50%;
            cursor: pointer;
        }
        .scale-value {
            min-width: 40px;
            text-align: right;
            font-size: 12px;
            color: #667eea;
        }
        
        /* 統計資訊 */
        .stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .stat-item {
            background: #0f3460;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            font-size: 11px;
            color: #888;
            margin-top: 4px;
        }
        
        /* 狀態提示 */
        .status-message {
            padding: 10px;
            background: #0f3460;
            border-radius: 6px;
            text-align: center;
            font-size: 13px;
            color: #888;
        }
        .status-message.playing {
            color: #667eea;
        }
        
        /* 圖例 */
        .legend {
            display: flex;
            gap: 15px;
            font-size: 12px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        .legend-line {
            width: 20px;
            height: 3px;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="index.php" class="back-link">← 返回首頁</a>
        <h1>GPS 時間軸動畫</h1>
    </div>
    
    <div class="main-container">
        <div class="map-container">
            <div id="map"></div>
        </div>
        
        <div class="control-panel">
            <!-- 篩選條件 -->
            <div class="panel-section">
                <h3>篩選條件</h3>
                
                <div class="filter-group">
                    <label>暱稱</label>
                    <select id="nicknameFilter">
                        <option value="">所有裝置</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>起始日期</label>
                    <input type="text" id="startDate" placeholder="選擇起始日期">
                </div>
                
                <div class="filter-group">
                    <label>結束日期</label>
                    <input type="text" id="endDate" placeholder="選擇結束日期">
                </div>
                
                <div class="filter-group">
                    <label>地圖比例 (zoom)</label>
                    <div class="scale-control">
                        <input type="range" id="mapScale" min="5" max="18" value="15">
                        <span class="scale-value" id="scaleValue">15</span>
                    </div>
                </div>
                
                <button onclick="applyFilter()" style="
                    width: 100%;
                    padding: 12px;
                    background: #667eea;
                    color: white;
                    border: none;
                    border-radius: 6px;
                    font-size: 14px;
                    cursor: pointer;
                    margin-top: 5px;
                ">套用篩選</button>
            </div>
            
            <!-- 日曆 -->
            <div class="panel-section">
                <h3>日曆</h3>
                <div class="calendar-container">
                    <input type="text" id="calendarRange" placeholder="選擇日期範圍" readonly style="
                        width: 100%;
                        padding: 10px;
                        background: #16213e;
                        border: 1px solid #1a4a7a;
                        border-radius: 6px;
                        color: #eee;
                        font-size: 14px;
                    ">
                </div>
            </div>
            
            <!-- 時鐘 -->
            <div class="panel-section">
                <h3>時鐘</h3>
                <div class="clock-container">
                    <div class="time-display">
                        <span id="currentTime">00:00:00</span>
                    </div>
                </div>
                <div class="time-range">
                    <span id="timeRangeStart">--:--:--</span> ~ <span id="timeRangeEnd">--:--:--</span>
                </div>
            </div>
            
            <!-- 播放控制 -->
            <div class="panel-section">
                <h3>播放控制</h3>
                <div class="playback-controls">
                    <button class="playback-btn" id="stepBackBtn" title="倒退一步">⏮</button>
                    <button class="playback-btn primary" id="playPauseBtn" title="播放/暫停">▶</button>
                    <button class="playback-btn" id="stepForwardBtn" title="前進一步">⏭</button>
                </div>
                
                <div class="progress-container">
                    <div class="progress-bar" id="progressBar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div class="progress-time">
                        <span id="progressCurrent">0%</span>
                        <span id="progressTotal">100%</span>
                    </div>
                </div>
                
                <div class="status-message" id="statusMessage">
                    等待載入資料...
                </div>
            </div>
            
            <!-- 統計 -->
            <div class="panel-section">
                <h3>統計</h3>
                <div class="stats">
                    <div class="stat-item">
                        <div class="stat-value" id="totalPoints">0</div>
                        <div class="stat-label">總點數</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="currentIndex">0</div>
                        <div class="stat-label">當前</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="elapsedTime">00:00</div>
                        <div class="stat-label">已播放</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" id="totalDuration">00:00</div>
                        <div class="stat-label">總長度</div>
                    </div>
                </div>
            </div>
            
            <!-- 圖例 -->
            <div class="panel-section">
                <h3>圖例</h3>
                <div class="legend">
                    <div class="legend-item">
                        <div class="legend-dot" style="background: #667eea;"></div>
                        <span>位置點</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-line" style="background: linear-gradient(90deg, #667eea, #764ba2);"></div>
                        <span>軌跡線</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/zh.js"></script>
    <script>
        // 地圖初始化
        let map = L.map('map').setView([25.0330, 121.5654], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        
        // 狀態變數
        let allLocations = [];           // 所有原始資料
        let filteredLocations = [];      // 篩選後的資料（按時間排序）
        let currentIndex = 0;
        let isPlaying = false;
        let playInterval = null;
        let playbackSpeed = 500;         // 每點毫秒數
        let mapMarkers = [];              // 地圖上的標記
        let polyline = null;              // 軌跡線
        let routeCoords = [];             // 軌跡線座標
        
        // DOM 元素
        const playPauseBtn = document.getElementById('playPauseBtn');
        const stepBackBtn = document.getElementById('stepBackBtn');
        const stepForwardBtn = document.getElementById('stepForwardBtn');
        const progressBar = document.getElementById('progressBar');
        const progressFill = document.getElementById('progressFill');
        const progressCurrent = document.getElementById('progressCurrent');
        const progressTotal = document.getElementById('progressTotal');
        const currentTimeEl = document.getElementById('currentTime');
        const timeRangeStart = document.getElementById('timeRangeStart');
        const timeRangeEnd = document.getElementById('timeRangeEnd');
        const statusMessage = document.getElementById('statusMessage');
        const totalPointsEl = document.getElementById('totalPoints');
        const currentIndexEl = document.getElementById('currentIndex');
        const elapsedTimeEl = document.getElementById('elapsedTime');
        const totalDurationEl = document.getElementById('totalDuration');
        const scaleInput = document.getElementById('mapScale');
        const scaleValue = document.getElementById('scaleValue');
        
        // 初始化日曆
        const calendar = flatpickr("#calendarRange", {
            mode: "range",
            dateFormat: "Y-m-d",
            defaultDate: [new Date(), new Date()],
            locale: "zh",
            onChange: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2) {
                    document.getElementById('startDate').value = formatDate(selectedDates[0]);
                    document.getElementById('endDate').value = formatDate(selectedDates[1]);
                }
            }
        });
        
        // 個別日期選擇器
        flatpickr("#startDate", {
            dateFormat: "Y-m-d",
            locale: "zh",
            onChange: function(dateStr) {
                updateCalendarRange();
            }
        });
        
        flatpickr("#endDate", {
            dateFormat: "Y-m-d",
            locale: "zh",
            onChange: function(dateStr) {
                updateCalendarRange();
            }
        });
        
        function updateCalendarRange() {
            const start = document.getElementById('startDate').value;
            const end = document.getElementById('endDate').value;
            if (start && end) {
                calendar.setDate([start, end]);
            }
        }
        
        function formatDate(date) {
            return date.toISOString().split('T')[0];
        }
        
        // 地圖比例控制
        scaleInput.addEventListener('input', function() {
            scaleValue.textContent = this.value;
            map.setZoom(parseInt(this.value));
        });
        
        // 載入所有位置資料
        async function loadLocations() {
            try {
                const response = await fetch('get_locations.php');
                allLocations = await response.json();
                
                // 更新暱稱下拉選單
                updateNicknameFilter();
                
                statusMessage.textContent = `已載入 ${allLocations.length} 筆資料`;
            } catch (error) {
                statusMessage.textContent = '載入資料失敗';
                console.error(error);
            }
        }
        
        // 更新暱稱篩選下拉選單
        function updateNicknameFilter() {
            const nicknameMap = {};
            allLocations.forEach(loc => {
                const name = loc.nickname || loc.device_id.substring(0, 12);
                nicknameMap[loc.device_id] = name;
            });
            
            const devices = [...new Set(allLocations.map(l => l.device_id))];
            const select = document.getElementById('nicknameFilter');
            select.innerHTML = '<option value="">所有裝置</option>' + 
                devices.map(d => `<option value="${d}">${nicknameMap[d]}</option>`).join('');
        }
        
        // 解析時間戳
        function parseTimestamp(ts) {
            if (!ts) return null;
            // 處理多種格式
            const str = ts.replace('T', ' ').replace('+08:00', '').trim();
            return str.split(' ')[0]; // 取日期部分
        }
        
        function parseFullTimestamp(ts) {
            if (!ts) return null;
            const str = ts.replace('T', ' ').replace('+08:00', '').trim();
            // 嘗試解析完整時間
            const parts = str.split(' ');
            if (parts.length >= 2) {
                const dateParts = parts[0].split('-');
                const timeParts = parts[1].split(':');
                return new Date(
                    parseInt(dateParts[0]),
                    parseInt(dateParts[1]) - 1,
                    parseInt(dateParts[2]),
                    parseInt(timeParts[0]),
                    parseInt(timeParts[1]),
                    parseInt(timeParts[2] || 0)
                );
            }
            return new Date(str);
        }
        
        // 套用篩選
        function applyFilter() {
            const nickname = document.getElementById('nicknameFilter').value;
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            
            filteredLocations = allLocations.filter(loc => {
                // 暱稱篩選
                if (nickname && loc.device_id !== nickname) return false;
                
                // 日期篩選
                const locDate = parseTimestamp(loc.timestamp);
                if (startDate && locDate < startDate) return false;
                if (endDate && locDate > endDate) return false;
                
                return true;
            });
            
            // 按時間排序（由舊到新）
            filteredLocations.sort((a, b) => {
                const timeA = parseFullTimestamp(a.timestamp);
                const timeB = parseFullTimestamp(b.timestamp);
                return timeA - timeB;
            });
            
            if (filteredLocations.length === 0) {
                statusMessage.textContent = '查無符合條件的資料';
                clearMap();
                updateStats();
                return;
            }
            
            // 重置播放狀態
            stopPlayback();
            currentIndex = 0;
            
            // 更新時間範圍顯示
            const firstTime = parseFullTimestamp(filteredLocations[0].timestamp);
            const lastTime = parseFullTimestamp(filteredLocations[filteredLocations.length - 1].timestamp);
            timeRangeStart.textContent = formatTimeDisplay(firstTime);
            timeRangeEnd.textContent = formatTimeDisplay(lastTime);
            
            // 初始化地圖
            initMapRoute();
            
            // 更新統計
            updateStats();
            updateProgress();
            
            statusMessage.textContent = `已篩選 ${filteredLocations.length} 筆資料，點擊播放`;
            statusMessage.classList.add('playing');
            
            // 自動播放
            setTimeout(() => startPlayback(), 500);
        }
        
        function formatTimeDisplay(date) {
            if (!date) return '--:--:--';
            return date.toTimeString().split(' ')[0];
        }
        
        // 清除地圖
        function clearMap() {
            mapMarkers.forEach(m => map.removeLayer(m));
            mapMarkers = [];
            if (polyline) {
                map.removeLayer(polyline);
                polyline = null;
            }
            routeCoords = [];
        }
        
        // 初始化地圖路線
        function initMapRoute() {
            clearMap();
            
            if (filteredLocations.length === 0) return;
            
            // 加入所有點作為背景（淡化）
            filteredLocations.forEach((loc, idx) => {
                const circle = L.circleMarker([loc.latitude, loc.longitude], {
                    radius: 4,
                    fillColor: '#667eea',
                    fillOpacity: 0.2,
                    color: '#667eea',
                    weight: 1,
                    opacity: 0.3
                }).addTo(map);
                mapMarkers.push(circle);
            });
            
            // 移動視圖到起始點
            map.setView([filteredLocations[0].latitude, filteredLocations[0].longitude], parseInt(scaleInput.value));
            
            // 初始化軌跡線
            routeCoords = [];
            polyline = L.polyline([], {
                color: '#667eea',
                weight: 3,
                opacity: 0.8
            }).addTo(map);
        }
        
        // 繪製到指定索引
        function drawToIndex(idx) {
            if (idx < 0 || idx >= filteredLocations.length) return;
            
            // 清除之前的標記
            mapMarkers.forEach(m => map.removeLayer(m));
            mapMarkers = [];
            
            // 繪製所有點（當前之前為淡化）
            filteredLocations.forEach((loc, i) => {
                const isCurrent = i === idx;
                const isPast = i < idx;
                
                if (isCurrent) {
                    // 當前點
                    const marker = L.circleMarker([loc.latitude, loc.longitude], {
                        radius: 10,
                        fillColor: '#764ba2',
                        fillOpacity: 1,
                        color: '#fff',
                        weight: 3
                    }).addTo(map);
                    mapMarkers.push(marker);
                    
                    // 更新時鐘
                    const time = parseFullTimestamp(loc.timestamp);
                    currentTimeEl.innerHTML = formatTimeDisplay(time).replace(/:/g, '<span class="colon">:</span>');
                    
                    // 移動視圖
                    map.panTo([loc.latitude, loc.longitude], {animate: true});
                } else {
                    const circle = L.circleMarker([loc.latitude, loc.longitude], {
                        radius: isPast ? 6 : 4,
                        fillColor: isPast ? '#667eea' : '#667eea',
                        fillOpacity: isPast ? 0.8 : 0.3,
                        color: isPast ? '#667eea' : '#667eea',
                        weight: isPast ? 2 : 1,
                        opacity: isPast ? 1 : 0.3
                    }).addTo(map);
                    mapMarkers.push(circle);
                }
            });
            
            // 更新軌跡線
            routeCoords = filteredLocations.slice(0, idx + 1).map(loc => [loc.latitude, loc.longitude]);
            polyline.setLatLngs(routeCoords);
            
            // 更新時間顯示
            const time = parseFullTimestamp(filteredLocations[idx].timestamp);
            if (time) {
                const elapsed = (time - parseFullTimestamp(filteredLocations[0].timestamp)) / 1000;
                elapsedTimeEl.textContent = formatElapsed(elapsed);
            }
        }
        
        function formatElapsed(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
        }
        
        // 更新進度
        function updateProgress() {
            if (filteredLocations.length === 0) return;
            
            const percent = ((currentIndex + 1) / filteredLocations.length) * 100;
            progressFill.style.width = `${percent}%`;
            progressCurrent.textContent = `${Math.round(percent)}%`;
            currentIndexEl.textContent = currentIndex + 1;
            
            // 總時長
            const firstTime = parseFullTimestamp(filteredLocations[0].timestamp);
            const lastTime = parseFullTimestamp(filteredLocations[filteredLocations.length - 1].timestamp);
            if (firstTime && lastTime) {
                const duration = (lastTime - firstTime) / 1000;
                totalDurationEl.textContent = formatElapsed(duration);
            }
        }
        
        // 更新統計
        function updateStats() {
            totalPointsEl.textContent = filteredLocations.length;
            currentIndexEl.textContent = currentIndex;
        }
        
        // 播放控制
        function startPlayback() {
            if (filteredLocations.length === 0) return;
            isPlaying = true;
            playPauseBtn.textContent = '⏸';
            statusMessage.textContent = '播放中...';
            
            playInterval = setInterval(() => {
                currentIndex++;
                if (currentIndex >= filteredLocations.length) {
                    stopPlayback();
                    currentIndex = filteredLocations.length - 1;
                    return;
                }
                drawToIndex(currentIndex);
                updateProgress();
            }, playbackSpeed);
        }
        
        function stopPlayback() {
            isPlaying = false;
            playPauseBtn.textContent = '▶';
            if (playInterval) {
                clearInterval(playInterval);
                playInterval = null;
            }
        }
        
        function togglePlayback() {
            if (isPlaying) {
                stopPlayback();
            } else {
                if (currentIndex >= filteredLocations.length - 1) {
                    currentIndex = -1;
                }
                startPlayback();
            }
        }
        
        function stepBack() {
            if (filteredLocations.length === 0) return;
            stopPlayback();
            currentIndex = Math.max(0, currentIndex - 1);
            drawToIndex(currentIndex);
            updateProgress();
        }
        
        function stepForward() {
            if (filteredLocations.length === 0) return;
            stopPlayback();
            currentIndex = Math.min(filteredLocations.length - 1, currentIndex + 1);
            drawToIndex(currentIndex);
            updateProgress();
        }
        
        // 事件監聽
        playPauseBtn.addEventListener('click', togglePlayback);
        stepBackBtn.addEventListener('click', stepBack);
        stepForwardBtn.addEventListener('click', stepForward);
        
        progressBar.addEventListener('click', function(e) {
            if (filteredLocations.length === 0) return;
            const rect = this.getBoundingClientRect();
            const percent = (e.clientX - rect.left) / rect.width;
            currentIndex = Math.floor(percent * filteredLocations.length);
            currentIndex = Math.max(0, Math.min(filteredLocations.length - 1, currentIndex));
            drawToIndex(currentIndex);
            updateProgress();
        });
        
        // 鍵盤快捷鍵
        document.addEventListener('keydown', function(e) {
            switch(e.code) {
                case 'Space':
                    e.preventDefault();
                    togglePlayback();
                    break;
                case 'ArrowLeft':
                    stepBack();
                    break;
                case 'ArrowRight':
                    stepForward();
                    break;
            }
        });
        
        // 初始化
        loadLocations();
    </script>
</body>
</html>
