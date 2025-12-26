<?php
// ==================== КОНФИГУРАЦИЯ ====================
define('TIMEZONE', 'Asia/Almaty');
define('LOG_FILE', __DIR__ . "/log.txt");
define('STATE_FILE', __DIR__ . "/checklist_state.json");
define('RETRY_FILE', __DIR__ . "/retry_state.json");
define('BITRIX_WEBHOOK', 'https://bitrix24.kz/rest//');
define('TARGET_CHECKLIST_ITEM', 'Товар отгружен');
define('NOTIFY_USER_ID', 10611);
define('HOURS_THRESHOLD', 24);
define('MAX_RETRY_ATTEMPTS', 10);
define('RETRY_DELAY_MINUTES', 2);

date_default_timezone_set(TIMEZONE);



// ==================== ФУНКЦИЯ ЛОГИРОВАНИЯ ====================
function logMessage($message) {
    $logLine = "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND);
}

// ==================== ФУНКЦИИ ДЛЯ ПОВТОРНЫХ ПРОВЕРОК ====================
function loadRetryState() {
    if (!file_exists(RETRY_FILE)) {
        return [];
    }
    $content = file_get_contents(RETRY_FILE);
    if ($content === false) {
        logMessage("Failed to read retry state file");
        return [];
    }
    $state = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("JSON decode error for retry state file: " . json_last_error_msg());
        return [];
    }
    return is_array($state) ? $state : [];
}

function saveRetryState($state) {
    $result = file_put_contents(RETRY_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        logMessage("Failed to save retry state file");
    }
}

function shouldRetryCheck($dealId) {
    $retryState = loadRetryState();
    $retryKey = "deal_{$dealId}_retry";
    if (!isset($retryState[$retryKey])) {
        return true;
    }
    $lastCheck = $retryState[$retryKey]['last_check'];
    $attempts = $retryState[$retryKey]['attempts'];
    $minutesSinceLastCheck = (time() - $lastCheck) / 60;
    if ($attempts >= MAX_RETRY_ATTEMPTS) {
        logMessage("Max retry attempts reached for Deal $dealId");
        return false;
    }
    if ($minutesSinceLastCheck >= RETRY_DELAY_MINUTES) {
        return true;
    }
    logMessage("Retry check not needed yet for Deal $dealId. Minutes passed: " . round($minutesSinceLastCheck, 1));
    return false;
}

function updateRetryState($dealId, $hasTask) {
    $retryState = loadRetryState();
    $retryKey = "deal_{$dealId}_retry";
    if (!isset($retryState[$retryKey])) {
        $retryState[$retryKey] = [
            'attempts' => 1,
            'last_check' => time(),
            'has_task' => $hasTask
        ];
    } else {
        $retryState[$retryKey]['attempts']++;
        $retryState[$retryKey]['last_check'] = time();
        $retryState[$retryKey]['has_task'] = $hasTask;
    }
    saveRetryState($retryState);
    logMessage("Retry state updated for Deal $dealId. Attempt: " . $retryState[$retryKey]['attempts']);
}

