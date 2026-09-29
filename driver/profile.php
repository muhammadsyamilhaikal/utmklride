<?php
require_once 'api/auth_guard.php';
require_once '../shared/config.php';

$driver_id = $_SESSION['driver_id'];
$success = '';
$error = '';

/**
 * Store one uploaded driver file after checking the actual file contents.
 * Returns the relative path when a new file was stored, null when no file was
 * selected or an error occurred (the latter is written to $error_message).
 */
function store_driver_upload(array $file, string $kind, int $driver_id, array $allowed_mime_types, int $max_size, string &$error_message): ?string
{
    $upload_error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($upload_error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $label = $kind === 'photo' ? 'Profile photo' : 'License file';
    if ($upload_error !== UPLOAD_ERR_OK) {
        $error_message = match ($upload_error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "$label is too large for the server upload limit.",
            UPLOAD_ERR_PARTIAL => "$label upload was interrupted. Please try again.",
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is not configured. Please contact the administrator.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file. Please contact the administrator.',
            UPLOAD_ERR_EXTENSION => "$label was blocked by a server extension.",
            default => "$label could not be uploaded. Please try again."
        };
        return null;
    }

    $temporary_file = (string) ($file['tmp_name'] ?? '');
    $file_size = (int) ($file['size'] ?? 0);
    if ($temporary_file === '' || !is_uploaded_file($temporary_file) || $file_size <= 0) {
        $error_message = "$label upload is invalid. Please select the file again.";
        return null;
    }
    if ($file_size > $max_size) {
        $limit_mb = (int) ($max_size / (1024 * 1024));
        $error_message = "$label must be less than {$limit_mb}MB.";
        return null;
    }

    $mime_type = false;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($temporary_file);
    } else {
        $image_info = @getimagesize($temporary_file);
        $detected_image_type = $image_info['mime'] ?? false;
        if ($detected_image_type && isset($allowed_mime_types[$detected_image_type])) {
            $mime_type = $detected_image_type;
        } elseif (isset($allowed_mime_types['application/pdf'])) {
            // fileinfo is disabled in some PHP builds. Still verify a PDF
            // using its signature rather than trusting browser MIME/name.
            $pdf_signature = @file_get_contents($temporary_file, false, null, 0, 5);
            $mime_type = ($pdf_signature === '%PDF-') ? 'application/pdf' : false;
        }
    }
    if (!isset($allowed_mime_types[$mime_type])) {
        $error_message = $kind === 'photo'
            ? 'Profile photo must be a valid JPG, PNG, or WebP image.'
            : 'License must be a valid JPG, PNG, or PDF file.';
        return null;
    }

    $upload_directory = __DIR__ . '/uploads';
    if (!is_dir($upload_directory) && !@mkdir($upload_directory, 0755, true) && !is_dir($upload_directory)) {
        $error_message = 'The upload folder could not be created. Please contact the administrator.';
        return null;
    }
    if (!is_writable($upload_directory)) {
        $error_message = 'The upload folder is not writable. Please contact the administrator.';
        return null;
    }

    $prefix = $kind === 'photo' ? 'photo' : 'license';
    $filename = $prefix . '_' . $driver_id . '_' . bin2hex(random_bytes(8)) . '.' . $allowed_mime_types[$mime_type];
    $destination = $upload_directory . '/' . $filename;
    if (!move_uploaded_file($temporary_file, $destination)) {
        $error_message = "$label could not be saved. Please try again.";
        return null;
    }

    return 'uploads/' . $filename;
}

function delete_driver_upload(?string $relative_path, string $kind, int $driver_id): void
{
    if (!$relative_path) {
        return;
    }

    $filename = basename($relative_path);
    $prefix = ($kind === 'photo' ? 'photo_' : 'license_') . $driver_id . '_';
    if (!str_starts_with($filename, $prefix)) {
        return;
    }

    $upload_directory = realpath(__DIR__ . '/uploads');
    $file_path = realpath(__DIR__ . '/uploads/' . $filename);
    if ($upload_directory && $file_path && dirname($file_path) === $upload_directory && is_file($file_path)) {
        @unlink($file_path);
    }
}

