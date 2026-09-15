<?php
// Include product-document mapping
require_once __DIR__ . '/product_documents.php';

/**
 * Establish a secure connection to the MySQL database.
 */
function getDBConnection(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// GOOGLE SHEETS


function getGoogleSheetsService(): \Google\Service\Sheets {
    $client = new \Google\Client();
    $credentialsJson = getenv('GOOGLE_SERVICE_ACCOUNT_JSON');
    if ($credentialsJson !== false && $credentialsJson !== '') {
        $client->setAuthConfig(json_decode($credentialsJson, true));
    } else {
        $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: __DIR__ . '/../credentials.json';
        $client->setAuthConfig($credentialsPath);
    }
    $client->addScope(\Google\Service\Sheets::SPREADSHEETS);
    return new \Google\Service\Sheets($client);
}

function sheetRead(string $tab, string $range = 'A1:Z5000'): array {
    $svc = getGoogleSheetsService();
    $res = $svc->spreadsheets_values->get(SPREADSHEET_ID, "{$tab}!{$range}");
    return $res->getValues() ?? [];
}

function sheetAppend(string $tab, array $row): void {
    $svc  = getGoogleSheetsService();
    $body = new \Google\Service\Sheets\ValueRange(['values' => [$row]]);
    $svc->spreadsheets_values->append(
        SPREADSHEET_ID, "{$tab}!A1", $body,
        ['valueInputOption' => 'RAW']
    );
}

function sheetUpdateRow(string $tab, int $rowNumber, array $row): void {
    $svc   = getGoogleSheetsService();
    $range = "{$tab}!A{$rowNumber}";
    $body  = new \Google\Service\Sheets\ValueRange(['values' => [$row]]);
    $svc->spreadsheets_values->update(
        SPREADSHEET_ID, $range, $body,
        ['valueInputOption' => 'RAW']
    );
}

function sheetFindRow(string $tab, int $colIndex, string $value): ?array {
    $rows = sheetRead($tab);
    foreach ($rows as $i => $row) {
        if (isset($row[$colIndex]) && $row[$colIndex] === $value) {
            return ['row' => $row, 'rowNumber' => $i + 1];
        }
    }
    return null;
}

// MASTER DATA

function getCounsellors(): array {
    $rows = sheetRead(SHEET_COUNSELLORS, 'A2:E200');
    $out  = [];
    foreach ($rows as $r) {
        $name  = isset($r[2]) ? trim($r[2]) : '';
        $email = isset($r[4]) ? trim($r[4]) : '';
        if ($name !== '' && $email !== '') {
            $out[] = ['name' => $name, 'email' => $email];
        }
    }
    return $out;
}

function getCounsellorEmail(string $name): ?string {
    foreach (getCounsellors() as $c) {
        if (strcasecmp($c['name'], $name) === 0) return $c['email'];
    }
    return null;
}

function getPrograms(): array {
    return array_column(getProgramsDetailed(), 'label');
}

/**
 * Reads the Product List sheet.
 *
 * CONFIRMED SHEET LAYOUT (Product List tab):
 *   Col I [0] = Major                e.g. "SINGLE"
 *   Col J [1] = Degree               e.g. "DBM"
 *   Col K [2] = Degree Description   e.g. "Diploma in Business Management"
 *   Col L [3] = Product Code         e.g. "ANCAUSLTRDPBMSINGLEDBM"  ← real code
 *
 * The counsellor dropdown label = "Degree Description (Major - Degree)"
 * e.g. "Diploma in Business Management (SINGLE - DBM)"
 * The product code from col L is embedded in the option directly.
 */
function getProgramsDetailed(): array {
    $rows = sheetRead(SHEET_PRODUCTS, 'I2:L500');  // Col I=Major, J=Degree, K=Description, L=Product Code
    $out  = [];
    $seen = [];

    foreach ($rows as $r) {
        $major       = isset($r[0]) ? trim((string)$r[0]) : '';  // Col I
        $degree      = isset($r[1]) ? trim((string)$r[1]) : '';  // Col J
        $degreeDesc  = isset($r[2]) ? trim((string)$r[2]) : '';  // Col K - human-readable name (not used in label anymore)
        $productCode = isset($r[3]) ? trim((string)$r[3]) : '';  // Col L - the real product code

        // Skip rows with no product code
        if ($productCode === '') continue;

        // Display label: use "Major - Degree" format
        $label = $major . ' - ' . $degree;

        // Deduplicate by product code (a product code must appear only once)
        if (isset($seen[$productCode])) continue;
        $seen[$productCode] = true;

        $out[] = [
            'major'        => $major,        // Col I  e.g. "SINGLE"
            'degree'       => $degree,       // Col J  e.g. "DBM"
            'description'  => $degreeDesc,   // Col K  e.g. "Diploma in Business Management"
            'product_code' => $productCode,  // Col L  e.g. "ANCAUSLTRDPBMSINGLEDBM"
            'level'        => $label,        // backward-compat alias
            'label'        => $label,        // what the dropdown shows = "Major - Degree"
        ];
    }
    return $out;
}

/**
 * Find programme details by the label the counsellor selected.
 * Also tries matching against product_code directly (handles old tokens).
 */
function getProgramDetailsByLabel(string $label): ?array {
    $needle = trim($label);
    if ($needle === '') return null;

    $all = getProgramsDetailed();

    // Exact label match (normal case)
    foreach ($all as $p) {
        if (strcasecmp($p['label'], $needle) === 0) return $p;
    }
    // Match against product code (handles old tokens that stored code as label)
    foreach ($all as $p) {
        if (strcasecmp($p['product_code'], $needle) === 0) return $p;
    }
    // Match against degree code
    foreach ($all as $p) {
        if (strcasecmp($p['degree'], $needle) === 0) return $p;
    }
    return null;
}

/**
 * Look up a product code by programme label or degree code.
 * This overrides the version in product_documents.php and uses the
 * corrected sheet column mapping (col I = name, col K = product code).
 */
function getProductCodeFromLabel(string $label): ?string {
    $p = getProgramDetailsByLabel($label);
    return ($p && $p['product_code'] !== '') ? $p['product_code'] : null;
}

// TOKEN STORE (Google Sheets as database - NO SESSIONS)

/**
 * CF_Tokens columns:
 *   A=token | B=cf_number | C=student_name | D=student_email |
 *   E=counsellor_name | F=counsellor_email | G=created_at | H=status |
 *   I=otp | J=otp_time | K=phase
 */
/**
 * CF_Tokens columns mapping helper for MySQL
 */
function getCounsellorTokenColumnName(int $colIndex): ?string {
    $map = [
        1 => 'cf_number',
        2 => 'student_name',
        3 => 'student_email',
        4 => 'counsellor_name',
        5 => 'counsellor_email',
        6 => 'created_at',
        7 => 'status',
        8 => 'otp',
        9 => 'otp_time',
        10 => 'phase'
    ];
    return $map[$colIndex] ?? null;
}

function saveCounsellorToken(string $token, array $data): void {
    $now = date('Y-m-d H:i:s');
    // 1. Google Sheets
    sheetAppend(SHEET_CF_TOKENS, [
        $token,
        $data['cf_number'],
        $data['name'],
        $data['student_email'],
        $data['counsellor_name'],
        $data['counsellor_email'],
        $now,
        'pending',      // H = status
        '',             // I = otp
        '',             // J = otp_time
        'otp_request',  // K = phase
    ]);

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO cf_tokens (token, cf_number, student_name, student_email, counsellor_name, counsellor_email, created_at, status, otp, otp_time, phase) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', '', '', 'otp_request')");
        $stmt->execute([
            $token,
            $data['cf_number'],
            $data['name'],
            $data['student_email'],
            $data['counsellor_name'],
            $data['counsellor_email'],
            $now
        ]);
    } catch (Exception $e) {
        error_log("DB insert error (cf_tokens): " . $e->getMessage());
    }
}

