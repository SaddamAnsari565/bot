<?php
error_reporting(0);
ini_set('display_errors', 0);
require __DIR__ . '/config.php';

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

if (isset($update['message'])) handle_msg($update['message']);
if (isset($update['callback_query'])) handle_cb($update['callback_query']);

/* ================= MESSAGE HANDLER ================= */
function handle_msg($m)
{
    $cid   = $m['chat']['id'];
    $txt   = $m['text'] ?? '';
    $state = get_user_state($cid);
    $step  = $state['step'];

    // screenshot (for payment proof / QR)
    if (isset($m['photo'])) {
        return handle_screenshot($m, $state);
    }

    // /start
    if ($txt === '/start') {
        clear_user_state($cid);
        return send_home($cid);
    }

    // main menu buttons
    if ($txt === 'Enter User ID') {
        set_user_state($cid, 'DEPO_WAIT_USER', []);
        return send_text($cid, "Send your User 🆔");
    }

    if ($txt === 'Create New User') {
        // start new registration flow
        set_user_state($cid, 'REG_WAIT_NAME', []);
        return send_text($cid, "Send your Full Name:");
    }

    if ($txt === 'Withdrawal') {
        set_user_state($cid, 'WITH_WAIT_USER', []);
        return send_text($cid, "Send your User 🆔");
    }

    if ($txt === 'Pending Payments' && $cid == ADMIN_CHAT_ID) {
        return show_pending_withdrawals($cid);
    }

    if ($txt === 'Pending Registrations' && $cid == ADMIN_CHAT_ID) {
        return show_pending_registrations($cid);
    }

    if ($txt === 'Back') {
        clear_user_state($cid);
        return send_home($cid);
    }

    // state machine
    switch ($step) {
        /* ===== DEPOSIT FLOW ===== */
        case 'DEPO_WAIT_USER':
            $state['data']['user_id'] = $txt;
            set_user_state($cid, 'DEPO_WAIT_AMOUNT', $state['data']);
            return send_amount_buttons($cid);

        case 'DEPO_WAIT_UTR':
            $state['data']['utr'] = $txt;
            set_user_state($cid, 'DEPO_WAIT_SS', $state['data']);
            return send_text($cid, "Now send payment screenshot.");

        case 'DEPO_WAIT_SS':
            // This case is handled by screenshot handler
            return send_text($cid, "Please send the payment screenshot.");

        /* ===== WITHDRAW FLOW (WITH NAME) ===== */
        case 'WITH_WAIT_USER':
            $state['data']['user_id'] = $txt;
            set_user_state($cid, 'WITH_WAIT_NAME', $state['data']);
            return send_text($cid, "Enter your Full Name");

        case 'WITH_WAIT_NAME':
            $state['data']['name'] = $txt;
            set_user_state($cid, 'WITH_WAIT_AMOUNT', $state['data']);
            return send_text($cid, "Enter withdrawal amount\n\n⚠️ Don't enter more than wallet balance, your ID may be terminated.");

        case 'WITH_WAIT_AMOUNT':
            $amount = floatval($txt);
            if ($amount <= 0) {
                return send_text($cid, "Enter valid amount.");
            }
            $state['data']['amount'] = $amount;
            set_user_state($cid, 'WITH_WAIT_MODE', $state['data']);
            return send_text($cid, "Enter payment mode (UPI ID or QR Code details)");

        case 'WITH_WAIT_MODE':
            $state['data']['payment_mode'] = $txt;
            send_withdraw_to_admin($cid, $state['data']);
            clear_user_state($cid);
            return send_text($cid, "Withdrawal request sent to admin.");

        /* ===== REGISTRATION FLOW ===== */
        case 'REG_WAIT_NAME':
            $state['data']['name'] = $txt;
            set_user_state($cid, 'REG_WAIT_USERNAME', $state['data']);
            return send_text($cid, "Send a Username (without spaces):");

        case 'REG_WAIT_USERNAME':
            $username = trim($txt);
            if ($username === '') {
                return send_text($cid, "Username cannot be empty. Send a valid Username:");
            }

            $users = read_json_file(__DIR__ . '/user_id.json');
            if (!is_array($users)) $users = [];

            // user_id.json is OBJECT keyed by username (Option B)
            if (isset($users[$username])) {
                return send_text($cid, "❌ Username already exists.\nSend a different Username:");
            }

            $state['data']['username'] = $username;
            set_user_state($cid, 'REG_WAIT_PASSWORD', $state['data']);
            return send_text($cid, "Create a Temporary Password:");

        case 'REG_WAIT_PASSWORD':
            $password = trim($txt);
            if ($password === '') {
                return send_text($cid, "Password cannot be empty. Send a Temporary Password:");
            }

            $data     = $state['data'];
            $name     = $data['name'] ?? '';
            $username = $data['username'] ?? '';

            $users = read_json_file(__DIR__ . '/user_id.json');
            if (!is_array($users)) $users = [];

            // double-check username still free
            if (isset($users[$username])) {
                return send_text($cid, "❌ Username already exists.\nSend a different Username:");
            }

            // Save new user to user_id.json (OBJECT format)
            $users[$username] = [
                'name'       => $name,
                'password'   => $password,
                'used'       => true,
                'status'     => 'pending',   // pending | approved | rejected
                'chat_id'    => $cid,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            write_json_file(__DIR__ . '/user_id.json', $users);

            clear_user_state($cid);

            // Send info to user
            $msg_user = "📝 Registration Request Submitted\n\n"
                . "Name: {$name}\n"
                . "Username: {$username}\n"
                . "Temporary Password: {$password}\n\n"
                . "Your registration is pending admin approval ✅\n\n"
                . "After approval:\n"
                . "1️⃣ Open https://vipexch9.com/home\n"
                . "2️⃣ Login with your Username & Temporary Password\n"
                . "3️⃣ Create your new password on the website.";
            send_text($cid, $msg_user);

            // Send to admin with Approve / Reject buttons
            $kb = [
                'inline_keyboard' => [
                    [
                        ['text' => '✅ Approve', 'callback_data' => 'reg_ok:' . $username],
                        ['text' => '❌ Reject',  'callback_data' => 'reg_no:' . $username],
                    ]
                ]
            ];

            $msg_admin = "🆕 New User Registration\n"
                . "Name: {$name}\n"
                . "Username: {$username}\n"
                . "Temporary Password: {$password}\n"
                . "Chat ID: {$cid}";
            botRequest('sendMessage', [
                'chat_id'      => ADMIN_CHAT_ID,
                'text'         => $msg_admin,
                'reply_markup' => json_encode($kb),
            ]);

            return;

        default:
            return send_text($cid, "Use menu buttons or type /start.");
    }
}

/* ================= CALLBACK HANDLER ================= */
function handle_cb($cb)
{
    $cid  = $cb['message']['chat']['id'];
    $data = $cb['data'];

    botRequest('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
    ]);

    // amount selection
    if (starts_with($data, 'amt:')) {
        $amount = floatval(substr($data, 4));
        $state  = get_user_state($cid);
        $user_id = $state['data']['user_id'] ?? '';

        $state['data']['amount'] = $amount;
        set_user_state($cid, 'DEPO_WAIT_UTR', $state['data']);

        // call qr.php to send QR to user
        $qr_url = BASE_URL . 'qr.php?amount=' . urlencode($amount) .
                  '&user_id=' . urlencode($user_id) .
                  '&chat=' . urlencode($cid);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $qr_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_exec($ch);
        curl_close($ch);

        send_text($cid, "After payment send UTR number.");
        return;
    }

    // deposit approve / reject
    if (starts_with($data, 'dep_ok:') || starts_with($data, 'dep_no:')) {
        list($action, $pid) = explode(':', $data, 2);
        $payments = read_json_file(__DIR__ . '/payments.json');
        if (!isset($payments[$pid])) return;

        $p       = $payments[$pid];
        $chat_id = $p['chat_id'];
        $amount  = $p['amount'];
        $user_id = $p['user_id'];

        if ($action === 'dep_ok') {
            $payments[$pid]['status'] = 'approved';
            send_text($chat_id, "✅ Your deposit ₹{$amount} (User {$user_id}) approved.");
        } else {
            $payments[$pid]['status'] = 'rejected';
            send_text($chat_id, "❌ Your deposit ₹{$amount} (User {$user_id}) rejected.");
        }
        write_json_file(__DIR__ . '/payments.json', $payments);
        return;
    }

    // withdraw approve / reject
    if (starts_with($data, 'wd_ok:') || starts_with($data, 'wd_no:')) {
        list($action, $chat_id, $amount, $user_id) = explode(':', $data, 4);

        $withdrawals = read_json_file(__DIR__ . '/withdrawals.json');
        if (!is_array($withdrawals)) $withdrawals = [];

        // Find and update the pending withdrawal
        foreach ($withdrawals as $id => &$withdrawal) {
            if ($withdrawal['chat_id'] == $chat_id && 
                $withdrawal['amount'] == $amount && 
                $withdrawal['user_id'] == $user_id && 
                $withdrawal['status'] == 'pending') {
                
                $withdrawal['status'] = $action === 'wd_ok' ? 'approved' : 'rejected';
                
                if ($action === 'wd_ok') {
                    send_text($chat_id, "✅ Withdrawal ₹{$amount} (User {$user_id}) approved.");
                } else {
                    send_text($chat_id, "❌ Withdrawal ₹{$amount} (User {$user_id}) rejected.");
                }
                break;
            }
        }
        unset($withdrawal); // break reference

        write_json_file(__DIR__ . '/withdrawals.json', $withdrawals);
        return;
    }

    // registration approve / reject
    if (starts_with($data, 'reg_ok:') || starts_with($data, 'reg_no:')) {
        list($action, $username) = explode(':', $data, 2);

        $users = read_json_file(__DIR__ . '/user_id.json');
        if (!is_array($users) || !isset($users[$username])) {
            return;
        }

        $user    = $users[$username];
        $chat_id = $user['chat_id'] ?? null;
        $name    = $user['name'] ?? '';
        $password = $user['password'] ?? '';

        if ($action === 'reg_ok') {
            $users[$username]['status'] = 'approved';
            write_json_file(__DIR__ . '/user_id.json', $users);

            if ($chat_id) {
                $msg = "🎉 Your registration has been approved!\n\n"
                    . "Now change your password here:\n"
                    . "https://vipexch9.com/home\n\n"
                    . "Login using:\n"
                    . "Username: {$username}\n"
                    . "Temporary Password: {$password}\n\n"
                    . "After login, create your new password on the website.";
                send_text($chat_id, $msg);
            }

            send_text(ADMIN_CHAT_ID, "✅ User registration approved: {$username} ({$name})");
        } else {
            $users[$username]['status'] = 'rejected';
            write_json_file(__DIR__ . '/user_id.json', $users);

            if ($chat_id) {
                $msg = "❌ Your registration has been rejected.\nContact support for help.";
                send_text($chat_id, $msg);
            }

            send_text(ADMIN_CHAT_ID, "❌ User registration rejected: {$username} ({$name})");
        }

        return;
    }

    // (OLD) create user pick from list - no longer used, but kept if needed
    if (starts_with($data, 'pick:')) {
        // legacy - not used in new flow
        return;
    }
}

