<?php
// Пути к файлам
$log_file = $_SERVER['DOCUMENT_ROOT'] . '/var/www/vhosts/kaston.kz/logs/access_ssl_log';
$block_file = $_SERVER['DOCUMENT_ROOT'] . '/var/www/vhosts/kaston.kz/kaston.kz/blocked_ips.txt';
$possible_clients_file = $_SERVER['DOCUMENT_ROOT'] . '/var/www/vhosts/kaston.kz/kaston.kz/possible_clients.txt';

// Конфигурации
$statusCodesToBlock = [400, 403];
$exclude_ips = 
$modification_methods = ['POST', 'PUT', 'DELETE', 'PATCH'];
$bot_keywords = ['bot', 'crawl', 'spider', 'scanner', 'search', 'monitor', 'checker'];

// Проверка существования лог-файла
if (!file_exists($log_file)) {
    echo "❌ Ошибка: файл лога не найден.\n";
    exit;
}

// Чтение логов
$log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$ipsToBlock = [];
$possibleClients = [];

foreach ($log_lines as $line) {
    // Блокировка IP по признаку AH01797
    if (stripos($line, 'AH01797') !== false) {
        if (preg_match('/client denied by server configuration: .*client (\d{1,3}(?:\.\d{1,3}){3})/', $line, $match)) {
            $ip = $match[1];
            if (!in_array($ip, $exclude_ips)) {
                $ipsToBlock[$ip] = true;
            }
        }
        continue;
    }

    // Извлечение IP, метода, URL, статуса и user-agent из строки лога
    if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})\s.*?"(GET|POST|PUT|DELETE|PATCH|HEAD) ([^"]+)"\s(\d{3})\s[^"]*"([^"]*)"/', $line, $matches)) {
        [$full, $ip, $method, $url, $status, $userAgent] = $matches;
        $status = (int)$status;
        $userAgent = strtolower($userAgent);

        $isPhpRequest = stripos($url, '.php') !== false;
        $isPhpModification = $isPhpRequest && in_array($method, $modification_methods);
        $isRedirectPhp = stripos($url, 'redirect.php') !== false;

        // Проверка user-agent на бота
        $isBot = false;
        foreach ($bot_keywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                $isBot = true;
                break;
            }
        }

        if (!in_array($ip, $exclude_ips)) {
            if (
                in_array($status, $statusCodesToBlock) ||
                $isPhpRequest ||
                $isPhpModification ||
                $isRedirectPhp ||
                $isBot
            ) {
                $ipsToBlock[$ip] = true;
            } else {
                $possibleClients[$ip] = true;
            }
        }
    }
}

// Загружаем уже заблокированные IP
$alreadyBlocked = file_exists($block_file)
    ? array_unique(file($block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
    : [];

$newIpsToBlock = array_diff(array_keys($ipsToBlock), $alreadyBlocked);

// Запись новых блокировок
if (!empty($newIpsToBlock)) {
    file_put_contents($block_file, implode("\n", $newIpsToBlock) . "\n", FILE_APPEND);
    echo "✅ Заблокированы новые IP: " . implode(', ', $newIpsToBlock) . "\n";
} else {
    echo "✅ Новых IP для блокировки нет.\n";
}

// Проверка на ошибочную блокировку возможных клиентов
$mistakenClients = array_intersect(array_keys($possibleClients), $alreadyBlocked);
if (!empty($mistakenClients)) {
    file_put_contents($possible_clients_file, implode("\n", $mistakenClients));
    echo "⚠️ Возможные клиенты среди заблокированных IP: " . implode(', ', $mistakenClients) . "\n";

    // Разблокировка
    $updatedBlockList = array_diff($alreadyBlocked, $mistakenClients);
    file_put_contents($block_file, implode("\n", $updatedBlockList) . "\n");
    echo "🔓 Разблокированы IP: " . implode(', ', $mistakenClients) . "\n";
} else {
    echo "✅ Все заблокированные IP подозрительные.\n";
}
?>