function getCounsellorToken(string $token): ?array {
    $found = sheetFindRow(SHEET_CF_TOKENS, 0, $token);
    if (!$found) return null;
    $r = $found['row'];
    return [
        'token'            => $r[0]  ?? '',
        'cf_number'        => $r[1]  ?? '',
        'name'             => $r[2]  ?? '',
        'student_email'    => $r[3]  ?? '',
        'counsellor_name'  => $r[4]  ?? '',
        'counsellor_email' => $r[5]  ?? '',
        'created_at'       => $r[6]  ?? '',
        'status'           => $r[7]  ?? '',
        'otp'              => $r[8]  ?? '',
        'otp_time'         => $r[9]  ?? '',
        'phase'            => $r[10] ?? 'otp_request',
        '_rowNumber'       => $found['rowNumber'],
    ];
}

function updateCounsellorTokenField(string $token, int $colIndex, string $value): void {
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_CF_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) <= $colIndex) $row[] = '';
        $row[$colIndex] = $value;
        sheetUpdateRow(SHEET_CF_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    $colName = getCounsellorTokenColumnName($colIndex);
    if ($colName) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("UPDATE cf_tokens SET `{$colName}` = ? WHERE token = ?");
            $stmt->execute([$value, $token]);
        } catch (Exception $e) {
            error_log("DB update error (cf_tokens): " . $e->getMessage());
        }
    }
}