/* ================= SCREENSHOT HANDLER ================= */
function handle_screenshot($m, $state)
{
    $cid   = $m['chat']['id'];
    $step  = $state['step'];
    $photo_arr = $m['photo'];
    $file  = end($photo_arr);
    $file_id = $file['file_id'];

    // Handle deposit screenshot
    if ($step === 'DEPO_WAIT_SS') {
        $data    = $state['data'];
        $user_id = $data['user_id'];
        $amount  = $data['amount'];
        $utr     = $data['utr'];

        // Save payment to payments.json
        $payments = read_json_file(__DIR__ . '/payments.json');
        if (!is_array($payments)) $payments = [];

        $payment_id = "pay_" . time() . "_" . $cid;
        $payments[$payment_id] = [
            'id'        => $payment_id,
            'chat_id'   => $cid,
            'user_id'   => $user_id,
            'amount'    => $amount,
            'utr'       => $utr,
            'file_id'   => $file_id,
            'status'    => 'pending',
            'timestamp' => date('Y-m-d H:i:s')
        ];

        write_json_file(__DIR__ . '/payments.json', $payments);

        // Send to admin
        $caption = "💰 Deposit Request\nUser ID: {$user_id}\nAmount: ₹{$amount}\nUTR: {$utr}\nChat ID: {$cid}";
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => 'dep_ok:' . $payment_id],
                    ['text' => '❌ Reject',  'callback_data' => 'dep_no:' . $payment_id],
                ]
            ]
        ];

        botRequest('sendPhoto', [
            'chat_id'      => ADMIN_CHAT_ID,
            'photo'        => $file_id,
            'caption'      => $caption,
            'reply_markup' => json_encode($kb),
        ]);

        clear_user_state($cid);
        return send_text($cid, "Deposit request sent to admin with screenshot.");
    }
    // Handle withdrawal screenshot (QR code)
    else if ($step === 'WITH_WAIT_MODE') {
        $data = $state['data'];
        $withdrawals = read_json_file(__DIR__ . '/withdrawals.json');
        if (!is_array($withdrawals)) $withdrawals = [];

        $withdrawal_id = "wd_" . time() . "_" . $cid;
        $withdrawals[$withdrawal_id] = [
            'id'           => $withdrawal_id,
            'chat_id'      => $cid,
            'user_id'      => $data['user_id'],
            'name'         => $data['name'] ?? '',
            'amount'       => $data['amount'],
            'payment_mode' => 'QR_CODE_IMAGE',
            'file_id'      => $file_id,
            'status'       => 'pending',
            'timestamp'    => date('Y-m-d H:i:s')
        ];

        write_json_file(__DIR__ . '/withdrawals.json', $withdrawals);

        // send to admin
        $caption = "💸 Withdrawal Request\n"
                 . "User ID: {$data['user_id']}\n"
                 . "Name: {$data['name']}\n"
                 . "Amount: ₹{$data['amount']}\n"
                 . "Payment Mode: QR Code Image\n"
                 . "Chat ID: {$cid}";
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => 'wd_ok:' . $cid . ':' . $data['amount'] . ':' . $data['user_id']],
                    ['text' => '❌ Reject',  'callback_data' => 'wd_no:' . $cid . ':' . $data['amount'] . ':' . $data['user_id']],
                ]
            ]
        ];

        botRequest('sendPhoto', [
            'chat_id'      => ADMIN_CHAT_ID,
            'photo'        => $file_id,
            'caption'      => $caption,
            'reply_markup' => json_encode($kb),
        ]);

        clear_user_state($cid);
        return send_text($cid, "Withdrawal request sent to admin with QR code.");
    }
    
    // Default case - user sent screenshot at wrong time
    return send_text($cid, "Start again with /start.");
}