// Fetch current driver data
$stmt = $conn->prepare("SELECT * FROM drivers WHERE id = ?");
$stmt->bind_param('i', $driver_id);
$stmt->execute();
$driver = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch driver stats
$stats_stmt = $conn->prepare("SELECT COUNT(*) as total_trips, AVG(rating) as avg_rating, COUNT(rating) as total_reviews FROM tempahan WHERE driver_id = ? AND status = 'selesai'");
$stats_stmt->bind_param('i', $driver_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

$total_trips = $stats['total_trips'] ?? 0;
$avg_rating = $stats['avg_rating'] ? round((float)$stats['avg_rating'], 1) : 0;
$total_reviews = $stats['total_reviews'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        // Contact details are managed by admins. Ignore any values posted by
        // a modified client and always persist the current database values.
        $nama            = (string) ($driver['nama'] ?? '');
        $no_telefon      = (string) ($driver['no_telefon'] ?? '');
        $no_plat         = strtoupper(trim($_POST['no_plat'] ?? ''));
        $model_kenderaan = trim($_POST['model_kenderaan'] ?? '');
        $warna_kenderaan = trim($_POST['warna_kenderaan'] ?? '');
        $telegram_chat_id = trim($_POST['telegram_chat_id'] ?? '');

        // Basic validation
        if (empty($no_plat) || empty($model_kenderaan)) {
            $error = 'Please fill in all required fields (Plate and Vehicle Model).';
        } else {
            // Handle both file fields, including PHP upload-limit errors where
            // the browser sends an empty filename but sets an error code.
            $old_profile_photo = $driver['profile_photo'] ?? null;
            $old_lesen_memandu = $driver['lesen_memandu'] ?? null;
            $profile_photo = $old_profile_photo;
            $lesen_memandu = $old_lesen_memandu;
            $new_uploads = [];
            $upload_error = '';

            $photo_upload = store_driver_upload(
                $_FILES['profile_photo'] ?? ['error' => UPLOAD_ERR_NO_FILE],
                'photo',
                (int) $driver_id,
                ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
                2 * 1024 * 1024,
                $upload_error
            );
            if ($photo_upload !== null) {
                $profile_photo = $photo_upload;
                $new_uploads[] = ['path' => $photo_upload, 'kind' => 'photo'];
            } elseif ($upload_error !== '') {
                $error = $upload_error;
            }

            $license_error = '';
            $license_upload = store_driver_upload(
                $_FILES['lesen_memandu'] ?? ['error' => UPLOAD_ERR_NO_FILE],
                'license',
                (int) $driver_id,
                ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'],
                3 * 1024 * 1024,
                $license_error
            );
            if ($license_upload !== null) {
                $lesen_memandu = $license_upload;
                $new_uploads[] = ['path' => $license_upload, 'kind' => 'license'];
            } elseif ($license_error !== '') {
                $error .= ($error !== '' ? ' ' : '') . $license_error;
            }

            if (empty($error)) {
                $stmt = $conn->prepare("UPDATE drivers SET nama=?, no_telefon=?, no_plat=?, model_kenderaan=?, warna_kenderaan=?, telegram_chat_id=?, profile_photo=?, lesen_memandu=? WHERE id=?");
                if (!$stmt) {
                    $error = 'Profile could not be saved. Please try again.';
                } else {
                    $stmt->bind_param('ssssssssi', $nama, $no_telefon, $no_plat, $model_kenderaan, $warna_kenderaan, $telegram_chat_id, $profile_photo, $lesen_memandu, $driver_id);
                    if (!$stmt->execute()) {
                        $error = 'Profile could not be saved. Please try again.';
                    }
                    $stmt->close();
                }
            }

            if (!empty($error)) {
                foreach ($new_uploads as $uploaded_file) {
                    delete_driver_upload($uploaded_file['path'], $uploaded_file['kind'], (int) $driver_id);
                }
            } else {
                // Remove prior files only after their replacements and profile
                // update have both succeeded.
                if ($profile_photo !== $old_profile_photo) {
                    delete_driver_upload($old_profile_photo, 'photo', (int) $driver_id);
                }
                if ($lesen_memandu !== $old_lesen_memandu) {
                    delete_driver_upload($old_lesen_memandu, 'license', (int) $driver_id);
                }

                // Update session name
                $_SESSION['driver_nama'] = $nama;
                $_SESSION['driver_no_telefon'] = $no_telefon;

                // Reload driver data
                $stmt = $conn->prepare("SELECT * FROM drivers WHERE id = ?");
                $stmt->bind_param('i', $driver_id);
                $stmt->execute();
                $driver = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $success = 'Profile updated successfully!';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>My Profile — UTMKL Ride</title>
    <meta name="theme-color" content="#1556e8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="driver.css">
    <style>
        body { background: #f1f5f9; min-height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        .top-header { background: linear-gradient(135deg, #1556e8, #9d3df5); color: white; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .top-header a { color: white; text-decoration: none; font-size: 14px; background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 8px; }
        .profile-page { padding: 16px; max-width: 500px; margin: 0 auto; }
        .avatar-section { text-align: center; margin-bottom: 20px; }
        .avatar { width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 3px solid #1556e8; }
        .avatar-placeholder { width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg,#1556e8,#9d3df5); display: inline-flex; align-items: center; justify-content: center; font-size: 36px; color: white; }
        .card { background: white; border-radius: 16px; padding: 20px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .card h3 { margin: 0 0 16px; font-size: 15px; color: #1e293b; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 11px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 15px; box-sizing: border-box; background: #f8fafc; color: #1e293b; }
        .form-group input:focus { outline: none; border-color: #1556e8; background: white; }
        .form-group input[readonly] { background: #eaf0f8; color: #64748b; cursor: not-allowed; }
        .form-group small { color: #94a3b8; font-size: 11px; margin-top: 4px; display: block; }
        .file-label { display: flex; align-items: center; gap: 10px; padding: 11px 14px; border: 1.5px dashed #cbd5e1; border-radius: 10px; cursor: pointer; background: #f8fafc; }
        .file-label span { color: #1556e8; font-size: 13px; font-weight: 600; }
        .file-label input[type=file] { display: none; }
        .doc-preview { margin-top: 8px; font-size: 12px; color: #16a34a; background: #dcfce7; padding: 6px 10px; border-radius: 6px; }
        .btn-save { width: 100%; background: linear-gradient(135deg, #1556e8, #9d3df5); color: white; border: none; padding: 15px; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; margin-top: 8px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
        .alert-danger { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
        .required { color: #ef4444; }
    </style>
</head>
<body>
    <div class="top-header">
        <strong>👤 My Profile</strong>
        <a href="dashboard.php">← Dashboard</a>
    </div>

    <div class="profile-page">
        <?php if ($success): ?>
            <div class="alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Avatar -->
        <div class="avatar-section">
            <?php if (!empty($driver['profile_photo']) && file_exists(__DIR__ . '/' . $driver['profile_photo'])): ?>
                <img src="<?= htmlspecialchars($driver['profile_photo']) ?>" alt="Profile Photo" class="avatar">
            <?php else: ?>
                <div class="avatar-placeholder">🚗</div>
            <?php endif; ?>
            <div style="margin-top:10px; font-size:18px; font-weight:700; color:#1e293b;"><?= htmlspecialchars($driver['nama']) ?></div>
            <div style="font-size:13px; color:#64748b; margin-bottom: 12px;"><?= htmlspecialchars($driver['username']) ?></div>
            
            <!-- Driver Stats -->
            <div style="display:flex; justify-content:center; gap:12px; margin-bottom: 10px;">
                <div style="background:white; padding:10px 16px; border-radius:12px; box-shadow:0 2px 5px rgba(0,0,0,0.05); text-align:center; min-width:80px;">
                    <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Rating</div>
                    <div style="font-size:16px; font-weight:700; color:#1e293b; margin-top:4px;">
                        ⭐ <?= $avg_rating > 0 ? number_format($avg_rating, 1) : 'New' ?>
                    </div>
                    <?php if($total_reviews > 0): ?>
                        <div style="font-size:10px; color:#94a3b8; margin-top:2px;"><?= $total_reviews ?> reviews</div>
                    <?php endif; ?>
                </div>
                <div style="background:white; padding:10px 16px; border-radius:12px; box-shadow:0 2px 5px rgba(0,0,0,0.05); text-align:center; min-width:80px;">
                    <div style="font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase;">Trips</div>
                    <div style="font-size:16px; font-weight:700; color:#1e293b; margin-top:4px;">
                        🚗 <?= $total_trips ?>
                    </div>
                    <div style="font-size:10px; color:#94a3b8; margin-top:2px;">Completed</div>
                </div>
            </div>

            <!-- Achievement Badges -->
            <div style="display:flex; justify-content:center; gap:8px; flex-wrap:wrap;">
                <?php if($total_trips >= 100): ?>
                    <span style="background:linear-gradient(135deg, #fbbf24, #d97706); color:white; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:700; box-shadow:0 2px 4px rgba(217,119,6,0.3);">🏆 100+ Trips</span>
                <?php elseif($total_trips >= 50): ?>
                    <span style="background:linear-gradient(135deg, #94a3b8, #475569); color:white; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:700; box-shadow:0 2px 4px rgba(71,85,105,0.3);">🥈 50+ Trips</span>
                <?php endif; ?>
                
                <?php if($avg_rating >= 4.8 && $total_reviews >= 5): ?>
                    <span style="background:linear-gradient(135deg, #34d399, #059669); color:white; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:700; box-shadow:0 2px 4px rgba(5,150,105,0.3);">🌟 Top Rated</span>
                <?php endif; ?>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_profile">

            <!-- Personal Info -->
            <div class="card">
                <h3>👤 Personal Information</h3>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="nama" value="<?= htmlspecialchars($driver['nama']) ?>" readonly aria-describedby="contact-details-note">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="no_telefon" value="<?= htmlspecialchars($driver['no_telefon'] ?? '') ?>" readonly aria-describedby="contact-details-note">
                    <small id="contact-details-note">Contact admin if your name or phone number needs to be changed.</small>
                </div>
                <div class="form-group">
                    <label>Telegram Chat ID</label>
                    <input type="text" name="telegram_chat_id" value="<?= htmlspecialchars($driver['telegram_chat_id'] ?? '') ?>" placeholder="e.g. 8138127062">
                    <small>Get your ID from @userinfobot on Telegram</small>
                </div>
                <div class="form-group">
                    <label>Profile Photo</label>
                    <label class="file-label">
                        <span>📷 Choose Photo</span>
                        <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                    </label>
                    <?php if (!empty($driver['profile_photo'])): ?>
                        <div class="doc-preview">✅ Photo uploaded</div>
                    <?php endif; ?>
                    <small>JPG, PNG, WebP — max 2MB</small>
                </div>
            </div>

            <!-- Vehicle Info -->
            <div class="card">
                <h3>🚗 Vehicle Information</h3>
                <div class="form-group">
                    <label>Plate Number <span class="required">*</span></label>
                    <input type="text" name="no_plat" value="<?= htmlspecialchars($driver['no_plat'] ?? '') ?>" placeholder="e.g. WXX 1234" style="text-transform:uppercase;" required>
                </div>
                <div class="form-group">
                    <label>Vehicle Model <span class="required">*</span></label>
                    <input type="text" name="model_kenderaan" value="<?= htmlspecialchars($driver['model_kenderaan'] ?? '') ?>" placeholder="e.g. Perodua Myvi 1.5" required>
                </div>
                <div class="form-group">
                    <label>Vehicle Colour</label>
                    <input type="text" name="warna_kenderaan" value="<?= htmlspecialchars($driver['warna_kenderaan'] ?? '') ?>" placeholder="e.g. Silver">
                </div>
            </div>

            <!-- Documents -->
            <div class="card">
                <h3>📄 Documents</h3>
                <div class="form-group">
                    <label>Driving License</label>
                    <label class="file-label">
                        <span>📂 Upload License</span>
                        <input type="file" name="lesen_memandu" accept="image/jpeg,image/png,application/pdf">
                    </label>
                    <?php if (!empty($driver['lesen_memandu'])): ?>
                        <div class="doc-preview">✅ License document uploaded</div>
                    <?php endif; ?>
                    <small>JPG, PNG, or PDF — max 3MB</small>
                </div>
            </div>

            <button type="submit" class="btn-save">💾 Save Profile</button>
        </form>

        <div style="text-align:center; margin-top:20px; padding-bottom:30px;">
            <a href="change_password.php" style="color:#7c3aed; font-size:14px; text-decoration:none; font-weight:600;">🔑 Change Password</a>
        </div>
    </div>
</body>
</html>