function setCounsellorOTP(string $token, string $otp): void {
    $nowTime = (string) time();
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_CF_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) < 11) $row[] = '';
        $row[8] = $otp;
        $row[9] = $nowTime;
        sheetUpdateRow(SHEET_CF_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE cf_tokens SET otp = ?, otp_time = ? WHERE token = ?");
        $stmt->execute([$otp, $nowTime, $token]);
    } catch (Exception $e) {
        error_log("DB otp update error (cf_tokens): " . $e->getMessage());
    }
}

function setCounsellorPhase(string $token, string $phase): void {
    updateCounsellorTokenField($token, 10, $phase);
}

function markCounsellorTokenUsed(string $token): void {
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_CF_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) < 11) $row[] = '';
        $row[7]  = 'used';
        $row[10] = 'done';
        sheetUpdateRow(SHEET_CF_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE cf_tokens SET status = 'used', phase = 'done' WHERE token = ?");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        error_log("DB status update error (cf_tokens): " . $e->getMessage());
    }
}


/**
 * Student_Tokens columns mapping helper for MySQL
 */
function getStudentTokenColumnName(int $colIndex): ?string {
    $map = [
        1 => 'cf_number',
        2 => 'student_name',
        3 => 'student_email',
        4 => 'counsellor_name',
        5 => 'program',
        6 => 'product_code',
        7 => 'created_at',
        8 => 'status',
        9 => 'otp',
        10 => 'otp_time',
        11 => 'phase'
    ];
    return $map[$colIndex] ?? null;
}

function saveStudentToken(string $token, array $data): void {
    $now = date('Y-m-d H:i:s');
    // 1. Google Sheets
    sheetAppend(SHEET_STUDENT_TOKENS, [
        $token,
        $data['cf_number'],
        $data['name'],
        $data['student_email'],
        $data['counsellor_name'],
        $data['program'],
        $data['product_code'] ?? '',
        $now,
        'pending',
        '',
        '',
        'otp_request',
    ]);

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO student_tokens (token, cf_number, student_name, student_email, counsellor_name, program, product_code, created_at, status, otp, otp_time, phase) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', '', '', 'otp_request')");
        $stmt->execute([
            $token,
            $data['cf_number'],
            $data['name'],
            $data['student_email'],
            $data['counsellor_name'],
            $data['program'],
            $data['product_code'] ?? '',
            $now
        ]);
    } catch (Exception $e) {
        error_log("DB insert error (student_tokens): " . $e->getMessage());
    }
}

function getStudentToken(string $token): ?array {
    $found = sheetFindRow(SHEET_STUDENT_TOKENS, 0, $token);
    if (!$found) return null;
    $r = $found['row'];
    return [
        'token'           => $r[0]  ?? '',
        'cf_number'       => $r[1]  ?? '',
        'name'            => $r[2]  ?? '',
        'student_email'   => $r[3]  ?? '',
        'counsellor_name' => $r[4]  ?? '',
        'program'         => $r[5]  ?? '',
        'product_code'    => $r[6]  ?? '',
        'created_at'      => $r[7]  ?? '',
        'status'          => $r[8]  ?? '',
        'otp'             => $r[9]  ?? '',
        'otp_time'        => $r[10] ?? '',
        'phase'           => $r[11] ?? 'otp_request',
        '_rowNumber'      => $found['rowNumber'],
    ];
}

function updateStudentTokenField(string $token, int $colIndex, string $value): void {
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_STUDENT_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) <= $colIndex) $row[] = '';
        $row[$colIndex] = $value;
        sheetUpdateRow(SHEET_STUDENT_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    $colName = getStudentTokenColumnName($colIndex);
    if ($colName) {
        try {
            $db = getDBConnection();
            $stmt = $db->prepare("UPDATE student_tokens SET `{$colName}` = ? WHERE token = ?");
            $stmt->execute([$value, $token]);
        } catch (Exception $e) {
            error_log("DB update error (student_tokens): " . $e->getMessage());
        }
    }
}

