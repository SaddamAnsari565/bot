<?php
// === BOT + ADMIN CONFIG ===
define('BOT_TOKEN', '8571344116:AAFFXltmqX2HLaKjwn88B7_56IZF9TuRcnA');
define('ADMIN_CHAT_ID', 808697013); // tumhara admin chat id
define('UPI_ID', '7210099290@ptaxis');   // tumhara UPI
define('BASE_URL', 'https://hsnvip.xyz/bot/'); // last me / zaroor

// Telegram API base URL
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

error_reporting(0);
ini_set('display_errors', 0);

// Telegram request via cURL
function botRequest($method, $params = [])
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => API_URL . $method,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $params,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// simple starts_with (PHP 7 compatible)
function starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}

// JSON helpers
function read_json_file($path)
{
    if (!file_exists($path)) return [];
    $txt = file_get_contents($path);
    if ($txt === '' || $txt === false) return [];
    $data = json_decode($txt, true);
    return is_array($data) ? $data : [];
}

function write_json_file($path, $data)
{
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// User state helpers (states.json)
function get_user_state($chat_id)
{
    $states = read_json_file(__DIR__ . '/states.json');
    return $states[$chat_id] ?? ['step' => null, 'data' => []];
}

function set_user_state($chat_id, $step = null, $data = [])
{
    $states = read_json_file(__DIR__ . '/states.json');
    $states[$chat_id] = [
        'step' => $step,
        'data' => $data,
    ];
    write_json_file(__DIR__ . '/states.json', $states);
}

function clear_user_state($chat_id)
{
    $states = read_json_file(__DIR__ . '/states.json');
    if (isset($states[$chat_id])) {
        unset($states[$chat_id]);
        write_json_file(__DIR__ . '/states.json', $states);
    }
}