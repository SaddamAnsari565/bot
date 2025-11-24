<?php
// index.php — Admin Dashboard

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
session_start();

// ---------- LOGOUT ----------
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// helper: safely read JSON as array
function safe_read_json($path) {
    $data = read_json_file($path);
    return is_array($data) ? $data : [];
}

// ---------- LOGGED-IN AREA (DASHBOARD) ----------
if (isset($_SESSION['logged']) && $_SESSION['logged'] === true) {

    // which tab?
    $tab = $_GET['tab'] ?? 'withdrawals'; // withdrawals | users | transactions

    // common
    $perPage = 10;

    // ===== WITHDRAWALS DATA =====
    $withdrawalsRaw = safe_read_json(__DIR__ . '/withdrawals.json');
    // withdrawals.json is associative array of id => data
    $withdrawals = [];
    foreach ($withdrawalsRaw as $id => $w) {
        if (!is_array($w)) continue;
        $w['id'] = $id;
        $withdrawals[] = $w;
    }
    // newest first
    usort($withdrawals, function ($a, $b) {
        $ta = isset($a['timestamp']) ? strtotime($a['timestamp']) : 0;
        $tb = isset($b['timestamp']) ? strtotime($b['timestamp']) : 0;
        return $tb <=> $ta;
    });

    $wFilter = $_GET['wstatus'] ?? 'pending'; // default show pending in filter, but allows 'all'
    if (!in_array($wFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
        $wFilter = 'all';
    }

    $withdrawalsFiltered = array_filter($withdrawals, function ($w) use ($wFilter) {
        if ($wFilter === 'all') return true;
        $st = $w['status'] ?? '';
        return $st === $wFilter;
    });

    $wTotal = count($withdrawalsFiltered);
    $wPage  = max(1, (int)($_GET['wpage'] ?? 1));
    $wPages = max(1, (int)ceil($wTotal / $perPage));
    if ($wPage > $wPages) $wPage = $wPages;
    $wOffset = ($wPage - 1) * $perPage;
    $withdrawalsPage = array_slice($withdrawalsFiltered, $wOffset, $perPage);

    // ===== USERS DATA =====
    $usersRaw = safe_read_json(__DIR__ . '/user_id.json');
    $users = [];

    // Handle OBJECT format keyed by username (new format)
    if ($usersRaw && array_keys($usersRaw) !== range(0, count($usersRaw) - 1)) {
        foreach ($usersRaw as $username => $u) {
            if (!is_array($u)) continue;
            $u['username'] = $username;
            $users[] = $u;
        }
    } else {
        // Legacy ARRAY format with user_id, password
        foreach ($usersRaw as $u) {
            if (!is_array($u)) continue;
            $users[] = [
                'username'   => $u['user_id'] ?? '',
                'name'       => $u['name']   ?? '',
                'password'   => $u['password'] ?? '',
                'status'     => $u['status'] ?? 'approved',
                'used'       => $u['used'] ?? true,
                'chat_id'    => $u['chat_id'] ?? '',
                'created_at' => $u['created_at'] ?? '',
            ];
        }
    }

    // newest first by created_at if available
    usort($users, function ($a, $b) {
        $ta = isset($a['created_at']) ? strtotime($a['created_at']) : 0;
        $tb = isset($b['created_at']) ? strtotime($b['created_at']) : 0;
        return $tb <=> $ta;
    });

    $uFilter = $_GET['ustatus'] ?? 'pending';
    if (!in_array($uFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
        $uFilter = 'all';
    }

    $usersFiltered = array_filter($users, function ($u) use ($uFilter) {
        if ($uFilter === 'all') return true;
        $st = $u['status'] ?? 'approved';
        return $st === $uFilter;
    });

    $uTotal = count($usersFiltered);
    $uPage  = max(1, (int)($_GET['upage'] ?? 1));
    $uPages = max(1, (int)ceil($uTotal / $perPage));
    if ($uPage > $uPages) $uPage = $uPages;
    $uOffset = ($uPage - 1) * $perPage;
    $usersPage = array_slice($usersFiltered, $uOffset, $perPage);

    // ===== TRANSACTION DATA =====
    $paymentsRaw = safe_read_json(__DIR__ . '/payments.json');
    $payments = [];
    foreach ($paymentsRaw as $id => $p) {
        if (!is_array($p)) continue;
        $p['id'] = $id;
        $payments[] = $p;
    }

    // Total deposit (approved only)
    $totalDeposit = 0;
    $pendingDeposit = 0;
    foreach ($payments as $p) {
        $st = $p['status'] ?? '';
        $amt = (float)($p['amount'] ?? 0);
        if ($st === 'approved') $totalDeposit += $amt;
        if ($st === 'pending')  $pendingDeposit += $amt;
    }

    // Total withdrawal (approved only)
    $totalWithdrawal = 0;
    $pendingWithdrawal = 0;
    foreach ($withdrawals as $w) {
        $st = $w['status'] ?? '';
        $amt = (float)($w['amount'] ?? 0);
        if ($st === 'approved') $totalWithdrawal += $amt;
        if ($st === 'pending')  $pendingWithdrawal += $amt;
    }

    $netIncome = $totalDeposit - $totalWithdrawal;

    // Transaction log: merge deposits + withdrawals
    $logs = [];

    foreach ($payments as $p) {
        $logs[] = [
            'type'    => 'Deposit',
            'user_id' => $p['user_id'] ?? '',
            'amount'  => (float)($p['amount'] ?? 0),
            'status'  => $p['status'] ?? '',
            'time'    => $p['timestamp'] ?? '',
            'info'    => 'UTR: ' . ($p['utr'] ?? 'N/A'),
        ];
    }

    foreach ($withdrawals as $w) {
        $logs[] = [
            'type'    => 'Withdrawal',
            'user_id' => $w['user_id'] ?? '',
            'amount'  => (float)($w['amount'] ?? 0),
            'status'  => $w['status'] ?? '',
            'time'    => $w['timestamp'] ?? '',
            'info'    => 'Mode: ' . ($w['payment_mode'] ?? 'N/A'),
        ];
    }

    // sort logs newest first
    usort($logs, function ($a, $b) {
        $ta = isset($a['time']) ? strtotime($a['time']) : 0;
        $tb = isset($b['time']) ? strtotime($b['time']) : 0;
        return $tb <=> $ta;
    });

    $tTotal = count($logs);
    $tPage  = max(1, (int)($_GET['tpage'] ?? 1));
    $tPages = max(1, (int)ceil($tTotal / $perPage));
    if ($tPage > $tPages) $tPage = $tPages;
    $tOffset = ($tPage - 1) * $perPage;
    $logsPage = array_slice($logs, $tOffset, $perPage);

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <title>Admin Dashboard</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="min-h-screen bg-slate-950 text-slate-100">
        <!-- Top bar -->
        <header class="border-b border-slate-800 bg-slate-950/80 backdrop-blur sticky top-0 z-20">
            <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-sky-500 to-emerald-500 flex items-center justify-center text-white font-bold text-lg">
                        V
                    </div>
                    <div>
                        <h1 class="text-base md:text-lg font-semibold">VIP Admin Panel</h1>
                        <p class="text-[11px] text-slate-400">Monitor users, withdrawals & transactions.</p>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden sm:inline-flex px-3 py-1 rounded-full bg-slate-800 text-[11px] text-slate-300">
                        Logged in as <span class="ml-1 font-semibold text-sky-400"><?php echo htmlspecialchars($ADMIN_USERNAME); ?></span>
                    </span>
                    <a href="index.php?logout=1"
                       class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-medium bg-slate-800 text-slate-200 hover:bg-red-500 hover:text-white transition">
                        Logout
                    </a>
                </div>
            </div>
        </header>

        <main class="max-w-6xl mx-auto px-4 py-6 md:py-8 space-y-6">
            <!-- Tabs -->
            <div class="border border-slate-800 rounded-2xl bg-slate-900/60 backdrop-blur">
                <div class="border-b border-slate-800 flex overflow-x-auto">
                    <?php
                    $tabs = [
                        'withdrawals'  => 'Withdrawals',
                        'users'        => 'New Users',
                        'transactions' => 'Transactions',
                    ];
                    foreach ($tabs as $key => $label):
                        $active = ($tab === $key);
                    ?>
                        <a href="?tab=<?php echo $key; ?>"
                           class="flex-1 text-center px-3 py-2.5 text-xs md:text-sm font-medium border-b-2 transition
                           <?php echo $active
                               ? 'border-sky-400 text-sky-300 bg-slate-900'
                               : 'border-transparent text-slate-400 hover:text-slate-200 hover:bg-slate-900/60'; ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="p-4 md:p-6 space-y-6">
                    <!-- ===== TAB: WITHDRAWALS ===== -->
                    <?php if ($tab === 'withdrawals'): ?>
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold">Withdrawals</h2>
                                <p class="text-xs text-slate-400">
                                    Review and track all withdrawal requests coming from the bot.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <?php
                                $filters = [
                                    'all'      => 'All',
                                    'pending'  => 'Pending',
                                    'approved' => 'Approved',
                                    'rejected' => 'Rejected',
                                ];
                                foreach ($filters as $key => $label):
                                    $active = ($wFilter === $key);
                                    $url = '?tab=withdrawals&wstatus=' . $key;
                                ?>
                                    <a href="<?php echo $url; ?>"
                                       class="px-3 py-1.5 rounded-full border text-xs <?php echo $active
                                           ? 'border-sky-500 bg-sky-500/10 text-sky-300'
                                           : 'border-slate-700 bg-slate-900 text-slate-300 hover:border-slate-500'; ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="overflow-x-auto border border-slate-800 rounded-xl bg-slate-950/60">
                            <table class="min-w-full text-xs md:text-sm">
                                <thead class="bg-slate-900">
                                    <tr class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide">
                                        <th class="px-3 py-3">#</th>
                                        <th class="px-3 py-3">User ID</th>
                                        <th class="px-3 py-3">Name</th>
                                        <th class="px-3 py-3">Amount</th>
                                        <th class="px-3 py-3">Mode</th>
                                        <th class="px-3 py-3">Status</th>
                                        <th class="px-3 py-3">Time</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <?php if (empty($withdrawalsPage)): ?>
                                        <tr>
                                            <td colspan="7" class="px-3 py-6 text-center text-slate-500 text-xs">
                                                No records found for selected filter.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($withdrawalsPage as $i => $w): 
                                            $num      = $wOffset + $i + 1;
                                            $user_id  = $w['user_id'] ?? '-';
                                            $name     = $w['name'] ?? '-';
                                            $amount   = (float)($w['amount'] ?? 0);
                                            $mode     = $w['payment_mode'] ?? '-';
                                            $status   = $w['status'] ?? 'pending';
                                            $time     = $w['timestamp'] ?? '';
                                            $labelCls = 'bg-slate-800 text-slate-200';
                                            if ($status === 'approved') $labelCls = 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/40';
                                            elseif ($status === 'rejected') $labelCls = 'bg-red-500/10 text-red-300 border border-red-500/40';
                                            elseif ($status === 'pending') $labelCls = 'bg-amber-500/10 text-amber-300 border border-amber-500/40';
                                        ?>
                                            <tr class="hover:bg-slate-900/80">
                                                <td class="px-3 py-3 text-slate-400"><?php echo $num; ?></td>
                                                <td class="px-3 py-3 font-medium text-slate-100">
                                                    <?php echo htmlspecialchars($user_id); ?>
                                                </td>
                                                <td class="px-3 py-3 text-slate-300">
                                                    <?php echo htmlspecialchars($name); ?>
                                                </td>
                                                <td class="px-3 py-3 text-sky-300 font-semibold">
                                                    ₹<?php echo number_format($amount, 2); ?>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-slate-300">
                                                    <?php echo htmlspecialchars($mode); ?>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $labelCls; ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 text-[11px] text-slate-400">
                                                    <?php echo htmlspecialchars($time); ?>
                                                </td>
                                                <td class="px-3 py-3 text-right flex gap-2 justify-end">
    <?php if ($status === 'pending'): ?>
        <a href="action.php?type=wd_ok&id=<?php echo urlencode($w['id']); ?>"
           class="px-2.5 py-1 rounded-full bg-emerald-600 text-white text-[11px] hover:bg-emerald-700">
           Approve
        </a>
        <a href="action.php?type=wd_no&id=<?php echo urlencode($w['id']); ?>"
           class="px-2.5 py-1 rounded-full bg-red-600 text-white text-[11px] hover:bg-red-700">
           Reject
        </a>
    <?php else: ?>
        <span class="text-[11px] text-slate-500 italic">Completed</span>
    <?php endif; ?>
</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($wPages > 1): ?>
                            <div class="flex justify-between items-center mt-4 text-[11px] text-slate-400">
                                <span>Page <?php echo $wPage; ?> of <?php echo $wPages; ?> (<?php echo $wTotal; ?> records)</span>
                                <div class="flex gap-2">
                                    <?php if ($wPage > 1): ?>
                                        <a href="?tab=withdrawals&wstatus=<?php echo $wFilter; ?>&wpage=<?php echo $wPage - 1; ?>"
                                           class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                            Prev
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($wPage < $wPages): ?>
                                        <a href="?tab=withdrawals&wstatus=<?php echo $wFilter; ?>&wpage=<?php echo $wPage + 1; ?>"
                                           class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                            Next
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    <!-- ===== TAB: NEW USERS ===== -->
                    <?php elseif ($tab === 'users'): ?>
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold">New Users</h2>
                                <p class="text-xs text-slate-400">
                                    Track registrations coming from the bot with approval status.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2 text-xs">
                                <?php
                                $uFilters = [
                                    'all'      => 'All',
                                    'pending'  => 'Pending',
                                    'approved' => 'Approved',
                                    'rejected' => 'Rejected',
                                ];
                                foreach ($uFilters as $key => $label):
                                    $active = ($uFilter === $key);
                                    $url = '?tab=users&ustatus=' . $key;
                                ?>
                                    <a href="<?php echo $url; ?>"
                                       class="px-3 py-1.5 rounded-full border text-xs <?php echo $active
                                           ? 'border-sky-500 bg-sky-500/10 text-sky-300'
                                           : 'border-slate-700 bg-slate-900 text-slate-300 hover:border-slate-500'; ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="overflow-x-auto border border-slate-800 rounded-xl bg-slate-950/60">
                            <table class="min-w-full text-xs md:text-sm">
                                <thead class="bg-slate-900">
                                    <tr class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide">
                                        <th class="px-3 py-3">#</th>
                                        <th class="px-3 py-3">Name</th>
                                        <th class="px-3 py-3">Username</th>
                                        <th class="px-3 py-3">Temp Password</th>
                                        <th class="px-3 py-3">Status</th>
                                        <th class="px-3 py-3">Created</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800">
                                    <?php if (empty($usersPage)): ?>
                                        <tr>
                                            <td colspan="6" class="px-3 py-6 text-center text-slate-500 text-xs">
                                                No users for selected filter.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($usersPage as $i => $u): 
                                            $num      = $uOffset + $i + 1;
                                            $name     = $u['name'] ?? '-';
                                            $username = $u['username'] ?? '-';
                                            $pass     = $u['password'] ?? '-';
                                            $status   = $u['status'] ?? 'approved';
                                            $time     = $u['created_at'] ?? '';
                                            $used     = !empty($u['used']);
                                            $labelCls = 'bg-slate-800 text-slate-200';
                                            if ($status === 'approved') $labelCls = 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/40';
                                            elseif ($status === 'rejected') $labelCls = 'bg-red-500/10 text-red-300 border border-red-500/40';
                                            elseif ($status === 'pending') $labelCls = 'bg-amber-500/10 text-amber-300 border border-amber-500/40';
                                        ?>
                                            <tr class="hover:bg-slate-900/80">
                                                <td class="px-3 py-3 text-slate-400"><?php echo $num; ?></td>
                                                <td class="px-3 py-3 text-slate-100 font-medium">
                                                    <?php echo htmlspecialchars($name); ?>
                                                </td>
                                                <td class="px-3 py-3 text-slate-200">
                                                    <?php echo htmlspecialchars($username); ?>
                                                    <?php if ($used): ?>
                                                        <span class="ml-2 text-[10px] px-2 py-0.5 rounded-full bg-slate-800 text-slate-300">Used</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <span class="px-2.5 py-1 rounded-full bg-slate-900 text-slate-200 font-mono text-[11px]">
                                                        <?php echo htmlspecialchars($pass); ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3">
                                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $labelCls; ?>">
                                                        <?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 text-[11px] text-slate-400">
                                                    <?php echo htmlspecialchars($time); ?>
                                                </td>
                                                <td class="px-3 py-3 text-right flex gap-2 justify-end">
    <?php if ($status === 'pending'): ?>
        <a href="action.php?type=reg_ok&username=<?php echo urlencode($username); ?>"
           class="px-2.5 py-1 rounded-full bg-emerald-600 text-white text-[11px] hover:bg-emerald-700">
           Approve
        </a>
        <a href="action.php?type=reg_no&username=<?php echo urlencode($username); ?>"
           class="px-2.5 py-1 rounded-full bg-red-600 text-white text-[11px] hover:bg-red-700">
           Reject
        </a>
    <?php else: ?>
        <span class="text-[11px] text-slate-500 italic">Completed</span>
    <?php endif; ?>
</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($uPages > 1): ?>
                            <div class="flex justify-between items-center mt-4 text-[11px] text-slate-400">
                                <span>Page <?php echo $uPage; ?> of <?php echo $uPages; ?> (<?php echo $uTotal; ?> records)</span>
                                <div class="flex gap-2">
                                    <?php if ($uPage > 1): ?>
                                        <a href="?tab=users&ustatus=<?php echo $uFilter; ?>&upage=<?php echo $uPage - 1; ?>"
                                           class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                            Prev
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($uPage < $uPages): ?>
                                        <a href="?tab=users&ustatus=<?php echo $uFilter; ?>&upage=<?php echo $uPage + 1; ?>"
                                           class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                            Next
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                    <!-- ===== TAB: TRANSACTIONS ===== -->
                    <?php elseif ($tab === 'transactions'): ?>
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold">Transactions Overview</h2>
                                <p class="text-xs text-slate-400">
                                    Combined analytics of deposits and withdrawals from your bot.
                                </p>
                            </div>
                        </div>

                        <!-- Stats cards -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-3">
                            <div class="border border-slate-800 rounded-xl bg-gradient-to-br from-sky-500/15 to-slate-900 p-4">
                                <p class="text-[11px] text-slate-400 uppercase tracking-wide mb-1">Total Deposit</p>
                                <p class="text-xl font-semibold text-sky-300">
                                    ₹<?php echo number_format($totalDeposit, 2); ?>
                                </p>
                                <p class="text-[11px] text-slate-500 mt-1">Approved deposits only</p>
                            </div>

                            <div class="border border-slate-800 rounded-xl bg-gradient-to-br from-emerald-500/15 to-slate-900 p-4">
                                <p class="text-[11px] text-slate-400 uppercase tracking-wide mb-1">Total Withdrawal</p>
                                <p class="text-xl font-semibold text-emerald-300">
                                    ₹<?php echo number_format($totalWithdrawal, 2); ?>
                                </p>
                                <p class="text-[11px] text-slate-500 mt-1">Approved withdrawals only</p>
                            </div>

                            <div class="border border-slate-800 rounded-xl bg-gradient-to-br from-fuchsia-500/20 to-slate-900 p-4">
                                <p class="text-[11px] text-slate-400 uppercase tracking-wide mb-1">Net Income</p>
                                <p class="text-xl font-semibold <?php echo $netIncome >= 0 ? 'text-fuchsia-300' : 'text-red-300'; ?>">
                                    ₹<?php echo number_format($netIncome, 2); ?>
                                </p>
                                <p class="text-[11px] text-slate-500 mt-1">(Deposit − Withdrawal)</p>
                            </div>

                            <div class="border border-slate-800 rounded-xl bg-slate-900 p-4">
                                <p class="text-[11px] text-slate-400 uppercase tracking-wide mb-1">Pending</p>
                                <p class="text-xs text-slate-300">
                                    Deposit: <span class="text-amber-300 font-semibold">₹<?php echo number_format($pendingDeposit, 2); ?></span>
                                </p>
                                <p class="text-xs text-slate-300 mt-1">
                                    Withdrawal: <span class="text-amber-300 font-semibold">₹<?php echo number_format($pendingWithdrawal, 2); ?></span>
                                </p>
                            </div>
                        </div>

                        <!-- Timeline / Logs -->
                        <div class="mt-5">
                            <h3 class="text-sm font-semibold text-slate-200 mb-2">Recent Transaction Log</h3>
                            <div class="overflow-x-auto border border-slate-800 rounded-xl bg-slate-950/60">
                                <table class="min-w-full text-xs md:text-sm">
                                    <thead class="bg-slate-900">
                                        <tr class="text-left text-[11px] font-semibold text-slate-400 uppercase tracking-wide">
                                            <th class="px-3 py-3">#</th>
                                            <th class="px-3 py-3">Type</th>
                                            <th class="px-3 py-3">User ID</th>
                                            <th class="px-3 py-3">Amount</th>
                                            <th class="px-3 py-3">Status</th>
                                            <th class="px-3 py-3">Info</th>
                                            <th class="px-3 py-3">Time</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800">
                                        <?php if (empty($logsPage)): ?>
                                            <tr>
                                                <td colspan="7" class="px-3 py-6 text-center text-slate-500 text-xs">
                                                    No transaction logs available.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($logsPage as $i => $log):
                                                $num    = $tOffset + $i + 1;
                                                $type   = $log['type'];
                                                $uid    = $log['user_id'] ?? '-';
                                                $amount = $log['amount'] ?? 0;
                                                $status = $log['status'] ?? '';
                                                $info   = $log['info'] ?? '';
                                                $time   = $log['time'] ?? '';
                                                $typeCls = $type === 'Deposit'
                                                    ? 'bg-sky-500/10 text-sky-300 border border-sky-500/40'
                                                    : 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/40';

                                                $stCls = 'bg-slate-800 text-slate-200';
                                                if ($status === 'approved') $stCls = 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/40';
                                                elseif ($status === 'rejected') $stCls = 'bg-red-500/10 text-red-300 border border-red-500/40';
                                                elseif ($status === 'pending') $stCls = 'bg-amber-500/10 text-amber-300 border border-amber-500/40';
                                            ?>
                                                <tr class="hover:bg-slate-900/80">
                                                    <td class="px-3 py-3 text-slate-400"><?php echo $num; ?></td>
                                                    <td class="px-3 py-3">
                                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $typeCls; ?>">
                                                            <?php echo $type; ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-3 text-slate-100">
                                                        <?php echo htmlspecialchars($uid); ?>
                                                    </td>
                                                    <td class="px-3 py-3 text-sky-300 font-semibold">
                                                        ₹<?php echo number_format($amount, 2); ?>
                                                    </td>
                                                    <td class="px-3 py-3">
                                                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold <?php echo $stCls; ?>">
                                                            <?php echo ucfirst($status); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-3 py-3 text-[11px] text-slate-300">
                                                        <?php echo htmlspecialchars($info); ?>
                                                    </td>
                                                    <td class="px-3 py-3 text-[11px] text-slate-400">
                                                        <?php echo htmlspecialchars($time); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if ($tPages > 1): ?>
                                <div class="flex justify-between items-center mt-4 text-[11px] text-slate-400">
                                    <span>Page <?php echo $tPage; ?> of <?php echo $tPages; ?> (<?php echo $tTotal; ?> records)</span>
                                    <div class="flex gap-2">
                                        <?php if ($tPage > 1): ?>
                                            <a href="?tab=transactions&tpage=<?php echo $tPage - 1; ?>"
                                               class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                                Prev
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($tPage < $tPages): ?>
                                            <a href="?tab=transactions&tpage=<?php echo $tPage + 1; ?>"
                                               class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 hover:border-slate-500">
                                                Next
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

// ---------- LOGIN PAGE ----------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['u'], $_POST['p'])) {
    if ($_POST['u'] === $ADMIN_USERNAME && $_POST['p'] === $ADMIN_PASSWORD) {
        $_SESSION['logged'] = true;
        header("Location: index.php");
        exit;
    } else {
        $msg = "Incorrect details. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login | VIP Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="bg-slate-900/80 border border-slate-800 backdrop-blur rounded-2xl shadow-2xl p-6 md:p-8">
            <div class="mb-5 text-center">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-sky-500 to-emerald-500 text-white shadow-md mb-2">
                    <span class="text-2xl font-extrabold">V</span>
                </div>
                <h1 class="text-xl md:text-2xl font-bold text-slate-50">
                    VIP Admin Panel
                </h1>
                <p class="text-xs md:text-sm text-slate-400 mt-1">
                    Sign in to view withdrawals, users & transactions.
                </p>
            </div>

            <form method="post" class="space-y-4">
                <div class="space-y-1">
                    <label class="text-xs uppercase tracking-wide text-slate-400 font-semibold">
                        Username
                    </label>
                    <input
                        type="text"
                        name="u"
                        required
                        class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-900 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent text-sm"
                        placeholder="Enter admin username"
                    />
                </div>
                <div class="space-y-1">
                    <label class="text-xs uppercase tracking-wide text-slate-400 font-semibold">
                        Password
                    </label>
                    <input
                        type="password"
                        name="p"
                        required
                        class="w-full px-3 py-2 rounded-xl border border-slate-700 bg-slate-900 text-slate-100 focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-transparent text-sm"
                        placeholder="Enter password"
                    />
                </div>

                <?php if (!empty($msg)): ?>
                    <div class="text-xs text-red-400 mt-1">
                        <?php echo htmlspecialchars($msg); ?>
                    </div>
                <?php endif; ?>

                <button
                    type="submit"
                    class="w-full mt-2 inline-flex items-center justify-center gap-1 px-4 py-2.5 rounded-xl bg-gradient-to-r from-sky-500 to-emerald-500 text-white text-sm font-semibold shadow-md hover:shadow-lg hover:brightness-110 transition"
                >
                    Sign In
                </button>
            </form>
        </div>
    </div>
</body>
</html>