function setStudentOTP(string $token, string $otp): void {
    $nowTime = (string) time();
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_STUDENT_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) < 12) $row[] = '';
        $row[9]  = $otp;
        $row[10] = $nowTime;
        sheetUpdateRow(SHEET_STUDENT_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE student_tokens SET otp = ?, otp_time = ? WHERE token = ?");
        $stmt->execute([$otp, $nowTime, $token]);
    } catch (Exception $e) {
        error_log("DB otp update error (student_tokens): " . $e->getMessage());
    }
}

function setStudentPhase(string $token, string $phase): void {
    updateStudentTokenField($token, 11, $phase);
}

function markStudentTokenUsed(string $token): void {
    // 1. Google Sheets
    $found = sheetFindRow(SHEET_STUDENT_TOKENS, 0, $token);
    if ($found) {
        $row = $found['row'];
        while (count($row) < 12) $row[] = '';
        $row[8]  = 'used';
        $row[11] = 'done';
        sheetUpdateRow(SHEET_STUDENT_TOKENS, $found['rowNumber'], $row);
    }

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("UPDATE student_tokens SET status = 'used', phase = 'done' WHERE token = ?");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        error_log("DB status update error (student_tokens): " . $e->getMessage());
    }
}


/**
 * Appends a submission row.
 * Columns: timestamp | token | cf_number | student_name | student_email |
 *          program_level | degree_description | product_code |
 *          doc1_path | doc2_path | … | docN_path | agreement_path
 *
 * Fixed: now writes ALL dynamic doc paths instead of only 3.
 */
function appendSubmission(array $data): void {
    $now = date('Y-m-d H:i:s');
    $row = [
        $now,
        $data['token']              ?? '',
        $data['cf_number']          ?? '',
        $data['student_name']       ?? '',
        $data['student_email']      ?? '',
        $data['program_level']      ?? '',
        $data['degree_description'] ?? '',
        $data['product_code']       ?? '',
    ];

    // Collect all docN_path keys in numeric order
    $docPaths = [];
    foreach ($data as $key => $val) {
        if (preg_match('/^doc(\d+)_path$/', $key, $m)) {
            $docPaths[(int)$m[1]] = $val;
        }
    }
    ksort($docPaths);
    foreach ($docPaths as $path) {
        $row[] = $path;
    }

    $agreementPath = $data['agreement_path'] ?? '';
    $row[] = $agreementPath;

    // 1. Google Sheets
    sheetAppend(SHEET_SUBMISSIONS, $row);

    // 2. MySQL
    try {
        $db = getDBConnection();
        $stmt = $db->prepare("INSERT INTO submissions (timestamp, token, cf_number, student_name, student_email, program_level, degree_description, product_code, agreement_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $now,
            $data['token']              ?? '',
            $data['cf_number']          ?? '',
            $data['student_name']       ?? '',
            $data['student_email']      ?? '',
            $data['program_level']      ?? '',
            $data['degree_description'] ?? '',
            $data['product_code']       ?? '',
            $agreementPath
        ]);

        $submissionId = $db->lastInsertId();

        if ($submissionId) {
            $stmtDoc = $db->prepare("INSERT INTO submission_documents (submission_id, document_slot, file_path) VALUES (?, ?, ?)");
            foreach ($docPaths as $index => $path) {
                if ($path !== '') {
                    $stmtDoc->execute([$submissionId, "doc" . $index, $path]);
                }
            }
        }
    } catch (Exception $e) {
        error_log("DB insert error (submissions): " . $e->getMessage());
    }
}


function storeUploadedFile(array $file, string $tokenPrefix, string $slot, string $baseName = ''): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Upload failed for {$slot}.");
    }
    $maxSize = 10 * 1024 * 1024; // 10 MB
    if (($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException("{$slot} exceeds 10MB limit.");
    }
    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException("{$slot} type is not allowed.");
    }
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $tokenPrefix) ?: 'student';
    $safeSlot   = preg_replace('/[^a-zA-Z0-9_-]/', '', $slot)        ?: 'file';

    if ($baseName !== '') {
        $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '', $baseName) ?: ($safePrefix . '_' . $safeSlot);
        $filename = $safeBase . '.' . $ext;
    } else {
        $filename = sprintf('%s_%s_%s.%s', $safePrefix, $safeSlot, bin2hex(random_bytes(6)), $ext);
    }

    $destPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;
    $counter  = 2;
    while (file_exists($destPath)) {
        $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
        $filename = sprintf('%s_%d.%s', $nameOnly, $counter, $ext);
        $destPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $counter++;
    }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException("Unable to save {$slot}.");
    }
    return 'uploads/' . $filename;
}