// ==================== ЛОГИРОВАНИЕ ВХОДЯЩЕГО ЗАПРОСА ====================
$rawInput = file_get_contents('php://input');
logMessage("=== NEW REQUEST ===");
logMessage("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'N/A'));
logMessage("User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'));
logMessage("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'N/A'));
logMessage("Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
logMessage("GET: " . json_encode($_GET, JSON_UNESCAPED_UNICODE));
logMessage("POST: " . json_encode($_POST, JSON_UNESCAPED_UNICODE));
logMessage("INPUT: " . $rawInput);
logMessage("=== END REQUEST DATA ===");

// ==================== ФУНКЦИИ API ====================
function callBitrixAPI($method, $params = []) {
    $url = BITRIX_WEBHOOK . $method . '.json';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 30, // Увеличиваем таймаут
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logMessage("CURL Error for $method: $error");
        return null;
    }
    
    // Полное логирование ответа
    $logResponse = substr((string)$response, 0, 1000);
    if (strlen($response) > 1000) {
        $logResponse .= "... [truncated]";
    }
    logMessage("API $method response (HTTP $httpCode): " . $logResponse);

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("JSON decode error for $method: " . json_last_error_msg());
        return null;
    }
    if (isset($decoded['error'])) {
        $desc = $decoded['error_description'] ?? $decoded['error'] ?? 'Unknown error';
        logMessage("Bitrix API error for $method: " . $desc);
        return null;
    }
    return $decoded;
}

// ==================== ЗАГРУЗКА И СОХРАНЕНИЕ СОСТОЯНИЯ ====================
function loadChecklistState() {
    if (!file_exists(STATE_FILE)) {
        return [];
    }
    $content = file_get_contents(STATE_FILE);
    if ($content === false) {
        logMessage("Failed to read state file");
        return [];
    }
    $state = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage("JSON decode error for state file: " . json_last_error_msg());
        return [];
    }
    return is_array($state) ? $state : [];
}

function saveChecklistState($state) {
    $result = file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($result === false) {
        logMessage("Failed to save state file");
    }
}

// ==================== ПОЛУЧЕНИЕ DEAL ID ====================
$dealId = 0;

// 1. GET параметр (основной способ для вашего робота)
if (!$dealId && isset($_GET['ID']) && is_numeric($_GET['ID'])) {
    $dealId = (int)$_GET['ID'];
    logMessage("Found Deal ID from GET parameter: " . $dealId);
}

// 2. POST от робота Bitrix24 (альтернативный способ)
if (!$dealId && isset($_POST['document_id']) && is_array($_POST['document_id']) && isset($_POST['document_id'][2])) {
    if (preg_match('/DEAL_(\d+)/', $_POST['document_id'][2], $matches)) {
        $dealId = (int)$matches[1];
        logMessage("Found Deal ID from document_id: " . $dealId);
    }
}

if (!$dealId && !empty($rawInput)) {
    $incomingData = json_decode($rawInput, true);
    if (is_array($incomingData)) {
        if (!empty($incomingData['data']['dealId'])) {
            $dealId = (int)$incomingData['data']['dealId'];
        } elseif (!empty($incomingData['ID'])) {
            $dealId = (int)$incomingData['ID'];
        } elseif (!empty($incomingData['deal_id'])) {
            $dealId = (int)$incomingData['deal_id'];
        }
    }
}

if (empty($dealId) || $dealId <= 0) {
    logMessage("Error: Deal ID not provided or could not be determined");
    http_response_code(400);
    exit('Invalid Deal ID');
}

logMessage("Processing Deal ID: $dealId");

// ==================== ПРОВЕРКА НЕОБХОДИМОСТИ ПОВТОРА ====================
if (!shouldRetryCheck($dealId)) {
    logMessage("Skipping check for Deal $dealId - retry not needed or max attempts reached");
    echo "OK";
    exit;
}

// ==================== ПОИСК ЗАДАЧ С ЧЕК-ЛИСТАМИ ====================
logMessage("Searching for tasks with checklists in deal $dealId");

// Получаем все задачи, связанные со сделкой
$tasksResponse = callBitrixAPI('tasks.task.list', [
    'filter' => ['UF_CRM_TASK' => ['D_' . $dealId]],
    'select' => ['ID', 'TITLE', 'CREATED_DATE', 'STATUS']
]);

if (!$tasksResponse || !isset($tasksResponse['result'])) {
    logMessage("Error: Failed to get tasks for deal $dealId");
    updateRetryState($dealId, false);
    echo "OK";
    exit;
}

$allTasks = $tasksResponse['result']['tasks'] ?? [];
logMessage("Found " . count($allTasks) . " tasks in deal");

if (empty($allTasks)) {
    logMessage("No tasks found in deal $dealId");
    updateRetryState($dealId, false);
    echo "OK";
    exit;
}

// Логируем все задачи для отладки
foreach ($allTasks as $index => $task) {
    $taskId = $task['ID'] ?? $task['id'] ?? 'N/A';
    $taskTitle = $task['TITLE'] ?? $task['title'] ?? 'N/A';
    logMessage("Task #$index: ID=$taskId, Title='$taskTitle'");
}

// Ищем задачи с чек-листами, содержащими нужный пункт
$foundTask = null;
$foundChecklistItem = null;

foreach ($allTasks as $task) {
    $currentTaskId = (int)($task['ID'] ?? $task['id'] ?? 0);
    if ($currentTaskId === 0) continue;
    
    logMessage("Checking checklist for task ID: $currentTaskId");
    
    // Получаем чек-лист задачи
    $checklistResponse = callBitrixAPI('task.checklistitem.getlist', ['taskId' => $currentTaskId]);
    
    if (!$checklistResponse || !isset($checklistResponse['result'])) {
        logMessage("No checklist or API error for task $currentTaskId");
        continue;
    }
    
    $checklistItems = $checklistResponse['result'];
    logMessage("Task $currentTaskId has " . count($checklistItems) . " checklist items");
    
    // Ищем пункт "Товар отгружен"
    foreach ($checklistItems as $item) {
        $itemTitle = $item['TITLE'] ?? '';
        if ($itemTitle === TARGET_CHECKLIST_ITEM) {
            $foundTask = $task;
            $foundChecklistItem = $item;
            logMessage("FOUND: Task $currentTaskId has target checklist item: '$itemTitle'");
            break 2;
        }
    }
}

if (!$foundTask || !$foundChecklistItem) {
    logMessage("No tasks with '" . TARGET_CHECKLIST_ITEM . "' checklist item found in deal $dealId. Will retry later.");
    updateRetryState($dealId, false);
    echo "OK";
    exit;
}

// ==================== ОБРАБОТКА НАЙДЕННОЙ ЗАДАЧИ ====================
$taskId = (int)($foundTask['ID'] ?? $foundTask['id'] ?? 0);
$taskTitle = $foundTask['TITLE'] ?? $foundTask['title'] ?? 'Unknown';
$isComplete = ($foundChecklistItem['IS_COMPLETE'] ?? 'N') === 'Y';

logMessage("Processing task: ID=$taskId, Title='$taskTitle', ItemComplete=" . ($isComplete ? 'Yes' : 'No'));

// Помечаем что задача найдена
updateRetryState($dealId, true);

// ==================== ОПРЕДЕЛЕНИЕ ДАТЫ ДЛЯ ОТСЧЕТА ====================
$dateReference = null;

// 1. Пытаемся получить дату из самой задачи
$taskData = callBitrixAPI('tasks.task.get', ['taskId' => $taskId]);
if ($taskData && !empty($taskData['result']['task']['createdDate'])) {
    $dateReference = strtotime($taskData['result']['task']['createdDate']);
    logMessage("Using task creation date: " . $taskData['result']['task']['createdDate']);
}
// 2. Ищем дату в чек-листе
elseif (!empty($foundChecklistItem['CREATED_DATE'])) {
    $dateReference = strtotime($foundChecklistItem['CREATED_DATE']);
    logMessage("Using checklist item creation date: " . $foundChecklistItem['CREATED_DATE']);
}
// 3. Тестовый режим
elseif (isset($_GET['test'])) {
    $dateReference = time() - (25 * 3600);
    logMessage("Using test date (25 hours ago)");
}
// 4. Запасной вариант
else {
    $dateReference = time();
    logMessage("Using current time as reference");
}

// ВЫЧИСЛЯЕМ прошедшее время
$hoursSinceReference = (time() - $dateReference) / 3600;
logMessage("Reference date: " . date('Y-m-d H:i:s', $dateReference));
logMessage("Hours passed: " . round($hoursSinceReference, 2));

// ==================== ЛОГИКА УВЕДОМЛЕНИЯ ====================
$checklistState = loadChecklistState();
$notificationKey = "deal_{$dealId}_task_{$taskId}_notified";

if (!$isComplete && $hoursSinceReference >= HOURS_THRESHOLD) {
    if (empty($checklistState[$notificationKey])) {
        $dealLink = "https://.bitrix24.kz/crm/deal/details/{$dealId}/";
        $taskLink = "https://.bitrix24.kz/company/personal/user/0/tasks/task/view/{$taskId}/";
        
        $message = "⏰ Пункт чек-листа '" . TARGET_CHECKLIST_ITEM . "' в задаче <a href='{$taskLink}'>" . $taskTitle . "</a> "
                 . "(<a href='{$dealLink}'>Сделка #{$dealId}</a>) не выполнен спустя " 
                 . HOURS_THRESHOLD . " часа";

        $notifyResult = callBitrixAPI('im.notify', [
            "USER_ID" => NOTIFY_USER_ID,
            "MESSAGE" => $message,
            "NOTIFY_TYPE" => 1
        ]);
        
        if ($notifyResult) {
            logMessage("Notification sent successfully for Deal $dealId, Task $taskId");
            $checklistState[$notificationKey] = time();
            saveChecklistState($checklistState);
        } else {
            logMessage("Failed to send notification for Deal $dealId, Task $taskId");
        }
    } else {
        $lastNotification = date('Y-m-d H:i:s', $checklistState[$notificationKey]);
        logMessage("Notification for Deal $dealId, Task $taskId already sent on $lastNotification");
    }
}

logMessage("Processing completed for Deal $dealId");
echo "OK";
?>