/* ================= WITHDRAWAL TO ADMIN ================= */
function send_withdraw_to_admin($cid, $data)
{
    $user_id      = $data['user_id'];
    $name         = $data['name'] ?? '';
    $amount       = $data['amount'];
    $payment_mode = $data['payment_mode'];

    $withdrawals = read_json_file(__DIR__ . '/withdrawals.json');
    if (!is_array($withdrawals)) $withdrawals = [];

    $withdrawal_id = "wd_" . time() . "_" . $cid;
    $withdrawals[$withdrawal_id] = [
        'id'           => $withdrawal_id,
        'chat_id'      => $cid,
        'user_id'      => $user_id,
        'name'         => $name,
        'amount'       => $amount,
        'payment_mode' => $payment_mode,
        'status'       => 'pending',
        'timestamp'    => date('Y-m-d H:i:s')
    ];

    write_json_file(__DIR__ . '/withdrawals.json', $withdrawals);

    $kb = [
        'inline_keyboard' => [
            [
                ['text' => '✅ Approve', 'callback_data' => 'wd_ok:' . $cid . ':' . $amount . ':' . $user_id],
                ['text' => '❌ Reject',  'callback_data' => 'wd_no:' . $cid . ':' . $amount . ':' . $user_id],
            ]
        ]
    ];

    $txt = "💸 Withdrawal Request\n"
         . "User ID: {$user_id}\n"
         . "Name: {$name}\n"
         . "Amount: ₹{$amount}\n"
         . "Payment Mode: {$payment_mode}\n"
         . "Chat ID: {$cid}";
    botRequest('sendMessage', [
        'chat_id'      => ADMIN_CHAT_ID,
        'text'         => $txt,
        'reply_markup' => json_encode($kb),
    ]);
}

