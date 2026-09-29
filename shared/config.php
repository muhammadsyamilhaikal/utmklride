<?php
// =========================================================================
// SHARED DATABASE CONFIG — UTMKL Ride
// Digunakan oleh /admin/ dan /driver/
// =========================================================================
date_default_timezone_set('Asia/Kuala_Lumpur');
mysqli_report(MYSQLI_REPORT_OFF);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '8080');
define('DB_NAME', 'ride_booking');

// =========================================================================
// HELPER: Sambungan DB dengan error handling standard
// =========================================================================
function db_connect(): mysqli {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        $is_json = isset($_SERVER['HTTP_ACCEPT']) &&
                   str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
        if ($is_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
            exit;
        }
        die("<div style='background:#f8d7da;color:#721c24;padding:20px;font-family:sans-serif;margin:20px;border-radius:8px;border:1px solid #f5c6cb;text-align:center;'>
                <b>🚨 Database Connection Error:</b><br>Unable to connect to the database. Please ensure the server is running!
             </div>");
    }
    return $conn;
}

// =========================================================================
// HELPER: Baca nilai dari jadual `settings`
// =========================================================================
function get_setting(mysqli $conn, string $key, string $default = ''): string {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    return ($row && isset($row['value'])) ? $row['value'] : $default;
}

// =========================================================================
// HELPER: Normalisasi dan Auto-fix nombor telefon Malaysia (+60)
// Menangani: +6013-3670031, 013 367 0031, +60 013, 6013, 0060, dsb.
// Hasil: Sentiasa format digit bersih antarabangsa (cth: 60133670031)
// =========================================================================
function clean_phone_my($phone): string {
    $phone = preg_replace('/\D+/', '', (string)$phone);
    if (substr($phone, 0, 2) === '00') {
        $phone = substr($phone, 2);
    }
    if (substr($phone, 0, 3) === '600') {
        $phone = '60' . substr($phone, 3);
    } elseif (substr($phone, 0, 1) === '0') {
        $phone = '60' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) !== '60') {
        $phone = '60' . $phone;
    }
    return $phone;
}

// =========================================================================
// HELPER: AES-256 Data Encryption / Decryption
// Guna: encrypt_data($string) dan decrypt_data($encrypted)
// Kunci disimpan dalam jadual settings (auto-generate jika tiada)
// =========================================================================
function get_encrypt_key(mysqli $conn): string {
    $stmt = $conn->prepare("SELECT value FROM settings WHERE key_name = 'app_encrypt_key' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row && !empty($row['value'])) {
            return base64_decode($row['value']);
        }
    }
    // Auto-generate 32-byte key if not exists
    $key = random_bytes(32);
    $encoded = base64_encode($key);
    $ins = $conn->prepare("INSERT IGNORE INTO settings (key_name, value) VALUES ('app_encrypt_key', ?)");
    if ($ins) {
        $ins->bind_param('s', $encoded);
        $ins->execute();
        $ins->close();
    }
    return $key;
}

function encrypt_data(string $plaintext, mysqli $conn): string {
    if (empty($plaintext)) return '';
    $key = get_encrypt_key($conn);
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_data(string $ciphertext, mysqli $conn): string {
    if (empty($ciphertext)) return '';
    try {
        $raw = base64_decode($ciphertext);
        if (strlen($raw) < 17) return $ciphertext; // not encrypted, return as-is
        $key = get_encrypt_key($conn);
        $iv  = substr($raw, 0, 16);
        $enc = substr($raw, 16);
        $dec = openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return ($dec !== false) ? $dec : $ciphertext;
    } catch (\Throwable $e) {
        return $ciphertext;
    }
}

$conn = db_connect();

// =========================================================================
// AUTO-MIGRATE: Cipta jadual yang diperlukan jika belum wujud
// =========================================================================

// Jadual: drivers
$conn->query("
    CREATE TABLE IF NOT EXISTS drivers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        nama VARCHAR(100) NOT NULL,
        no_telefon VARCHAR(20),
        no_plat VARCHAR(20),
        model_kenderaan VARCHAR(100),
        telegram_chat_id VARCHAR(50) DEFAULT NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Jadual: admins
$conn->query("
    CREATE TABLE IF NOT EXISTS admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        nama VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Jadual: settings
$conn->query("
    CREATE TABLE IF NOT EXISTS settings (
        key_name VARCHAR(50) PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// Nilai default untuk settings
$conn->query("INSERT IGNORE INTO settings (key_name, value) VALUES
    ('telegram_bot_token',      '8601874885:AAGzZSB5Fs6HiRkcdDCDkxRBomApBNOAKcs'),
    ('telegram_chat_id_admin',  '1359073968'),
    ('telegram_chat_id_drivers','')
");

// Check and add new columns to drivers table
$driver_cols = $conn->query("SHOW COLUMNS FROM drivers");
if ($driver_cols) {
    $driver_existing = [];
    while ($dc = $driver_cols->fetch_assoc()) { $driver_existing[] = $dc['Field']; }
    if (!in_array('must_change_password', $driver_existing)) {
        $conn->query("ALTER TABLE drivers ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password");
    }
    if (!in_array('profile_photo', $driver_existing)) {
        $conn->query("ALTER TABLE drivers ADD COLUMN profile_photo VARCHAR(255) NULL AFTER telegram_chat_id");
    }
    if (!in_array('lesen_memandu', $driver_existing)) {
        $conn->query("ALTER TABLE drivers ADD COLUMN lesen_memandu VARCHAR(255) NULL");
    }
    if (!in_array('warna_kenderaan', $driver_existing)) {
        $conn->query("ALTER TABLE drivers ADD COLUMN warna_kenderaan VARCHAR(50) NULL");
    }
}

// Tambah kolum driver_id dan accepted_at/completed_at ke tempahan jika belum ada
$cols = $conn->query("SHOW COLUMNS FROM tempahan");
if ($cols) {
    $existing = [];
    while ($c = $cols->fetch_assoc()) { $existing[] = $c['Field']; }

    if (!in_array('driver_id', $existing)) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN driver_id INT NULL");
    }
    if (!in_array('accepted_at', $existing)) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN accepted_at DATETIME NULL");
    }
    if (!in_array('completed_at', $existing)) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN completed_at DATETIME NULL");
    }
    if (!in_array('tambang', $existing)) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN tambang DECIMAL(10,2) DEFAULT NULL");
    }
    if (!in_array('cara_bayaran', $existing)) {
        $conn->query("ALTER TABLE tempahan ADD COLUMN cara_bayaran ENUM('tunai','qr') DEFAULT NULL");
    }
}
?>
