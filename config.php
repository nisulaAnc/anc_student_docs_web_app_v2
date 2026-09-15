<?php
// Load .env file for local secrets and configuration
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            $value = substr($value, 1, -1);
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function env(string $key, $default = null) {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// Session configuration - add this at the VERY TOP of config.php before anything else
if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie for localhost
    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',  
        'secure' => false, 
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Core Settings
define('SPREADSHEET_ID', env('SPREADSHEET_ID', '1_uYfzirCYiT5GWDR915aKfB48wvTtcrNaH2MLKIniyU'));
define('BASE_URL',       env('BASE_URL', 'https://localhost/ANC_Student_Docs/'));
define('UPLOAD_DIR',     __DIR__ . '/uploads/');

// SMTP
define('SMTP_HOST',     env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT',     (int) env('SMTP_PORT', 587));
define('SMTP_USERNAME', env('SMTP_USERNAME', 'nisula@ancedu.com'));
define('SMTP_PASSWORD', env('SMTP_PASSWORD', 'ylwd sbzg owpj rihb'));
define('FROM_EMAIL',    env('FROM_EMAIL', 'nisula@ancedu.com'));
define('FROM_NAME',     env('FROM_NAME', 'ANC Student Docs'));

// Database settings
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'anc_student_docs'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));


// Google Sheet Tab Names
// Master data (read-only by the app)
define('SHEET_COUNSELLORS', 'Counsellor List');  
define('SHEET_PRODUCTS',    'Product List');       // Cols: I=Major, J=Degree, K=Product Code (must match keys in product_documents.php)

// Workflow data (written by the app)
define('SHEET_CF_TOKENS',      'CF_Tokens');       
define('SHEET_STUDENT_TOKENS', 'Student_Tokens');  
define('SHEET_SUBMISSIONS',    'Submissions');     
define('SHEET_CHECKLIST', 'Check List');

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/functions.php';
?>