/* ================= SHOW PENDING WITHDRAWALS ================= */
function show_pending_withdrawals($admin_cid)
{
    $withdrawals = read_json_file(__DIR__ . '/withdrawals.json');
    if (!is_array($withdrawals) || empty($withdrawals)) {
        return send_text($admin_cid, "No withdrawal requests found.");
    }

    $pending = [];
    foreach ($withdrawals as $id => $w) {
        if ($w['status'] === 'pending') {
            $pending[] = $w;
        }
    }

    if (empty($pending)) {
        return send_text($admin_cid, "No pending withdrawal requests.");
    }

    foreach ($pending as $w) {
        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => 'wd_ok:' . $w['chat_id'] . ':' . $w['amount'] . ':' . $w['user_id']],
                    ['text' => '❌ Reject',  'callback_data' => 'wd_no:' . $w['chat_id'] . ':' . $w['amount'] . ':' . $w['user_id']],
                ]
            ]
        ];

        $txt = "💸 Pending Withdrawal\n"
             . "User ID: {$w['user_id']}\n"
             . "Name: {$w['name']}\n"
             . "Amount: ₹{$w['amount']}\n"
             . "Payment Mode: {$w['payment_mode']}\n"
             . "Chat ID: {$w['chat_id']}\n"
             . "Time: {$w['timestamp']}";

        // Check if it's a QR code withdrawal
        if (isset($w['file_id'])) {
            botRequest('sendPhoto', [
                'chat_id'      => $admin_cid,
                'photo'        => $w['file_id'],
                'caption'      => $txt,
                'reply_markup' => json_encode($kb),
            ]);
        } else {
            botRequest('sendMessage', [
                'chat_id'      => $admin_cid,
                'text'         => $txt,
                'reply_markup' => json_encode($kb),
            ]);
        }
    }
}

