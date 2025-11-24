<?php
error_reporting(0);
ini_set('display_errors', 0);

require __DIR__ . '/config.php';

$amount = floatval($_GET['amount'] ?? 0);
$user   = preg_replace('/[^A-Za-z0-9_\-]/', '', ($_GET['user_id'] ?? ''));
$chat   = $_GET['chat'] ?? '';

if ($amount <= 0 || !$chat) exit;

// UPI deep link (personal UPI OK)
$upi = "upi://pay?pa=" . UPI_ID . "&pn=Payment&am={$amount}&cu=INR&tn={$user}";

// high-quality QR
$api = "https://quickchart.io/qr?text=" . urlencode($upi) . "&size=550&margin=1&ecLevel=H";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$img = curl_exec($ch);
curl_close($ch);

if (!$img) {
    botRequest('sendMessage', [
        'chat_id' => $chat,
        'text'    => "⚠ QR generation failed, please try again."
    ]);
    exit;
}

// temp file → upload → delete
$tmp = __DIR__ . '/qr_' . time() . rand(100,999) . '.png';
file_put_contents($tmp, $img);

botRequest('sendPhoto', [
    'chat_id' => $chat,
    'photo'   => new CURLFile($tmp),
    'caption' => "🟢 Scan to Pay ₹{$amount}\nUPI: `" . UPI_ID . "`\n\nAfter payment send UTR & screenshot.",
    'parse_mode' => 'Markdown',
]);

unlink($tmp);
exit;