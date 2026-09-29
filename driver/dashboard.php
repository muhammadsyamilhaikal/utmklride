<?php
require_once 'api/auth_guard.php';
require_once '../shared/config.php';

$driver_id = $_SESSION['driver_id'];
$stmt = $conn->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->bind_param("i", $driver_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();

// Trip History
$hist_stmt = $conn->prepare("SELECT * FROM tempahan WHERE driver_id = ? AND status IN ('selesai', 'batal') ORDER BY completed_at DESC LIMIT 50");
$hist_stmt->bind_param("i", $driver_id);
$hist_stmt->execute();
$history = $hist_stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Driver Dashboard — UTMKL Ride</title>
    <meta name="theme-color" content="#1556e8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="stylesheet" href="driver.css">
    <link rel="stylesheet" href="css/dark.css">
    <!-- Apply saved dark/light theme immediately to prevent flash -->
    <script>
        (function() {
            const t = localStorage.getItem('driver-theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>

<body>
    <div class="top-header">
        <div>
            <strong><?php echo htmlspecialchars($driver['nama']); ?></strong>
            <div style="font-size:12px; opacity:0.8;"><?php echo htmlspecialchars($driver['no_plat'] ?? '—'); ?></div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <button id="themeToggle" onclick="toggleTheme()" aria-label="Toggle dark mode"
                style="background:rgba(255,255,255,0.2); border:none; color:white;
                       padding:6px 12px; border-radius:8px; cursor:pointer;
                       font-size:14px; font-family:inherit;">🌙</button>
            <a href="profile.php" style="color:white; text-decoration:none; font-size:14px; background:rgba(255,255,255,0.2); padding:6px 12px; border-radius:8px;">👤 Profile</a>
            <a href="logout.php" style="color:white; text-decoration:none; font-size:14px; background:rgba(255,255,255,0.2); padding:6px 12px; border-radius:8px;">Logout</a>
        </div>
    </div>


    <!-- Status Toggle Banner -->
    <div class="status-banner <?php echo (!isset($driver['is_online']) || $driver['is_online'] == 1) ? 'online' : 'offline'; ?>" id="statusBanner">
        <div style="display:flex; align-items:center; gap:8px;">
            <span id="statusIndicator"><?php echo (!isset($driver['is_online']) || $driver['is_online'] == 1) ? '🟢' : '🔴'; ?></span>
            <strong id="statusText"><?php echo (!isset($driver['is_online']) || $driver['is_online'] == 1) ? 'Online - Active' : 'Offline - Resting'; ?></strong>
        </div>
        <label class="switch">
            <input type="checkbox" id="toggleStatus" <?php echo (!isset($driver['is_online']) || $driver['is_online'] == 1) ? 'checked' : ''; ?> onchange="toggleOnlineStatus(this.checked)">
            <span class="slider round"></span>
        </label>
    </div>

    <div class="install-banner" id="installBanner" style="display:none;">
        <span>📲 Install this app!</span>
        <button id="installBtn" style="background:white; color:#1556e8; border:none; padding:6px 12px; border-radius:6px; font-weight:bold; cursor:pointer;">Install</button>
    </div>

    <div class="content">
        <!-- Tab 1: Open Jobs -->
        <div id="tab-jobs" class="tab-content active">
            <div class="filter-scroll" id="zoneFilters">
                <div class="filter-pill active" onclick="setJobFilter('All')">All Jobs</div>
                <div class="filter-pill" onclick="setJobFilter('LRT Damai')">LRT Damai</div>
                <div class="filter-pill" onclick="setJobFilter('LRT Sri Rampai')">LRT Sri Rampai</div>
                <div class="filter-pill" onclick="setJobFilter('MRT Raja Uda')">MRT Raja Uda</div>
                <div class="filter-pill" onclick="setJobFilter('Keramat')">Keramat</div>
                <div class="filter-pill" onclick="setJobFilter('KLCC')">KLCC</div>
                <div class="filter-pill" onclick="setJobFilter('Residensi UTMKL')">Residensi UTMKL</div>
                <div class="filter-pill" onclick="setJobFilter('Gurney Mall')">Gurney Mall</div>
            </div>
            <div id="jobs-container">
                <div class="spinner"></div>
            </div>
        </div>

        <!-- Tab 2: My Trips -->
        <div id="tab-mytrips" class="tab-content">
            <div id="mytrips-container">
                <div class="spinner"></div>
            </div>
        </div>

        <!-- Tab 3: History -->
        <div id="tab-history" class="tab-content">
            <?php if ($history->num_rows > 0): ?>
                <?php while($row = $history->fetch_assoc()): ?>
                    <div class="job-card">
                        <div style="display:flex; justify-content:space-between;">
                            <span class="booking-ref">UTM-<?php echo str_pad($row['id'], 4, '0', STR_PAD_LEFT); ?></span>
                            <span class="badge status-<?php echo $row['status']; ?>">
                                <?php echo $row['status'] === 'selesai' ? 'Completed' : 'Cancelled'; ?>
                            </span>
                        </div>
                        <div style="margin-top:8px; font-size:13px; color:#64748b;">
                            <?php echo date('d M Y, h:i A', strtotime($row['tarikh'] . ' ' . $row['masa'])); ?>
                        </div>
                        <div class="trip-route" style="margin-top:8px;">
                            📍 <?php echo htmlspecialchars($row['pickup']); ?> &rarr; <?php echo htmlspecialchars($row['dropoff']); ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div style="font-size:40px; margin-bottom:10px;">📋</div>
                    <div>No trip history found.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="bottom-nav">
        <div class="nav-item active" onclick="switchTab('jobs', this)">
            <span class="nav-icon">🗂</span>
            Open Jobs <span id="badge-jobs" class="badge" style="display:none;">0</span>
        </div>
        <div class="nav-item" onclick="switchTab('mytrips', this)">
            <span class="nav-icon">🚗</span>
            My Trips <span id="badge-mytrips" class="badge" style="display:none;">0</span>
        </div>
        <div class="nav-item" onclick="switchTab('history', this)">
            <span class="nav-icon">📋</span>
            History
        </div>
        <div class="nav-item" onclick="window.location.href='profile.php'">
            <span class="nav-icon">👤</span>
            Profile
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <!-- ===== Fare Collection Modal ===== -->
    <div id="fareModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:flex-end; justify-content:center;">
        <div style="background:#fff; border-radius:20px 20px 0 0; padding:28px 24px 36px; width:100%; max-width:480px; box-shadow:0 -4px 30px rgba(0,0,0,0.18);">
            <div style="text-align:center; margin-bottom:20px;">
                <div style="font-size:32px; margin-bottom:6px;">💰</div>
                <h3 style="margin:0; font-size:18px; color:#1e293b;">Payment Record</h3>
                <p style="margin:6px 0 0; font-size:13px; color:#64748b;">Enter the collected fare amount</p>
            </div>

            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px;">Fare Amount (RM)</label>
            <input id="fareAmount" type="number" inputmode="decimal" min="0" step="0.50" placeholder="e.g. 12.00"
                   style="width:100%; box-sizing:border-box; padding:14px 16px; font-size:18px; font-weight:700; border:2px solid #e2e8f0; border-radius:12px; outline:none; margin-bottom:16px; color:#1e293b; text-align:center;">

            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Payment Method</label>
            <div style="display:flex; gap:10px; margin-bottom:24px;">
                <button id="btnTunai" onclick="selectPayment('tunai')"
                        style="flex:1; padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; transition:all .2s;">
                    💵 Cash
                </button>
                <button id="btnQR" onclick="selectPayment('qr')"
                        style="flex:1; padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; transition:all .2s;">
                    📲 QR
                </button>
            </div>

            <button onclick="submitFareModal()"
                    style="width:100%; padding:16px; background:#1556e8; color:#fff; border:none; border-radius:14px; font-size:16px; font-weight:700; cursor:pointer; margin-bottom:10px;">
                ✅ Complete Trip
            </button>
            <button onclick="closeFareModal()"
                    style="width:100%; padding:12px; background:#f1f5f9; color:#64748b; border:none; border-radius:14px; font-size:14px; cursor:pointer;">
                Cancel
            </button>
        </div>
    </div>
    <!-- ===== End Fare Modal ===== -->

    <!-- ===== SOS Modal ===== -->
    <div id="sosModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:flex-end; justify-content:center;">
        <div style="background:#fff; border-radius:20px 20px 0 0; padding:28px 24px 36px; width:100%; max-width:480px; box-shadow:0 -4px 30px rgba(0,0,0,0.18);">
            <div style="text-align:center; margin-bottom:20px;">
                <div style="font-size:32px; margin-bottom:6px;">🚨</div>
                <h3 style="margin:0; font-size:18px; color:#ef4444;">SOS / Report Issue</h3>
                <p style="margin:6px 0 0; font-size:13px; color:#64748b;">Alert the admin immediately</p>
            </div>

            <label style="display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:8px;">Select Issue Type</label>
            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                <button class="sos-option" onclick="selectSosIssue('Breakdown')" style="padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">🔧 Breakdown</button>
                <button class="sos-option" onclick="selectSosIssue('Flat Tire')" style="padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">🛞 Flat Tire</button>
                <button class="sos-option" onclick="selectSosIssue('Heavy Traffic')" style="padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">🚦 Heavy Traffic</button>
                <button class="sos-option" onclick="selectSosIssue('Accident')" style="padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">💥 Accident</button>
                <button class="sos-option" onclick="selectSosIssue('Other')" style="padding:14px; border-radius:12px; border:2px solid #e2e8f0; background:#f8fafc; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">❓ Other</button>
            </div>

            <button id="btnSubmitSos" onclick="submitSos()" disabled
                    style="width:100%; padding:16px; background:#ef4444; color:#fff; border:none; border-radius:14px; font-size:16px; font-weight:700; cursor:pointer; margin-bottom:10px; opacity:0.5;">
                Send SOS Alert
            </button>
            <button onclick="closeSosModal()"
                    style="width:100%; padding:12px; background:#f1f5f9; color:#64748b; border:none; border-radius:14px; font-size:14px; cursor:pointer;">
                Cancel
            </button>
        </div>
    </div>
    <!-- ===== End SOS Modal ===== -->

    <!-- ===== Quick Reply Modal ===== -->
    <div id="quickReplyModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9999; align-items:flex-end; justify-content:center;">
        <div style="background:#fff; border-radius:20px 20px 0 0; padding:28px 24px 36px; width:100%; max-width:480px; box-shadow:0 -4px 30px rgba(0,0,0,0.18);">
            <div style="text-align:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size:18px; color:#1e293b;">💬 Quick Message</h3>
                <p style="margin:6px 0 0; font-size:13px; color:#64748b;">Send a quick update to the passenger</p>
            </div>

            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:24px;">
                <button onclick="sendQuickReply('Hi, I have arrived at the pick-up point.')" style="padding:14px; border-radius:12px; border:none; background:#f0fdf4; color:#166534; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">📍 Hi, I have arrived at the pick-up point.</button>
                <button onclick="sendQuickReply('Hi, I am stuck in traffic. Please give me 5-10 minutes.')" style="padding:14px; border-radius:12px; border:none; background:#fffbeb; color:#92400e; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">🚦 Hi, I am stuck in traffic. Please give me 5-10 minutes.</button>
                <button onclick="sendQuickReply('Hi, I am on my way to you now!')" style="padding:14px; border-radius:12px; border:none; background:#eff6ff; color:#1e40af; font-size:15px; font-weight:600; cursor:pointer; text-align:left;">🚗 Hi, I am on my way to you now!</button>
            </div>

            <button onclick="closeQuickReplyModal()"
                    style="width:100%; padding:12px; background:#f1f5f9; color:#64748b; border:none; border-radius:14px; font-size:14px; cursor:pointer;">
                Cancel
            </button>
        </div>
    </div>
    <!-- ===== End Quick Reply Modal ===== -->

    <script>
        function switchTab(tabId, el) {
            document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(e => e.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            el.classList.add('active');
            if (tabId === 'jobs') loadJobs();
            if (tabId === 'mytrips') loadMyTrips();
        }

        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        let currentOpenJobs = [];
        let currentJobFilter = 'All';

        function setJobFilter(zone) {
            currentJobFilter = zone;
            document.querySelectorAll('.filter-pill').forEach(el => {
                if (el.textContent.trim() === zone || (zone === 'All' && el.textContent.trim() === 'All Jobs')) {
                    el.classList.add('active');
                } else {
                    el.classList.remove('active');
                }
            });
            renderJobs();
        }

        async function loadJobs() {
            const banner = document.getElementById('statusBanner');
            if (banner && banner.classList.contains('offline')) {
                return;
            }

            try {
                const res = await fetch('api/jobs.php', {headers: {'Accept': 'application/json'}});
                if (res.status === 401) { window.location.href = 'login.php'; return; }
                currentOpenJobs = await res.json();
                // Save to IndexedDB for offline fallback
                if (typeof cacheSet === 'function') cacheSet('jobs', currentOpenJobs).catch(() => {});
                renderJobs();
            } catch (err) {
                console.error(err);
                // Offline — try to show cached data from IndexedDB
                if (typeof cacheGet === 'function') {
                    try {
                        const cached = await cacheGet('jobs');
                        if (cached && cached.length >= 0) {
                            currentOpenJobs = cached;
                            renderJobs();
                            showToast('⚠️ Offline — showing cached jobs');
                            return;
                        }
                    } catch (dbErr) { console.warn('IndexedDB read failed:', dbErr); }
                }
                document.getElementById('jobs-container').innerHTML = `
                    <div class="empty-state">
                        <div style="font-size:40px; margin-bottom:10px;">📶</div>
                        <div>You appear to be offline. Please check your connection.</div>
                    </div>
                `;
            }
        }


        function renderJobs() {
            const container = document.getElementById('jobs-container');
            const badge = document.getElementById('badge-jobs');
            const banner = document.getElementById('statusBanner');
            
            if (banner && banner.classList.contains('offline')) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div style="font-size:40px; margin-bottom:10px;">😴</div>
                        <div>You are offline. Turn on your status to view and claim new trips.</div>
                    </div>
                `;
                badge.style.display = 'none';
                return;
            }

            // Apply filter
            let filteredJobs = currentOpenJobs;
            if (currentJobFilter !== 'All') {
                const keyword = currentJobFilter.toLowerCase();
                filteredJobs = currentOpenJobs.filter(job => 
                    job.pickup.toLowerCase().includes(keyword) || 
                    job.dropoff.toLowerCase().includes(keyword)
                );
            }

            if (filteredJobs.length > 0) {
                badge.textContent = currentOpenJobs.length; // badge always shows total open jobs
                badge.style.display = 'inline-block';
                let html = '';
                filteredJobs.forEach(job => {
                    html += `
                        <div class="job-card">
                            <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                                <span class="booking-ref">${job.booking_ref}</span>
                                <span style="font-size:12px; color:#64748b;">${job.created_ago}</span>
                            </div>
                            <div style="font-weight:bold; margin-bottom:5px;">${job.nama}</div>
                            <div class="trip-route" style="margin-bottom:8px;">
                                📍 ${job.pickup} &rarr; ${job.dropoff}
                            </div>
                            <div style="font-size:13px; color:#64748b; margin-bottom:10px;">
                                📅 ${job.tarikh_format} &nbsp;🕒 ${job.masa_format}
                            </div>
                            <button class="btn-claim" onclick="claimJob(${job.id})">🤝 Claim This Trip</button>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } else {
                badge.textContent = currentOpenJobs.length;
                badge.style.display = currentOpenJobs.length > 0 ? 'inline-block' : 'none';
                
                let emptyMsg = currentJobFilter !== 'All' 
                    ? `No open jobs matching "<b>${currentJobFilter}</b>".` 
                    : "No open jobs at the moment.";
                
                container.innerHTML = `
                    <div class="empty-state">
                        <div style="font-size:40px; margin-bottom:10px;">🏖️</div>
                        <div>${emptyMsg}</div>
                    </div>
                `;
            }
        }

        async function loadMyTrips() {
            try {
                const res = await fetch('api/my_trips.php', {headers: {'Accept': 'application/json'}});
                if (res.status === 401) { window.location.href = 'login.php'; return; }
                const data     = await res.json();
                const trips    = data.trips       ?? [];
                const stats    = data.shift_stats ?? {tunai: 0, qr: 0};
                // Save to IndexedDB for offline fallback
                if (typeof cacheSet === 'function') cacheSet('mytrips', data).catch(() => {});
                renderMyTrips(trips, stats);
            } catch (err) {
                console.error(err);
                // Offline — try to show cached data from IndexedDB
                if (typeof cacheGet === 'function') {
                    try {
                        const cached = await cacheGet('mytrips');
                        if (cached) {
                            renderMyTrips(cached.trips ?? [], cached.shift_stats ?? {tunai: 0, qr: 0});
                            showToast('⚠️ Offline — showing cached trips');
                            return;
                        }
                    } catch (dbErr) { console.warn('IndexedDB read failed:', dbErr); }
                }
                document.getElementById('mytrips-container').innerHTML = `
                    <div class="empty-state">
                        <div style="font-size:40px; margin-bottom:10px;">📶</div>
                        <div>You appear to be offline. Please check your connection.</div>
                    </div>
                `;
            }
        }

        function renderMyTrips(trips, stats) {
                const container = document.getElementById('mytrips-container');
                const badge     = document.getElementById('badge-mytrips');

                // --- Build Shift Stats card (always shown) ---
                const totalToday = (parseFloat(stats.tunai) + parseFloat(stats.qr)).toFixed(2);
                const statsCard = `
                    <div style="background:linear-gradient(135deg,#1556e8 0%,#0f3fb5 100%); border-radius:16px; padding:18px 20px; margin-bottom:16px; color:#fff; box-shadow:0 4px 16px rgba(21,86,232,0.3);">
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px;">
                            <span style="font-size:20px;">📊</span>
                            <span style="font-size:14px; font-weight:700; opacity:0.95; letter-spacing:0.3px;">Today's Collection</span>
                        </div>
                        <div style="display:flex; gap:10px; margin-bottom:14px;">
                            <div style="flex:1; background:rgba(255,255,255,0.15); border-radius:12px; padding:12px 14px; text-align:center;">
                                <div style="font-size:12px; opacity:0.8; margin-bottom:4px;">💵 Cash</div>
                                <div style="font-size:20px; font-weight:800;">RM ${parseFloat(stats.tunai).toFixed(2)}</div>
                            </div>
                            <div style="flex:1; background:rgba(255,255,255,0.15); border-radius:12px; padding:12px 14px; text-align:center;">
                                <div style="font-size:12px; opacity:0.8; margin-bottom:4px;">📲 QR</div>
                                <div style="font-size:20px; font-weight:800;">RM ${parseFloat(stats.qr).toFixed(2)}</div>
                            </div>
                        </div>
                        <div style="border-top:1px solid rgba(255,255,255,0.2); padding-top:10px; display:flex; justify-content:space-between; align-items:center;">
                            <span style="font-size:13px; opacity:0.85;">Total Amount</span>
                            <span style="font-size:18px; font-weight:800;">RM ${totalToday}</span>
                        </div>
                    </div>
                `;

                if (trips.length > 0) {
                    badge.textContent = trips.length;
                    badge.style.display = 'inline-block';
                    let html = statsCard;
                    trips.forEach(trip => {
                        const statusLabel = trip.status === 'confirmed' ? 'Confirmed' : 'In Progress';
                        let actionBtn = '';
                        if (trip.status === 'confirmed') {
                            actionBtn = `
                                <button class="btn-claim btn-start" onclick="updateStatus(${trip.id}, 'in_progress')"><i class="fa-solid fa-play"></i> Start Trip</button>
                                <button class="btn-claim" style="background: linear-gradient(135deg, #ef4444, #dc2626); margin-top: 8px;" onclick="cancelClaimedJob(${trip.id})"><i class="fa-solid fa-eject"></i> Cancel &amp; Release Job</button>
                            `;
                        } else if (trip.status === 'in_progress') {
                            actionBtn = `
                                <button class="btn-claim btn-done" onclick="openFareModal(${trip.id})"><i class="fa-solid fa-check"></i> End Trip</button>
                                <button class="btn-claim" style="background: linear-gradient(135deg, #ef4444, #dc2626); margin-top: 8px;" onclick="cancelClaimedJob(${trip.id})"><i class="fa-solid fa-eject"></i> Cancel &amp; Release Job</button>
                            `;
                        }
                        const pickupWazeUrl  = `https://waze.com/ul?q=${encodeURIComponent(trip.pickup)}&navigate=yes`;
                        const dropoffWazeUrl = `https://waze.com/ul?q=${encodeURIComponent(trip.dropoff)}&navigate=yes`;
                        const cleanPhone = cleanPhoneMY(trip.telefon);
                        const chatMsg   = `Hi ${trip.nama}! I am your driver for UTMKL Ride booking ${trip.booking_ref}.`;
                        const chatUrl   = `https://api.whatsapp.com/send?phone=${cleanPhone}&text=${encodeURIComponent(chatMsg)}`;

                        html += `
                            <div class="job-card">
                                <div style="display:flex; justify-content:space-between; margin-bottom:10px;">
                                    <span class="booking-ref">${escapeHTML(trip.booking_ref)}</span>
                                    <span class="badge status-${trip.status}">${statusLabel}</span>
                                </div>
                                <div style="font-weight:bold; font-size:15px; color:#1e293b;">${escapeHTML(trip.nama)}</div>
                                <div style="margin-bottom:8px;">
                                    <a href="tel:${cleanPhone}" style="text-decoration:none; color:var(--primary); font-size:14px; font-weight:600;">📞 ${escapeHTML(trip.telefon)}</a>
                                </div>
                                <div class="trip-route" style="margin-bottom:8px;">
                                    📍 ${escapeHTML(trip.pickup)} &rarr; ${escapeHTML(trip.dropoff)}
                                </div>
                                <div style="font-size:13px; color:#64748b; margin-bottom:10px;">
                                    📅 ${escapeHTML(trip.tarikh_format)} &nbsp;🕒 ${escapeHTML(trip.masa_format)}
                                </div>
                                <div class="action-btn-row">
                                    <button type="button" class="btn-action-sm btn-fare"
                                        data-ref="${escapeAttr(trip.booking_ref)}"
                                        data-name="${escapeAttr(trip.nama)}"
                                        data-pickup="${escapeAttr(trip.pickup)}"
                                        data-dropoff="${escapeAttr(trip.dropoff)}"
                                        data-date="${escapeAttr(trip.tarikh_format)}"
                                        data-time="${escapeAttr(trip.masa_format)}"
                                        data-phone="${escapeAttr(trip.telefon)}"
                                        onclick="handleSendFare(this)">
                                        💰 Send Fare
                                    </button>
                                    <button type="button" class="btn-action-sm btn-rearrange"
                                        data-ref="${escapeAttr(trip.booking_ref)}"
                                        data-name="${escapeAttr(trip.nama)}"
                                        data-pickup="${escapeAttr(trip.pickup)}"
                                        data-dropoff="${escapeAttr(trip.dropoff)}"
                                        data-date="${escapeAttr(trip.tarikh_format)}"
                                        data-time="${escapeAttr(trip.masa_format)}"
                                        data-phone="${escapeAttr(trip.telefon)}"
                                        onclick="handleRearrange(this)">
                                        ↻ Rearrange
                                    </button>
                                    <button type="button" class="btn-action-sm btn-whatsapp"
                                        onclick="openQuickReplyModal('${cleanPhone}', '${escapeAttr(trip.booking_ref)}')">
                                        💬 Quick Msg
                                    </button>
                                    <a href="${pickupWazeUrl}" target="_blank" rel="noopener noreferrer" class="btn-action-sm btn-waze" aria-label="Navigate to pickup: ${escapeAttr(trip.pickup)}">
                                        📍 To Pickup
                                    </a>
                                    <a href="${dropoffWazeUrl}" target="_blank" rel="noopener noreferrer" class="btn-action-sm btn-waze" aria-label="Navigate to drop-off: ${escapeAttr(trip.dropoff)}">
                                        🏁 To Drop-off
                                    </a>
                                    <button type="button" class="btn-action-sm btn-sos"
                                        style="background-color:#fee2e2; color:#ef4444;"
                                        onclick="openSosModal('${escapeAttr(trip.booking_ref)}')">
                                        🚨 SOS
                                    </button>
                                </div>
                                ${actionBtn}
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    badge.style.display = 'none';
                    container.innerHTML = statsCard + `
                        <div class="empty-state">
                            <div style="font-size:40px; margin-bottom:10px;">📭</div>
                            <div>No active trips at the moment.</div>
                        </div>
                    `;
                }
        }


        // ===== Fare Modal Helpers =====

        let _fareBookingId   = null;
        let _selectedPayment = null;

        function openFareModal(bookingId) {
            _fareBookingId   = bookingId;
            _selectedPayment = null;
            document.getElementById('fareAmount').value = '';
            document.getElementById('btnTunai').style.cssText += ';border-color:#e2e8f0;background:#f8fafc;color:#1e293b;';
            document.getElementById('btnQR').style.cssText    += ';border-color:#e2e8f0;background:#f8fafc;color:#1e293b;';
            const modal = document.getElementById('fareModal');
            modal.style.display = 'flex';
            setTimeout(() => document.getElementById('fareAmount').focus(), 100);
        }

        function selectPayment(method) {
            _selectedPayment = method;
            const activeStyle  = 'flex:1;padding:14px;border-radius:12px;border:2px solid #1556e8;background:#eff6ff;font-size:15px;font-weight:700;cursor:pointer;transition:all .2s;color:#1556e8;';
            const inactiveStyle = 'flex:1;padding:14px;border-radius:12px;border:2px solid #e2e8f0;background:#f8fafc;font-size:15px;font-weight:600;cursor:pointer;transition:all .2s;color:#1e293b;';
            document.getElementById('btnTunai').style.cssText = (method === 'tunai') ? activeStyle : inactiveStyle;
            document.getElementById('btnQR').style.cssText    = (method === 'qr')    ? activeStyle : inactiveStyle;
        }

        function closeFareModal() {
            document.getElementById('fareModal').style.display = 'none';
            _fareBookingId   = null;
            _selectedPayment = null;
        }

        async function submitFareModal() {
            const amountRaw = document.getElementById('fareAmount').value.trim();
            const amount    = parseFloat(amountRaw);

            if (isNaN(amount) || amount <= 0) {
                alert('Please enter a valid fare amount (greater than RM 0.00).');
                return;
            }
            if (!_selectedPayment) {
                alert('Please select a payment method: Cash or QR.');
                return;
            }

            // Snapshot values BEFORE closing the modal — closeFareModal() resets them to null
            const bookingId = _fareBookingId;
            const payment   = _selectedPayment;

            closeFareModal();
            await updateStatus(bookingId, 'selesai', amount, payment);
        }

        // ===== SOS Modal Helpers =====
        let _sosBookingRef = null;
        let _sosIssueType = null;

        function openSosModal(bookingRef) {
            _sosBookingRef = bookingRef;
            _sosIssueType = null;
            document.querySelectorAll('.sos-option').forEach(btn => {
                btn.style.borderColor = '#e2e8f0';
                btn.style.background = '#f8fafc';
            });
            document.getElementById('btnSubmitSos').disabled = true;
            document.getElementById('btnSubmitSos').style.opacity = '0.5';
            document.getElementById('sosModal').style.display = 'flex';
        }

        function closeSosModal() {
            document.getElementById('sosModal').style.display = 'none';
            _sosBookingRef = null;
            _sosIssueType = null;
        }

        function selectSosIssue(issueType) {
            _sosIssueType = issueType;
            document.querySelectorAll('.sos-option').forEach(btn => {
                if (btn.innerText.includes(issueType)) {
                    btn.style.borderColor = '#ef4444';
                    btn.style.background = '#fef2f2';
                } else {
                    btn.style.borderColor = '#e2e8f0';
                    btn.style.background = '#f8fafc';
                }
            });
            document.getElementById('btnSubmitSos').disabled = false;
            document.getElementById('btnSubmitSos').style.opacity = '1';
        }

        async function submitSos() {
            if (!_sosIssueType) return;
            
            const btn = document.getElementById('btnSubmitSos');
            btn.innerText = 'Sending...';
            btn.disabled = true;

            try {
                const res = await fetch('api/sos_alert.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({
                        booking_ref: _sosBookingRef,
                        issue_type: _sosIssueType
                    })
                });
                const data = await res.json();
                
                // Reset button state BEFORE alert blocks the thread
                btn.innerText = 'Send SOS Alert';
                btn.disabled = false;

                if (data.success) {
                    showToast('🚨 SOS Alert Sent to Admin!');
                    closeSosModal();
                } else {
                    console.error("SOS API Error:", data.message);
                    alert('Error: ' + data.message);
                }
            } catch (err) {
                console.error("SOS Fetch Error:", err);
                btn.innerText = 'Send SOS Alert';
                btn.disabled = false;
                alert('Connection error. Could not send SOS alert.');
            }
        }

        // ===== Quick Reply Helpers =====
        let _qrPhone = null;
        let _qrBookingRef = null;

        function openQuickReplyModal(phone, bookingRef) {
            _qrPhone = phone;
            _qrBookingRef = bookingRef;
            document.getElementById('quickReplyModal').style.display = 'flex';
        }

        function closeQuickReplyModal() {
            document.getElementById('quickReplyModal').style.display = 'none';
            _qrPhone = null;
            _qrBookingRef = null;
        }

        function sendQuickReply(templateText) {
            if (!_qrPhone) return;
            const text = `Booking ${_qrBookingRef}:\n${templateText}`;
            const url = `https://api.whatsapp.com/send?phone=${_qrPhone}&text=${encodeURIComponent(text)}`;
            window.open(url, '_blank');
            closeQuickReplyModal();
        }



        function cleanPhoneMY(phone) {
            if (!phone) return '';
            let digits = String(phone).replace(/\D/g, '');
            if (digits.startsWith('00')) digits = digits.substring(2);
            if (digits.startsWith('600')) {
                digits = '60' + digits.substring(3);
            } else if (digits.startsWith('0')) {
                digits = '60' + digits.substring(1);
            } else if (!digits.startsWith('60')) {
                digits = '60' + digits;
            }
            return digits;
        }

        function escapeHTML(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function escapeAttr(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function handleSendFare(btn) {
            const d = btn.dataset;
            const phoneNo = cleanPhoneMY(d.phone);

            let price = prompt(
`Enter the price offer (RM)\n\nBooking: ${d.ref}\nCustomer: ${d.name}\n\n📍 From:\n${d.pickup}\n\n🏁 To:\n${d.dropoff}`
            );

            if (price !== null && price.trim() !== '') {
                price = price.trim();

                const wsMsg =
`Hi ${d.name}! 👋

Your ride fare has been prepared.

🎫 *Booking ID: ${d.ref}*

📍 *Pick-up:* ${d.pickup}
🏁 *Drop-off:* ${d.dropoff}
📅 *Date:* ${d.date}
🕐 *Time:* ${d.time}

💰 *Total Fare: RM ${price}*

Please reply *YES* to confirm your booking.

🔎 *Check Booking Status*
Use Booking ID *${d.ref}* together with your registered phone number on the UTMKL RIDE booking status page.

Please keep this Booking ID for future reference.

Thank you! 🚗`;

                const wsLink = `https://api.whatsapp.com/send?phone=${phoneNo}&text=${encodeURIComponent(wsMsg)}`;
                window.open(wsLink, '_blank');
            } else {
                alert('Process cancelled. No WhatsApp message was sent.');
            }
        }

        function handleRearrange(btn) {
            const d = btn.dataset;
            const phoneNo = cleanPhoneMY(d.phone);

            const msg =
`Dear ${d.name},

Regarding your booking *${d.ref}* from *${d.pickup}* to *${d.dropoff}* on *${d.date} at ${d.time}*, unfortunately I am unable to accept this ride due to a scheduling conflict.

Please rearrange or cancel your booking. I sincerely apologise for the inconvenience.`;

            const wsLink = `https://api.whatsapp.com/send?phone=${phoneNo}&text=${encodeURIComponent(msg)}`;
            window.open(wsLink, '_blank');
        }

        async function claimJob(bookingId) {
            try {
                const res = await fetch('api/claim_job.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({booking_id: bookingId})
                });
                const data = await res.json();
                if (data.success) {
                    if (data.tg_url) fetch(data.tg_url, {mode: 'no-cors'}).catch(() => {});
                    showToast(data.message);
                    loadJobs();
                    loadMyTrips();
                } else {
                    alert(data.message);
                    loadJobs();
                }
            } catch (err) { console.error(err); }
        }

        async function updateStatus(bookingId, newStatus, tambang = null, caraBayaran = null) {
            try {
                const payload = {booking_id: bookingId, new_status: newStatus};
                if (newStatus === 'selesai' && tambang !== null) {
                    payload.tambang      = tambang;
                    payload.cara_bayaran = caraBayaran;
                }
                const res = await fetch('api/update_status.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    if (data.tg_url) fetch(data.tg_url, {mode: 'no-cors'}).catch(() => {});
                    showToast('Trip status updated successfully.');
                    if (newStatus === 'selesai') {
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        loadMyTrips();
                    }
                } else {
                    alert(data.message);
                }
            } catch (err) { console.error(err); }
        }

        async function toggleOnlineStatus(isOnline) {
            const status = isOnline ? 1 : 0;
            const banner = document.getElementById('statusBanner');
            const ind = document.getElementById('statusIndicator');
            const txt = document.getElementById('statusText');

            try {
                const res = await fetch('api/toggle_online.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({is_online: status})
                });
                const data = await res.json();
                if (data.success) {
                    if (isOnline) {
                        banner.classList.remove('offline');
                        banner.classList.add('online');
                        ind.textContent = '🟢';
                        txt.textContent = 'Online - Active';
                        showToast('You are now Online');
                        loadJobs();
                    } else {
                        banner.classList.remove('online');
                        banner.classList.add('offline');
                        ind.textContent = '🔴';
                        txt.textContent = 'Offline - Resting';
                        showToast('You are now Offline');
                        document.getElementById('jobs-container').innerHTML = `
                            <div class="empty-state">
                                <div style="font-size:40px; margin-bottom:10px;">😴</div>
                                <div>You are offline. Turn on your status to view and claim new trips.</div>
                            </div>
                        `;
                        document.getElementById('badge-jobs').style.display = 'none';
                    }
                } else {
                    alert('Failed to update status. Please try again.');
                    document.getElementById('toggleStatus').checked = !isOnline;
                }
            } catch (err) {
                console.error(err);
                alert('Connection error.');
                document.getElementById('toggleStatus').checked = !isOnline;
            }
        }

        async function cancelClaimedJob(bookingId) {
            // The requested Antigravity confirmation prompt
            const antigravityPrompt = "🚀 ANTIGRAVITY PROTOCOL INITIATED!\n\nAre you sure you want to cancel this order and float it back into the job pool for other drivers?";
            
            if (!confirm(antigravityPrompt)) {
                return; // Driver backed out
            }
            
            try {
                const res = await fetch('api/cancel_job.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({booking_id: bookingId})
                });
                const data = await res.json();
                
                if (data.success) {
                    if (data.tg_url) fetch(data.tg_url, {mode: 'no-cors'}).catch(() => {});
                    showToast(data.message);
                    // Refresh both tabs to show the job moved from 'My Trips' back to 'Open Jobs'
                    loadJobs();
                    loadMyTrips();
                } else {
                    alert(data.message);
                }
            } catch (err) { 
                console.error(err); 
                alert("Connection error while activating antigravity sequence.");
            }
        }

        // ===== Init =====
        loadJobs();
        loadMyTrips();

        // ===== Smart Polling (visibility-aware, pauses when offline/tab hidden) =====
        let pollingTimer = null;

        function startPolling() {
            if (pollingTimer) return; // already running
            pollingTimer = setInterval(() => {
                if (document.getElementById('tab-jobs').classList.contains('active')) loadJobs();
                if (document.getElementById('tab-mytrips').classList.contains('active')) loadMyTrips();
            }, 10000); // every 10 seconds
        }

        function stopPolling() {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }

        // Pause when user switches to another tab/app, resume when they return
        document.addEventListener('visibilitychange', () => {
            document.hidden ? stopPolling() : startPolling();
        });

        // Pause when network drops, refresh & resume when it comes back
        window.addEventListener('offline', stopPolling);
        window.addEventListener('online', () => {
            showToast('🟢 Back online — refreshing...');
            loadJobs();
            loadMyTrips();
            startPolling();
        });

        startPolling();

        // ===== Dark Mode Toggle =====
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-theme') || 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('driver-theme', next);
            document.getElementById('themeToggle').textContent = next === 'dark' ? '☀️' : '🌙';
        }

        // Sync toggle icon with the saved theme (applied before page load in <head>)
        (function syncThemeIcon() {
            const saved = localStorage.getItem('driver-theme') || 'light';
            document.getElementById('themeToggle').textContent = saved === 'dark' ? '☀️' : '🌙';
        })();

        // ===== PWA Service Worker =====
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('./sw.js', { scope: './' })
                .then(reg => console.log('SW registered with scope:', reg.scope))
                .catch(err => console.error('SW registration error:', err));
        }

        // ===== PWA Install Prompt =====
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            document.getElementById('installBanner').style.display = 'flex';
        });
        document.getElementById('installBtn').addEventListener('click', async () => {
            document.getElementById('installBanner').style.display = 'none';
            deferredPrompt.prompt();
            await deferredPrompt.userChoice;
            deferredPrompt = null;
        });
    </script>
    <!-- IndexedDB helper — loaded after main script so cacheSet/cacheGet are available -->
    <script src="js/db.js"></script>
</body>
</html>