/* ================= SHOW PENDING REGISTRATIONS ================= */
function show_pending_registrations($admin_cid)
{
    $users = read_json_file(__DIR__ . '/user_id.json');
    if (!is_array($users) || empty($users)) {
        return send_text($admin_cid, "No registrations found.");
    }

    $has = false;
    foreach ($users as $username => $u) {
        $status = $u['status'] ?? 'pending';
        if ($status !== 'pending') continue;

        $has = true;
        $name     = $u['name'] ?? '';
        $password = $u['password'] ?? '';
        $chat_id  = $u['chat_id'] ?? 'N/A';
        $time     = $u['created_at'] ?? '';

        $kb = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => 'reg_ok:' . $username],
                    ['text' => '❌ Reject',  'callback_data' => 'reg_no:' . $username],
                ]
            ]
        ];

        $txt = "🕒 Pending Registration\n"
             . "Name: {$name}\n"
             . "Username: {$username}\n"
             . "Temporary Password: {$password}\n"
             . "Chat ID: {$chat_id}\n"
             . "Time: {$time}";

        botRequest('sendMessage', [
            'chat_id'      => $admin_cid,
            'text'         => $txt,
            'reply_markup' => json_encode($kb),
        ]);
    }

    if (!$has) {
        return send_text($admin_cid, "No pending registrations.");
    }
}

/* ================= HOME & UI HELPERS ================= */
function send_home($cid)
{
    $kb = [
        'keyboard' => [
            [
                ['text' => 'Enter User ID'],
                ['text' => 'Create New User'],
            ],
            [
                ['text' => 'Withdrawal'],
            ]
        ],
        'resize_keyboard'      => true,
        'one_time_keyboard'    => false,
    ];

    // Add "Pending Payments" + "Pending Registrations" buttons for admin only
    if ($cid == ADMIN_CHAT_ID) {
        $kb['keyboard'][] = [
            ['text' => 'Pending Payments'],
            ['text' => 'Pending Registrations'],
        ];
    }

    botRequest('sendMessage', [
        'chat_id'      => $cid,
        'text'         => "👋 Welcome\nChoose an option:",
        'reply_markup' => json_encode($kb),
    ]);
}

function send_amount_buttons($cid)
{
    $inline = [
        'inline_keyboard' => [
            [
                ['text' => '₹100',  'callback_data' => 'amt:100'],
                ['text' => '₹200',  'callback_data' => 'amt:200'],
                ['text' => '₹300',  'callback_data' => 'amt:300'],
            ],
            [
                ['text' => '₹500',  'callback_data' => 'amt:500'],
                ['text' => '₹1000', 'callback_data' => 'amt:1000'],
                ['text' => '₹5000', 'callback_data' => 'amt:5000'],
            ],
        ],
    ];

    botRequest('sendMessage', [
        'chat_id'      => $cid,
        'text'         => "Select deposit amount:",
        'reply_markup' => json_encode($inline),
    ]);
}

function send_text($cid, $txt)
{
    botRequest('sendMessage', [
        'chat_id' => $cid,
        'text'    => $txt,
    ]);
}