// UTILITIES

function generateToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function generateOTP(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendEmail(string $to, string $subject, string $htmlBody): bool {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . $e->getMessage());
        return false;
    }
}

function emailHtml(string $title, string $body, string $btnLabel = '', string $btnUrl = ''): string {
    $btn = '';
    if ($btnLabel && $btnUrl) {
        $btn = "<div style='text-align:center;margin:32px 0;'>
            <a href='{$btnUrl}' style='display:inline-block;padding:15px 40px;
            background:linear-gradient(135deg,#0A2463,#1447B8);color:#fff;
            text-decoration:none;border-radius:12px;font-weight:700;font-size:15px;
            font-family:sans-serif;letter-spacing:0.3px;'>
                {$btnLabel} &rarr;
            </a></div>
            <p style='text-align:center;font-size:12px;color:#94A3B8;'>
                Or copy: <a href='{$btnUrl}' style='color:#2563EB;'>{$btnUrl}</a>
            </p>";
    }
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
    <body style='margin:0;padding:0;background:#F1F5F9;font-family:sans-serif;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='padding:48px 20px;'>
    <tr><td align='center'>
    <table width='580' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:20px;
        overflow:hidden;box-shadow:0 8px 40px rgba(10,36,99,0.12);'>
      <tr><td style='background:linear-gradient(135deg,#0A2463 0%,#1447B8 100%);padding:32px 48px;'>
        <table cellpadding='0' cellspacing='0'>
          <tr>
            <td style='width:52px;height:52px;background:rgba(255,255,255,0.15);border-radius:12px;
                text-align:center;vertical-align:middle;'>
              <span style='font-family:Georgia,serif;font-weight:900;color:#fff;font-size:22px;'>ANC</span>
            </td>
            <td style='padding-left:16px;'>
              <div style='font-family:Georgia,serif;font-size:20px;font-weight:700;color:#fff;'>ANC Student Docs</div>
              <div style='font-size:10px;color:rgba(255,255,255,0.55);letter-spacing:2px;text-transform:uppercase;margin-top:3px;'>Document Portal</div>
            </td>
          </tr>
        </table>
      </td></tr>
      <tr><td style='padding:40px 48px;'>
        <h2 style='font-family:Georgia,serif;font-size:24px;color:#0A2463;margin:0 0 20px;'>{$title}</h2>
        {$body}
        {$btn}
      </td></tr>
      <tr><td style='background:#F8FAFC;padding:20px 48px;border-top:1px solid #E2E8F0;text-align:center;'>
        <p style='margin:0;font-size:12px;color:#94A3B8;'>
            &copy; " . date('Y') . " ANC Education &middot; Secure Document Portal
        </p>
      </td></tr>
    </table>
    </table></tr>
    </body></html>";
}

function otpEmailHtml(string $recipientName, string $otp, string $role): string {
    $roleLabel = $role === 'counsellor' ? 'Counsellor Portal' : 'Student Portal';
    return emailHtml(
        "Your One-Time Verification Code",
        "<p style='font-size:15px;color:#334155;line-height:1.7;margin-bottom:24px;'>
            Dear <strong>{$recipientName}</strong>,<br><br>
            Use the code below to verify your identity and access the ANC <strong>{$roleLabel}</strong>.
            This code expires in <strong>10 minutes</strong>.
        </p>
        <div style='background:#EFF6FF;border:2px dashed #BFDBFE;border-radius:16px;
            padding:32px;text-align:center;margin-bottom:24px;'>
            <div style='font-size:11px;font-weight:700;letter-spacing:3px;color:#64748B;
                text-transform:uppercase;margin-bottom:12px;'>Your OTP Code</div>
            <div style='font-size:48px;font-weight:900;letter-spacing:16px;color:#0A2463;
                font-family:monospace;'>{$otp}</div>
        </div>
        <p style='font-size:13px;color:#94A3B8;text-align:center;'>
            If you did not request this, please ignore this email.
        </p>"
    );
}
?>