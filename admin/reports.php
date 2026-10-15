<?php
require_once 'auth_guard.php';
require_once '../shared/config.php';

// =========================================================================
// ENSURE ADMIN_LOGS TABLE EXISTS
// =========================================================================
$conn->query("
    CREATE TABLE IF NOT EXISTS admin_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        admin_user  VARCHAR(100) NOT NULL DEFAULT 'admin',
        action      VARCHAR(50)  NOT NULL,
        booking_ref VARCHAR(20)  DEFAULT NULL,
        detail      TEXT         DEFAULT NULL,
        ip_address  VARCHAR(45)  DEFAULT NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// =========================================================================
// DATE RANGE (default: last 30 days)
// =========================================================================
$range   = $_GET['range'] ?? '30';
$allowed = ['7', '30', '90', '365'];
if (!in_array($range, $allowed, true)) $range = '30';

$date_from = date('Y-m-d', strtotime("-{$range} days"));
$date_to   = date('Y-m-d');

// =========================================================================
// EXPORT CSV
// =========================================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_sql = "
        SELECT
            CONCAT('UTM-', LPAD(t.id, 4, '0')) AS booking_id,
            t.nama        AS customer_name,
            t.telefon     AS phone,
            t.pickup,
            t.dropoff,
            t.tarikh      AS ride_date,
            t.masa        AS ride_time,
            t.status,
            COALESCE(t.tambang, '')      AS fare_rm,
            COALESCE(t.cara_bayaran, '') AS payment_method,
            COALESCE(d.nama, '')         AS driver_name,
            COALESCE(d.no_plat, '')      AS driver_plate,
            t.created_at  AS booked_at,
            COALESCE(t.completed_at, '') AS completed_at
        FROM tempahan t
        LEFT JOIN drivers d ON t.driver_id = d.id
        WHERE t.tarikh BETWEEN ? AND ?
        ORDER BY t.tarikh DESC, t.masa DESC
    ";

    $stmt_exp = $conn->prepare($export_sql);
    $stmt_exp->bind_param('ss', $date_from, $date_to);
    $stmt_exp->execute();
    $result_exp = $stmt_exp->get_result();

    $filename = 'utmklride_bookings_' . $date_from . '_to_' . $date_to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8

    fputcsv($out, [
        'Booking ID', 'Customer Name', 'Phone', 'Pick-up', 'Drop-off',
        'Ride Date', 'Ride Time', 'Status', 'Fare (RM)', 'Payment Method',
        'Driver Name', 'Driver Plate', 'Booked At', 'Completed At'
    ]);

    while ($row_exp = $result_exp->fetch_assoc()) {
        fputcsv($out, $row_exp);
    }

    fclose($out);
    $stmt_exp->close();
    $conn->close();
    exit;
}

// =========================================================================
// STATS: SUMMARY CARDS
// =========================================================================
$stmt_summary = $conn->prepare("
    SELECT
        COUNT(*) AS total_bookings,
        SUM(CASE WHEN status = 'selesai' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'batal'   THEN 1 ELSE 0 END) AS cancelled,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status = 'selesai' THEN COALESCE(tambang,0) ELSE 0 END) AS total_revenue,
        SUM(CASE WHEN status = 'selesai' AND cara_bayaran = 'tunai' THEN COALESCE(tambang,0) ELSE 0 END) AS rev_cash,
        SUM(CASE WHEN status = 'selesai' AND cara_bayaran = 'qr'    THEN COALESCE(tambang,0) ELSE 0 END) AS rev_qr
    FROM tempahan
    WHERE tarikh BETWEEN ? AND ?
");
$stmt_summary->bind_param('ss', $date_from, $date_to);
$stmt_summary->execute();
$summary = $stmt_summary->get_result()->fetch_assoc();
$stmt_summary->close();

