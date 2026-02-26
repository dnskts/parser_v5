<?php
/**
 * Страница логов отправки заказов в API 1С.
 * Отображает записи из logs/api_send.log в виде таблицы.
 */

require_once __DIR__ . '/core/ApiSender.php';

$configFile = __DIR__ . '/config/settings.json';
$settings = array();
if (file_exists($configFile)) {
    $content = file_get_contents($configFile);
    $settings = json_decode($content, true);
    if (!is_array($settings)) $settings = array();
}
$apiConfig = isset($settings['api']) ? $settings['api'] : array();
$apiLogFile = __DIR__ . '/logs/api_send.log';
$apiSender = new ApiSender($apiConfig, $apiLogFile);

// AJAX-запросы
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'get_logs') {
        $entries = $apiSender->getLogEntries(500);
        echo json_encode(array('success' => true, 'entries' => $entries), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['action'] === 'clear_logs') {
        $apiSender->clearLog();
        echo json_encode(array('success' => true, 'message' => 'Логи очищены'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_GET['action'] === 'get_settings') {
        echo json_encode(array(
            'success' => true,
            'enabled' => isset($apiConfig['enabled']) ? (bool)$apiConfig['enabled'] : false,
            'url'     => isset($apiConfig['url']) ? $apiConfig['url'] : ''
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(array('success' => false, 'message' => 'Неизвестное действие'), JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Parser — Логи отправки API</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .api-status{display:flex;align-items:center;gap:16px;margin-bottom:20px;padding:12px 16px;border-radius:8px;font-size:14px}
        .api-status--on{background:#e8f5e9;border:1px solid #4caf50;color:#2e7d32}
        .api-status--off{background:#fff3e0;border:1px solid #ff9800;color:#e65100}
        .api-status__dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
        .api-status--on .api-status__dot{background:#4caf50}
        .api-status--off .api-status__dot{background:#ff9800}
        .api-controls{display:flex;align-items:center;gap:12px;margin-bottom:16px}
        .api-count{color:#64748b;font-size:13px}
        .api-wrap{max-height:calc(100vh - 320px);overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px}
        .api-tbl{width:100%;border-collapse:collapse;font-size:13px}
        .api-tbl th{position:sticky;top:0;background:#1e293b;color:#fff;padding:8px 12px;text-align:left;font-weight:500;white-space:nowrap}
        .api-tbl td{padding:6px 12px;border-bottom:1px solid #e2e8f0;vertical-align:top}
        .api-tbl tr:hover{background:#f8fafc}
        .api-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-weight:600;font-size:11px;text-transform:uppercase}
        .api-badge--ok{background:#e8f5e9;color:#2e7d32}
        .api-badge--error{background:#ffebee;color:#c62828}
        .api-badge--skip{background:#fff3e0;color:#e65100}
        .api-badge--send{background:#e3f2fd;color:#1565c0}
        .api-http{font-family:monospace;font-weight:600}
        .api-http--ok{color:#2e7d32}
        .api-http--err{color:#c62828}
        .api-msg{max-width:600px;word-wrap:break-word;line-height:1.4}
        .api-file{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:#64748b}
        .api-resp{max-width:300px;max-height:60px;overflow:hidden;text-overflow:ellipsis;font-size:11px;font-family:monospace;color:#64748b;cursor:pointer}
        .api-resp:hover{max-height:none;overflow:visible;background:#f1f5f9}
        .api-empty{text-align:center;padding:40px;color:#94a3b8;font-size:15px}
    </style>
</head>
<body>
    <header class="header">
        <div class="header__content">
            <div class="header__top">
                <div>
                    <h1 class="header__title">XML Parser</h1>
                    <p class="header__subtitle">Логи отправки заказов в API 1С</p>
                </div>
                <nav class="nav">
                    <a href="index.php" class="nav__link">Панель управления</a>
                    <a href="data.php" class="nav__link">Обработанные заказы</a>
                    <a href="api_logs.php" class="nav__link nav__link--active">Логи API</a>
                    <a href="test.php" class="nav__link">Тесты</a>    
                </nav>
            </div>
        </div>
    </header>

    <main class="main">
        <section class="panel">
            <div id="api-status" class="api-status api-status--off">
                <div class="api-status__dot"></div>
                <span id="api-status-text">Загрузка...</span>
            </div>

            <div class="api-controls">
                <button id="btn-refresh" class="btn btn--primary btn--small">↻ Обновить</button>
                <button id="btn-clear" class="btn btn--danger btn--small">🗑 Очистить логи</button>
                <span id="api-count" class="api-count"></span>
            </div>

            <div class="api-wrap">
                <table class="api-tbl">
                    <thead>
                        <tr>
                            <th>Дата/время</th>
                            <th>Статус</th>
                            <th>HTTP</th>
                            <th>JSON-файл</th>
                            <th>Исходный XML</th>
                            <th>Пояснение</th>
                            <th>Ответ сервера</th>
                        </tr>
                    </thead>
                    <tbody id="api-body">
                        <tr><td colspan="7" class="api-empty">Загрузка...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p>XML Parser v5 — Система обработки файлов поставщиков</p>
    </footer>

    <script>
    (function(){
        var body=document.getElementById('api-body');
        var count=document.getElementById('api-count');
        var stBlock=document.getElementById('api-status');
        var stText=document.getElementById('api-status-text');

        function esc(s){
            if(!s)return'';
            var d=document.createElement('div');
            d.textContent=s;
            return d.innerHTML;
        }
        function badgeCls(s){
            s=(s||'').toUpperCase();
            if(s==='OK')return'api-badge--ok';
            if(s==='ERROR')return'api-badge--error';
            if(s==='SKIP')return'api-badge--skip';
            if(s==='SEND')return'api-badge--send';
            return'';
        }
        function httpCls(c){
            if(!c)return'';
            return c>=200&&c<300?'api-http--ok':'api-http--err';
        }
        function loadSettings(){
            fetch('api_logs.php?action=get_settings')
            .then(function(r){return r.json()})
            .then(function(d){
                if(d.enabled){
                    stBlock.className='api-status api-status--on';
                    stText.textContent='Отправка включена → '+(d.url||'URL не задан');
                }else{
                    stBlock.className='api-status api-status--off';
                    stText.textContent='Отправка отключена (api.enabled = false)';
                }
            })
            .catch(function(){stText.textContent='Ошибка загрузки настроек'});
        }
        function loadLogs(){
            fetch('api_logs.php?action=get_logs')
            .then(function(r){return r.json()})
            .then(function(d){
                if(!d.success||!d.entries||d.entries.length===0){
                    body.innerHTML='<tr><td colspan="7" class="api-empty">Логов пока нет. Записи появятся после обработки файлов.</td></tr>';
                    count.textContent='0 записей';
                    return;
                }
                count.textContent=d.entries.length+' записей';
                var h='';
                for(var i=0;i<d.entries.length;i++){
                    var e=d.entries[i];
                    h+='<tr>'
                        +'<td style="white-space:nowrap">'+esc(e.timestamp)+'</td>'
                        +'<td><span class="api-badge '+badgeCls(e.status)+'">'+esc(e.status)+'</span></td>'
                        +'<td class="api-http '+httpCls(e.http_code)+'">'+(e.http_code?e.http_code:'—')+'</td>'
                        +'<td class="api-file" title="'+esc(e.json_file)+'">'+esc(e.json_file)+'</td>'
                        +'<td class="api-file" title="'+esc(e.source_xml)+'">'+esc(e.source_xml)+'</td>'
                        +'<td class="api-msg">'+esc(e.message)+'</td>'
                        +'<td class="api-resp" title="Нажмите для раскрытия">'+esc(e.response||'')+'</td>'
                        +'</tr>';
                }
                body.innerHTML=h;
            })
            .catch(function(err){
                body.innerHTML='<tr><td colspan="7" class="api-empty">Ошибка: '+esc(err.message)+'</td></tr>';
            });
        }
        document.getElementById('btn-refresh').addEventListener('click',function(){loadLogs();loadSettings()});
        document.getElementById('btn-clear').addEventListener('click',function(){
            if(!confirm('Очистить все логи отправки API?'))return;
            fetch('api_logs.php?action=clear_logs').then(function(r){return r.json()}).then(function(){loadLogs()});
        });
        loadSettings();
        loadLogs();
        setInterval(loadLogs,10000);
    })();
    </script>
</body>
</html>
