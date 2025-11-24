<?php
require __DIR__ . '/config.php';

// Request type
$type = $_GET['type'] ?? '';
$id   = $_GET['id'] ?? '';
$username = $_GET['username'] ?? '';

/* -------- WITHDRAWAL APPROVE / REJECT -------- */
if ($type === 'wd_ok' || $type === 'wd_no') {
    $withdrawals = read_json_file(__DIR__ . '/withdrawals.json');
    if (!isset($withdrawals[$id])) goto END;

    $w = $withdrawals[$id];
    $chat_id = $w['chat_id'];
    $amount  = $w['amount'];
    $user_id = $w['user_id'];

    $withdrawals[$id]['status'] = ($type === 'wd_ok') ? 'approved' : 'rejected';
    write_json_file(__DIR__ . '/withdrawals.json', $withdrawals);

    if ($type === 'wd_ok') {
        botRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🎉 *Withdrawal Approved*\n\nUser ID: `$user_id`\nAmount: ₹$amount\n\nMoney will be credited shortly.",
            'parse_mode' => 'Markdown'
        ]);
    } else {
        botRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ *Withdrawal Rejected*\n\nUser ID: `$user_id`\nAmount: ₹$amount\n\nKindly contact support.",
            'parse_mode' => 'Markdown'
        ]);
    }

    header("Location: index.php?tab=withdrawals");
    exit;
}

/* -------- REGISTRATION APPROVE / REJECT -------- */
if ($type === 'reg_ok' || $type === 'reg_no') {
    $users = read_json_file(__DIR__ . '/user_id.json');

    if (!isset($users[$username])) goto END;

    $user = $users[$username];
    $chat_id = $user['chat_id'];
    $password = $user['password'];
    $name = $user['name'];

    $users[$username]['status'] = ($type === 'reg_ok') ? 'approved' : 'rejected';
    write_json_file(__DIR__ . '/user_id.json', $users);

    if ($type === 'reg_ok') {
        botRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' =>
"🎉 *Registration Approved!*\n\nHello *$name*, your account has been verified.\n\n🔐 Login Details:\n👤 Username: *$username*\n🔑 Password: *$password*\n\n⚠️ After login, change your password from profile setting.\n\n🌐 Login URL:\nhttps://vipexch9.com/home",
            'parse_mode' => 'Markdown'
        ]);
    } else {
        botRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "⚠️ Your registration request has been rejected.\n\nFor more info, contact support.",
        ]);
    }

    header("Location: index.php?tab=users");
    exit;
}

END:
header("Location: index.php");
exit;