// =========================================================================
// CHART: DAILY TRIPS (last N days)
// =========================================================================
$stmt_daily = $conn->prepare("
    SELECT tarikh AS day, COUNT(*) AS cnt
    FROM tempahan
    WHERE tarikh BETWEEN ? AND ?
    GROUP BY tarikh
    ORDER BY tarikh ASC
");
$stmt_daily->bind_param('ss', $date_from, $date_to);
$stmt_daily->execute();
$res_daily = $stmt_daily->get_result();
$daily_data = [];
while ($r = $res_daily->fetch_assoc()) {
    $daily_data[$r['day']] = (int)$r['cnt'];
}
$stmt_daily->close();

// Fill all days (including zero-count days)
$chart_labels = [];
$chart_values = [];
$d = new DateTime($date_from);
$end = new DateTime($date_to);
while ($d <= $end) {
    $key = $d->format('Y-m-d');
    $chart_labels[] = $d->format('d/m');
    $chart_values[] = $daily_data[$key] ?? 0;
    $d->modify('+1 day');
}

// =========================================================================
// TOP DRIVERS
// =========================================================================
$stmt_drivers = $conn->prepare("
    SELECT d.nama, d.no_plat,
           COUNT(*) AS trips_done,
           SUM(COALESCE(t.tambang, 0)) AS revenue
    FROM tempahan t
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.status = 'selesai'
      AND t.tarikh BETWEEN ? AND ?
    GROUP BY d.id
    ORDER BY trips_done DESC
    LIMIT 10
");
$stmt_drivers->bind_param('ss', $date_from, $date_to);
$stmt_drivers->execute();
$top_drivers = $stmt_drivers->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_drivers->close();

// =========================================================================
// POPULAR ROUTES
// =========================================================================
$stmt_routes = $conn->prepare("
    SELECT pickup, dropoff, COUNT(*) AS cnt
    FROM tempahan
    WHERE status = 'selesai'
      AND tarikh BETWEEN ? AND ?
    GROUP BY pickup, dropoff
    ORDER BY cnt DESC
    LIMIT 5
");
$stmt_routes->bind_param('ss', $date_from, $date_to);
$stmt_routes->execute();
$top_routes = $stmt_routes->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_routes->close();

// =========================================================================
// AUDIT LOG (latest 50)
// =========================================================================
$audit_logs = [];
$res_audit = $conn->query("
    SELECT * FROM admin_logs
    ORDER BY created_at DESC
    LIMIT 50
");
if ($res_audit) {
    $audit_logs = $res_audit->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#111827">
    <title>Reports & Analytics — UTMKL Ride</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        /* ── Report-specific styles ── */
        .report-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:18px; margin-bottom:24px; }
        .report-card { background:#fff; border:1px solid var(--line); border-radius:var(--radius-md); padding:20px 22px; box-shadow:0 5px 15px rgba(16,24,40,.04); }
        .report-card .rc-label { font-size:10px; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.9px; }
        .report-card .rc-value { font-size:28px; font-weight:700; color:var(--text); margin:4px 0 2px; line-height:1.2; }
        .report-card .rc-sub { font-size:10px; color:#98a2b3; }
        .chart-panel { background:#fff; border:1px solid var(--line); border-radius:var(--radius-lg); padding:24px; box-shadow:0 5px 15px rgba(16,24,40,.04); margin-bottom:24px; }
        .chart-panel h3 { font-size:15px; font-weight:700; margin-bottom:18px; }
        .table-panel { background:#fff; border:1px solid var(--line); border-radius:var(--radius-lg); overflow:hidden; box-shadow:0 5px 15px rgba(16,24,40,.04); margin-bottom:24px; }
        .table-panel-head { padding:18px 22px; border-bottom:1px solid var(--line); display:flex; align-items:center; justify-content:space-between; }
        .table-panel-head h3 { font-size:15px; font-weight:700; }
        .rpt-table { width:100%; border-collapse:collapse; font-size:12px; }
        .rpt-table th { padding:11px 16px; background:var(--surface-soft); color:var(--muted); font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.7px; text-align:left; border-bottom:1px solid var(--line); }
        .rpt-table td { padding:11px 16px; border-bottom:1px solid #f1f5f9; }
        .rpt-table tr:last-child td { border-bottom:none; }
        .rpt-table tr:hover td { background:#fafbff; }
        .badge-action { display:inline-block; padding:3px 9px; border-radius:999px; font-size:9px; font-weight:700; }
        .badge-confirm { background:#dcfce7; color:#166534; }
        .badge-cancel  { background:#fef2f2; color:#dc2626; }
        .badge-assign  { background:#eff6ff; color:#1d4ed8; }
        .badge-unassign{ background:#fff7ed; color:#c2410c; }
        .badge-status  { background:#f5f3ff; color:#6d28d9; }
        .badge-selesai { background:#f0fdf4; color:#15803d; }
        .badge-other   { background:#f8fafc; color:#475467; }
        .range-tabs { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
        .range-tab { padding:7px 14px; border-radius:9px; border:1px solid var(--line); background:#fff; font-size:11px; font-weight:600; color:var(--muted); text-decoration:none; transition:.15s; }
        .range-tab:hover { background:var(--blue-soft); color:var(--blue); border-color:#bfdbfe; }
        .range-tab.active { background:var(--blue); color:#fff; border-color:var(--blue); }
        .export-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 16px; background:#16a34a; color:#fff; border-radius:10px; font-size:11px; font-weight:700; text-decoration:none; transition:.15s; }
        .export-btn:hover { background:#15803d; transform:translateY(-1px); }
        .empty-row td { text-align:center; color:#98a2b3; padding:28px; font-size:12px; }
        .revenue-split { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:14px; }
        .rev-pill { background:var(--surface-soft); border:1px solid var(--line); border-radius:11px; padding:13px 15px; text-align:center; }
        .rev-pill .rp-label { font-size:9px; color:var(--muted); font-weight:600; text-transform:uppercase; }
        .rev-pill .rp-value { font-size:18px; font-weight:800; margin-top:3px; }
        .rp-cash { color:#16a34a; }
        .rp-qr   { color:#2563eb; }
        .log-ip  { font-size:9px; color:#98a2b3; }
        @media(max-width:768px){
            .report-grid { grid-template-columns:1fr 1fr; }
            .revenue-split { grid-template-columns:1fr 1fr; }
        }
    </style>
</head>
<body>

<div class="app-shell">
    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="brand">
            <div class="brand-logo">🚕</div>
            <div>
                <strong>UTMKL RIDE</strong>
                <span>Superadmin Console</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="index.php" class="nav-item">
                <span class="nav-icon">▦</span>
                <span>Operations</span>
            </a>
            <a href="index.php#fleet-monitor" class="nav-item">
                <span class="nav-icon">👨‍✈️</span>
                <span>Fleet Monitor</span>
            </a>
            <a href="index.php#bookings" class="nav-item">
                <span class="nav-icon">◫</span>
                <span>Active Bookings</span>
            </a>
            <a href="index.php#driver-notice" class="nav-item">
                <span class="nav-icon">✦</span>
                <span>Customer Notice</span>
            </a>
            <a href="drivers.php" class="nav-item">
                <span class="nav-icon">👨‍💼</span>
                <span>Manage Drivers</span>
            </a>
            <a href="reports.php" class="nav-item active">
                <span class="nav-icon">📊</span>
                <span>Reports</span>
            </a>
            <a href="settings.php" class="nav-item">
                <span class="nav-icon">⚙️</span>
                <span>Settings & Telegram</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <span class="pulse"></span>
            Reports &amp; Analytics
            &nbsp;|&nbsp;
            <a href="logout.php" style="color:#f87171;text-decoration:none;font-size:11px;" onclick="return confirm('Log out from superadmin?');">🚪 Logout</a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <button class="mobile-menu" type="button" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
                <div>
                    <p class="eyebrow">SUPERADMIN ANALYTICS</p>
                    <h1>Reports & Statistics</h1>
                    <p class="subtitle">Trip summary, revenue, top drivers, and audit trail.</p>
                </div>
            </div>
            <div class="topbar-actions">
                <a href="?range=<?= $range ?>&export=csv" class="export-btn" title="Export current date range to CSV">
                    ⬇ Export CSV
                </a>
                <a href="index.php" class="btn-refresh">← Back</a>
            </div>
        </header>

        <!-- DATE RANGE TABS -->
        <div class="range-tabs">
            <a href="?range=7"   class="range-tab <?= $range === '7'   ? 'active' : '' ?>">Last 7 days</a>
            <a href="?range=30"  class="range-tab <?= $range === '30'  ? 'active' : '' ?>">Last 30 days</a>
            <a href="?range=90"  class="range-tab <?= $range === '90'  ? 'active' : '' ?>">Last 90 days</a>
            <a href="?range=365" class="range-tab <?= $range === '365' ? 'active' : '' ?>">Last 12 months</a>
            <span style="font-size:11px;color:var(--muted);align-self:center;padding-left:4px;">
                <?= date('d M Y', strtotime($date_from)) ?> — <?= date('d M Y', strtotime($date_to)) ?>
            </span>
        </div>

        <!-- SUMMARY CARDS -->
        <div class="report-grid">
            <div class="report-card">
                <div class="rc-label">Total Bookings</div>
                <div class="rc-value"><?= number_format($summary['total_bookings']); ?></div>
                <div class="rc-sub">All statuses in period</div>
            </div>
            <div class="report-card">
                <div class="rc-label">Completed Rides</div>
                <div class="rc-value" style="color:#16a34a;"><?= number_format($summary['completed']); ?></div>
                <div class="rc-sub"><?= $summary['total_bookings'] > 0 ? round($summary['completed'] / $summary['total_bookings'] * 100) : 0 ?>% completion rate</div>
            </div>
            <div class="report-card">
                <div class="rc-label">Cancelled Rides</div>
                <div class="rc-value" style="color:#dc2626;"><?= number_format($summary['cancelled']); ?></div>
                <div class="rc-sub"><?= $summary['total_bookings'] > 0 ? round($summary['cancelled'] / $summary['total_bookings'] * 100) : 0 ?>% cancellation rate</div>
            </div>
            <div class="report-card">
                <div class="rc-label">Pending / Unresolved</div>
                <div class="rc-value" style="color:#d97706;"><?= number_format($summary['pending']); ?></div>
                <div class="rc-sub">Awaiting driver or completion</div>
            </div>
            <div class="report-card" style="grid-column: span 1;">
                <div class="rc-label">Total Revenue</div>
                <div class="rc-value" style="color:#7c3aed;">RM <?= number_format((float)$summary['total_revenue'], 2); ?></div>
                <div class="rc-sub">From completed rides with fare recorded</div>
                <div class="revenue-split">
                    <div class="rev-pill">
                        <div class="rp-label">💵 Cash</div>
                        <div class="rp-value rp-cash">RM <?= number_format((float)$summary['rev_cash'], 2); ?></div>
                    </div>
                    <div class="rev-pill">
                        <div class="rp-label">📲 QR</div>
                        <div class="rp-value rp-qr">RM <?= number_format((float)$summary['rev_qr'], 2); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DAILY CHART -->
        <div class="chart-panel">
            <h3>📈 Daily Bookings — Last <?= $range ?> Days</h3>
            <canvas id="dailyChart" height="<?= $range > 30 ? 80 : 100 ?>"></canvas>
        </div>

        <!-- TOP DRIVERS -->
        <div class="table-panel">
            <div class="table-panel-head">
                <h3>🏆 Top Drivers</h3>
                <span style="font-size:11px;color:var(--muted);">By completed trips in period</span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Driver</th>
                        <th>Plate</th>
                        <th>Trips Done</th>
                        <th>Revenue (RM)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_drivers)): ?>
                        <tr class="empty-row"><td colspan="5">No completed trips recorded in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($top_drivers as $i => $dr): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--muted);"><?= $i + 1 ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($dr['nama']); ?></td>
                            <td><?= htmlspecialchars($dr['no_plat'] ?: '—'); ?></td>
                            <td><strong><?= $dr['trips_done']; ?></strong></td>
                            <td style="color:#16a34a;font-weight:700;">RM <?= number_format((float)$dr['revenue'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- POPULAR ROUTES -->
        <div class="table-panel">
            <div class="table-panel-head">
                <h3>📍 Popular Routes</h3>
                <span style="font-size:11px;color:var(--muted);">Top 5 completed routes in period</span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Pick-up</th>
                        <th>Drop-off</th>
                        <th>Trips</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($top_routes)): ?>
                        <tr class="empty-row"><td colspan="4">No route data in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($top_routes as $ri => $rt): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--muted);"><?= $ri + 1 ?></td>
                            <td><?= htmlspecialchars($rt['pickup']); ?></td>
                            <td><?= htmlspecialchars($rt['dropoff']); ?></td>
                            <td><strong><?= $rt['cnt']; ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- AUDIT LOG -->
        <div class="table-panel">
            <div class="table-panel-head">
                <h3>🗒️ Admin Audit Log</h3>
                <span style="font-size:11px;color:var(--muted);">Last 50 admin actions</span>
            </div>
            <table class="rpt-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Admin</th>
                        <th>Action</th>
                        <th>Booking</th>
                        <th>Detail</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_logs)): ?>
                        <tr class="empty-row"><td colspan="6">No audit records yet. Actions (confirm, cancel, assign) will appear here.</td></tr>
                    <?php else: ?>
                        <?php foreach ($audit_logs as $log): ?>
                            <?php
                            $action_class = match(true) {
                                str_contains($log['action'], 'confirm') => 'badge-confirm',
                                str_contains($log['action'], 'cancel')  => 'badge-cancel',
                                str_contains($log['action'], 'assign')  => 'badge-assign',
                                str_contains($log['action'], 'unassign')=> 'badge-unassign',
                                str_contains($log['action'], 'selesai') => 'badge-selesai',
                                str_contains($log['action'], 'status')  => 'badge-status',
                                default                                  => 'badge-other',
                            };
                            ?>
                            <tr>
                                <td style="white-space:nowrap;">
                                    <?= date('d/m/y', strtotime($log['created_at'])); ?><br>
                                    <span style="font-size:9px;color:#98a2b3;"><?= date('h:i A', strtotime($log['created_at'])); ?></span>
                                </td>
                                <td style="font-weight:600;"><?= htmlspecialchars($log['admin_user']); ?></td>
                                <td><span class="badge-action <?= $action_class ?>"><?= htmlspecialchars($log['action']); ?></span></td>
                                <td style="font-weight:600;font-size:11px;"><?= htmlspecialchars($log['booking_ref'] ?? '—'); ?></td>
                                <td style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($log['detail'] ?? ''); ?></td>
                                <td class="log-ip"><?= htmlspecialchars($log['ip_address'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </main>
</div>

<script>
// CHART
const ctx = document.getElementById('dailyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_labels); ?>,
        datasets: [{
            label: 'Bookings',
            data: <?= json_encode($chart_values); ?>,
            backgroundColor: 'rgba(37,99,235,0.18)',
            borderColor: '#2563eb',
            borderWidth: 2,
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    title: (items) => 'Date: ' + items[0].label,
                    label: (item)  => ' ' + item.raw + ' booking(s)'
                }
            }
        },
        scales: {
            x: {
                grid: { display: false },
                ticks: {
                    font: { size: 10 },
                    maxTicksLimit: <?= $range <= 30 ? 15 : 12 ?>,
                    maxRotation: 45
                }
            },
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, font: { size: 10 } },
                grid: { color: 'rgba(0,0,0,0.04)' }
            }
        }
    }
});

// SIDEBAR TOGGLE (mobile)
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
</script>

</body>
</html>
<?php $conn->close(); ?>
