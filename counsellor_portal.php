<?php
require 'config.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!$token) {
    die(errorPage("Missing Token", "No token provided. Please use the link from your email."));
}

try {
    $tokenData = getCounsellorToken($token);
} catch (Exception $e) {
    die(errorPage("Database Error", "Unable to verify token. Please try again."));
}

if (!$tokenData) {
    die(errorPage("Invalid Link", "This link is invalid or has already been used. Please ask CF to resend."));
}
if ($tokenData['status'] === 'used') {
    die(errorPage("Link Already Used", "This counsellor link has already been used. The student has been notified."));
}

$counsellor_name  = $tokenData['counsellor_name'];
$counsellor_email = $tokenData['counsellor_email'];
$cf_number        = $tokenData['cf_number'];
$student_name     = $tokenData['name'];
$student_email    = $tokenData['student_email'];

// Phase is stored in the token data - NO SESSIONS
$phase = $tokenData['phase'];

$error   = '';
$success = '';

// POST: Request OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_otp') {
    $otp = generateOTP();
    setCounsellorOTP($token, $otp);
    setCounsellorPhase($token, 'otp_verify');
    $html = otpEmailHtml($counsellor_name, $otp, 'counsellor');
    if (sendEmail($counsellor_email, 'ANC - Your Verification Code', $html)) {
        $phase = 'otp_verify';
        $success = "OTP sent to <strong>$counsellor_email</strong>. Check your inbox.";
    } else {
        $error = "Failed to send OTP email. Please try again.";
    }
}

// POST: Verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $entered  = trim($_POST['otp'] ?? '');
    $fresh    = getCounsellorToken($token);
    $stored   = $fresh['otp']      ?? '';
    $otpTime  = (int)($fresh['otp_time'] ?? 0);
    $elapsed  = time() - $otpTime;

    if ($stored && $entered === $stored && $elapsed <= 600) {
        setCounsellorPhase($token, 'programme');
        $phase = 'programme';
    } else {
        $error = $elapsed > 600
            ? "OTP has expired (10 min limit). Please request a new one."
            : "Incorrect OTP. Please check and try again.";
        $phase = 'otp_verify';
    }
}

// POST: Submit programme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_programme') {
    $freshToken = getCounsellorToken($token);
    if ($freshToken['phase'] !== 'programme') {
        $error = "Please verify your OTP first.";
        $phase = $freshToken['phase'];
    } else {
        $program = trim($_POST['program'] ?? '');
        if (!$program) {
            $error = "Please select a programme.";
            $phase = 'programme';
        } else {
            // Product code is submitted directly from the JS hidden field —
            // no Sheet lookup needed. The JS embeds it from the PROGRAMS array.
            $productCode = trim($_POST['product_code'] ?? '');

            // Fallback: if JS didn't send it, look it up from getProgramsDetailed()
            if ($productCode === '') {
                $programDetails = getProgramDetailsByLabel($program);
                $productCode    = $programDetails['product_code'] ?? '';
            }
            // Last resort: legacy Sheet column lookup
            if ($productCode === '') {
                $productCode = getProductCodeFromLabel($program) ?? '';
            }

            $studentToken = generateToken();
            saveStudentToken($studentToken, [
                'cf_number'       => $cf_number,
                'name'            => $student_name,
                'student_email'   => $student_email,
                'counsellor_name' => $counsellor_name,
                'program'         => $program,
                'product_code'    => $productCode,
            ]);

            markCounsellorTokenUsed($token);
            $phase = 'done';

            $studentLink = BASE_URL . 'registration_form.php?token=' . $studentToken;
            $html = emailHtml(
                "Action Required: Student Registration",
                "<p style='font-size:15px;color:#334155;line-height:1.7;margin-bottom:20px;'>
                    Dear <strong>{$student_name}</strong>,<br><br>
                    Your student registration link is ready.
                    Please open the Student Portal, enter your email, and verify with OTP before uploading documents.
                </p>
                <table style='width:100%;border-collapse:collapse;margin-bottom:8px;border-radius:10px;overflow:hidden;border:1px solid #E2E8F0;'>
                  <tr style='background:#F8FAFC;'>
                    <td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:1px;width:40%;border-bottom:1px solid #E2E8F0;'>CF Number</td>
                    <td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0A2463;border-bottom:1px solid #E2E8F0;'>{$cf_number}</td>
                  </tr>
                  <tr>
                    <td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:1px;'>Student Email</td>
                    <td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0A2463;'>{$student_email}</td>
                  </tr>
                </table>",
                "Open Student Portal",
                $studentLink
            );
            sendEmail($student_email, "ANC - Upload Your Documents [{$cf_number}]", $html);
        }
    }
}

$programs = [];
if ($phase === 'programme') {
    try {
        // Build objects with label + product_code so JS can submit the code directly
        $seen = [];
        foreach (getProgramsDetailed() as $p) {
            $lbl = $p['label'] ?? '';
            if ($lbl === '' || isset($seen[$lbl])) continue;
            $seen[$lbl] = true;
            $programs[] = ['label' => $lbl, 'product_code' => $p['product_code'] ?? ''];
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

function errorPage(string $title, string $msg): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>ANC — Error</title>
    <link href='https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500&display=swap' rel='stylesheet'>
    <style>body{font-family:'DM Sans',sans-serif;background:#F1F5F9;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
    .box{background:#fff;border-radius:20px;padding:48px;max-width:480px;text-align:center;box-shadow:0 8px 40px rgba(10,36,99,0.10);border:1px solid #E2E8F0;}
    h2{font-family:'Playfair Display',serif;color:#0A2463;margin-bottom:12px;}p{color:#64748B;font-size:14px;line-height:1.7;}</style>
    </head><body><div class='box'>
    <svg width='48' height='48' fill='none' viewBox='0 0 24 24' stroke='#DC2626' stroke-width='1.5' style='margin:0 auto 20px;display:block;'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg>
    <h2>{$title}</h2><p>{$msg}</p>
    </div></body></html>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANC — Counsellor Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0A2463;--blue:#1447B8;--accent:#2563EB;--sky:#38BDF8;
  --white:#fff;--bg:#F1F5F9;--border:#E2E8F0;--muted:#94A3B8;--text:#334155;
  --green:#16A34A;--red:#DC2626;--r:14px;--shadow:0 8px 40px rgba(10,36,99,0.10);
}
body{min-height:100vh;background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text);display:flex;flex-direction:column;}

.topbar{position:sticky;top:0;z-index:100;height:64px;background:var(--white);border-bottom:1px solid var(--border);box-shadow:0 2px 10px rgba(10,36,99,0.06);display:flex;align-items:center;justify-content:space-between;padding:0 40px;}
.brand-logo{display:flex;align-items:center;gap:12px;}
.logo-mark{width:38px;height:38px;background:linear-gradient(135deg,var(--blue),var(--accent));border-radius:9px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(37,99,235,0.30);}
.logo-mark svg{width:22px;height:22px;}
.logo-name{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--navy);line-height:1.1;}
.logo-tag{font-size:9px;color:var(--accent);letter-spacing:2px;text-transform:uppercase;margin-top:2px;font-weight:700;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.email-chip{display:flex;align-items:center;gap:8px;background:#EFF6FF;border:1.5px solid #DBEAFE;color:var(--blue);font-size:13px;font-weight:600;padding:6px 14px;border-radius:40px;}
.email-chip svg{opacity:0.6;}

.progress{background:var(--white);border-bottom:1px solid var(--border);padding:0 40px;display:flex;align-items:center;height:52px;gap:0;}
.prog-step{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600;color:var(--muted);padding:0 20px;height:100%;border-bottom:3px solid transparent;transition:all 0.2s;}
.prog-step.active{color:var(--blue);border-bottom-color:var(--blue);}
.prog-step.done{color:var(--green);}
.prog-num{width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;background:var(--bg);border:1.5px solid var(--border);}
.prog-step.active .prog-num{background:var(--blue);border-color:var(--blue);color:#fff;}
.prog-step.done .prog-num{background:var(--green);border-color:var(--green);color:#fff;}
.prog-sep{width:28px;height:1.5px;background:var(--border);flex-shrink:0;}

.wrap{max-width:620px;margin:48px auto;padding:0 20px 60px;width:100%;}

.alert{display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-radius:var(--r);font-size:14px;margin-bottom:24px;animation:slideDown 0.3s ease;}
.alert-success{background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;}
.alert-error  {background:#FEF2F2;border:1px solid #FECACA;color:var(--red);}
.alert svg{flex-shrink:0;margin-top:1px;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

.card{background:var(--white);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:var(--shadow);}
.card-head{padding:32px 40px;}
.card-head.green{background:linear-gradient(135deg,#15803D,#16A34A);}
.card-head.blue{background:linear-gradient(135deg,var(--navy),var(--blue));}
.card-head-eyebrow{font-size:10px;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,0.45);margin-bottom:8px;}
.card-head-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:#fff;margin-bottom:6px;}
.card-head-sub{font-size:13px;color:rgba(255,255,255,0.60);line-height:1.6;}
.card-body{padding:36px 40px;}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px;}
.info-item{background:#F8FAFC;border:1px solid var(--border);border-radius:10px;padding:12px 16px;}
.info-label{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:3px;}
.info-val{font-size:13px;font-weight:700;color:var(--navy);}

.sec{font-size:10px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:10px;margin-bottom:18px;}
.sec::after{content:'';flex:1;height:1px;background:var(--border);}
.divider{border:none;border-top:1px solid var(--border);margin:8px 0 24px;}

.otp-row{display:flex;gap:10px;justify-content:center;margin-bottom:24px;}
.otp-box{width:54px;height:64px;background:#F8FAFC;border:2px solid var(--border);border-radius:12px;text-align:center;font-size:28px;font-weight:800;color:var(--navy);font-family:'DM Mono',monospace;outline:none;transition:all 0.2s;}
.otp-box:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,0.10);}
.otp-box.filled{border-color:var(--accent);background:#EFF6FF;}

.search-select-wrap{position:relative;}
.ss-input{width:100%;padding:12px 42px 12px 42px;background:#F8FAFC;border:1.5px solid var(--border);border-radius:var(--r);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--navy);outline:none;transition:all 0.2s;cursor:pointer;}
.ss-input:focus,.ss-input.open{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,0.10);}
.ss-input.open{border-radius:var(--r) var(--r) 0 0;}
.ss-ico{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--accent);pointer-events:none;}
.ss-chev{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none;transition:transform 0.2s;}
.ss-input.open ~ .ss-chev{transform:translateY(-50%) rotate(180deg);}
.ss-dropdown{position:relative;top:100%;left:0;right:0;z-index:50;background:#fff;border:1.5px solid var(--accent);border-top:none;border-radius:0 0 var(--r) var(--r);box-shadow:0 12px 32px rgba(10,36,99,0.12);max-height:240px;overflow-y:auto;display:none;}
.ss-dropdown.open{display:block;}
.ss-option{padding:11px 16px;font-size:14px;color:var(--navy);cursor:pointer;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);transition:background 0.1s;}
.ss-option:last-child{border-bottom:none;}
.ss-option:hover,.ss-option.hl{background:#EFF6FF;color:var(--blue);}
.ss-empty{padding:20px;text-align:center;color:var(--muted);font-size:13px;}

.field{margin-bottom:20px;}
.label{display:block;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--navy);margin-bottom:7px;opacity:0.75;}

.btn{width:100%;padding:15px;border:none;border-radius:var(--r);font-family:'DM Sans',sans-serif;font-size:15px;font-weight:700;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:transform 0.15s,box-shadow 0.15s;}
.btn-navy{background:linear-gradient(135deg,var(--navy),var(--accent));box-shadow:0 6px 24px rgba(20,71,184,0.28);}
.btn-navy:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(10,36,99,0.30);}
.btn-green{background:linear-gradient(135deg,#15803D,#16A34A);box-shadow:0 6px 24px rgba(22,163,74,0.25);}
.btn-green:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(22,163,74,0.32);}
.btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text);box-shadow:none;}
.btn-outline:hover{border-color:var(--accent);color:var(--blue);background:#EFF6FF;}
.btn:active{transform:translateY(0);}
.btn-row{display:flex;gap:12px;margin-top:8px;}

.success-icon{width:80px;height:80px;border-radius:50%;background:#F0FDF4;border:2px solid #BBF7D0;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;animation:popIn 0.5s 0.1s cubic-bezier(.22,.68,0,1.5) both;}
@keyframes popIn{from{opacity:0;transform:scale(0.5)}to{opacity:1;transform:scale(1)}}

@media(max-width:640px){.topbar{padding:0 20px;}.progress{padding:0 16px;}.card-body{padding:24px 20px;}.card-head{padding:24px 20px;}.info-grid{grid-template-columns:1fr;}.otp-box{width:42px;height:56px;font-size:22px;}}
</style>
</head>
<body>

<div class="topbar">
    <div class="brand-logo">
        <div class="logo-mark">
            <svg viewBox="0 0 26 26" fill="none">
                <path d="M13 4L2 9.5L13 15L24 9.5L13 4Z" fill="white" stroke="white" stroke-width="1.2" stroke-linejoin="round"/>
                <path d="M7 12.5V18C7 18 9.5 20 13 20C16.5 20 19 18 19 18V12.5" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M24 9.5V15" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </div>
        <div>
            <div class="logo-name">ANC Student Docs</div>
            <div class="logo-tag">Document Portal</div>
        </div>
    </div>
    <div class="topbar-right">
        <div class="email-chip">
            <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            <?= htmlspecialchars($counsellor_email) ?>
        </div>
    </div>
</div>

<div class="progress">
  <div class="prog-step done"><div class="prog-num">✓</div>CF Department</div>
  <div class="prog-sep"></div>
  <div class="prog-step active"><div class="prog-num">2</div>Counsellor</div>
  <div class="prog-sep"></div>
  <div class="prog-step"><div class="prog-num">3</div>Student</div>
</div>

<div class="wrap">

  <?php if ($success): ?>
  <div class="alert alert-success">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span><?= $success ?></span>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= $error ?></span>
  </div>
  <?php endif; ?>

  <?php if ($phase === 'otp_request'): ?>
  <div class="card">
    <div class="card-head blue">
      <div class="card-head-eyebrow">Step 2 · Identity Verification</div>
      <div class="card-head-title">Welcome, <?= htmlspecialchars($counsellor_name) ?></div>
      <div class="card-head-sub">To access your counsellor portal, we need to verify your identity. An OTP will be sent to your registered email.</div>
    </div>
    <div class="card-body">
      <div class="info-grid">
        <div class="info-item"><div class="info-label">CF Number</div><div class="info-val"><?= htmlspecialchars($cf_number) ?></div></div>
        <div class="info-item"><div class="info-label">Student</div><div class="info-val"><?= htmlspecialchars($student_name) ?></div></div>
        <div class="info-item" style="grid-column:1/-1;"><div class="info-label">Your Email</div><div class="info-val"><?= htmlspecialchars($counsellor_email) ?></div></div>
      </div>
      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="request_otp">
        <button class="btn btn-navy" type="submit">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Send OTP to My Email
        </button>
      </form>
    </div>
  </div>

  <?php elseif ($phase === 'otp_verify'): ?>
  <div class="card">
    <div class="card-head blue">
      <div class="card-head-eyebrow">Step 2 · Identity Verification</div>
      <div class="card-head-title">Enter Your OTP</div>
      <div class="card-head-sub">We sent a 6-digit code to <strong style="color:#fff;"><?= htmlspecialchars($counsellor_email) ?></strong></div>
    </div>
    <div class="card-body" style="text-align:center;">
      <form method="POST" id="otpForm">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp"    id="otpHidden">
        <div style="margin-bottom:20px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:14px;">Enter 6-digit code</div>
          <div class="otp-row">
            <?php for($i=0;$i<6;$i++): ?><input class="otp-box" type="text" maxlength="1" inputmode="numeric"><?php endfor; ?>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn btn-navy" type="submit">
            Verify & Continue
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>
      </form>
      <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="request_otp">
        <button type="submit" class="btn btn-outline" style="max-width:220px;margin:0 auto;font-size:13px;padding:10px;">
          ↺ Resend OTP
        </button>
      </form>
    </div>
  </div>

  <?php elseif ($phase === 'programme'): ?>
  <div class="card">
    <div class="card-head green">
      <div class="card-head-eyebrow">Step 2 · Programme Selection</div>
      <div class="card-head-title">Select Programme</div>
      <div class="card-head-sub">Identity verified ✓ — Now assign the appropriate programme for this student.</div>
    </div>
    <div class="card-body">
      <div class="sec">Student Details</div>
      <div class="info-grid" style="margin-bottom:24px;">
        <div class="info-item"><div class="info-label">CF Number</div><div class="info-val"><?= htmlspecialchars($cf_number) ?></div></div>
        <div class="info-item"><div class="info-label">Student Name</div><div class="info-val"><?= htmlspecialchars($student_name) ?></div></div>
        <div class="info-item" style="grid-column:1/-1;"><div class="info-label">Student Email</div><div class="info-val"><?= htmlspecialchars($student_email) ?></div></div>
      </div>

      <hr class="divider">
      <div class="sec">Programme Assignment</div>

      <form method="POST" onsubmit="return validateProg()">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="submit_programme">
        <input type="hidden" name="program" id="programHidden">
        <input type="hidden" name="product_code" id="productCodeHidden">

        <div class="field">
          <label class="label">Search & Select Programme</label>
          <div class="search-select-wrap">
            <svg class="ss-ico" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>
            <input type="text" class="ss-input" id="progSearch" placeholder="Type to search programme…" autocomplete="off">
            <svg class="ss-chev" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            <div class="ss-dropdown" id="progDropdown"></div>
          </div>
          <div id="progPreview" style="display:none;background:#EFF6FF;border:1.5px solid #BFDBFE;border-radius:var(--r);padding:12px 16px;margin-top:10px;font-size:14px;font-weight:600;color:var(--navy);animation:fadeIn 0.2s ease;">
            <span style="color:var(--muted);font-size:11px;display:block;margin-bottom:3px;">SELECTED</span>
            <span id="progPreviewText"></span>
          </div>
        </div>

        <div class="btn-row" style="margin-top:8px;">
          <button class="btn btn-green" type="submit">
            Confirm & Send Student Link
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php elseif ($phase === 'done'): ?>
  <div class="card">
    <div class="card-head green">
      <div class="card-head-eyebrow">Complete</div>
      <div class="card-head-title">All Done!</div>
      <div class="card-head-sub">The student has been notified by email.</div>
    </div>
    <div class="card-body" style="text-align:center;padding:48px 40px;">
      <div class="success-icon">
        <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="#16A34A" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h3 style="font-family:'Playfair Display',serif;font-size:22px;color:var(--navy);margin-bottom:10px;">Programme Assigned</h3>
      <p style="color:var(--muted);font-size:14px;line-height:1.7;max-width:380px;margin:0 auto;">
        A secure document upload link has been sent to <strong><?= htmlspecialchars($student_name) ?></strong>.
        Your task is complete — no further action is needed.
      </p>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
const otpBoxes = document.querySelectorAll('.otp-box');
const otpHidden = document.getElementById('otpHidden');
if (otpBoxes.length) {
  otpBoxes.forEach((b, i) => {
    b.addEventListener('input', () => {
      b.value = b.value.replace(/\D/g,'');
      b.classList.toggle('filled', !!b.value);
      if (b.value && i < otpBoxes.length - 1) otpBoxes[i+1].focus();
      if (otpHidden) otpHidden.value = [...otpBoxes].map(x=>x.value).join('');
    });
    b.addEventListener('keydown', e => {
      if (e.key === 'Backspace' && !b.value && i > 0) { otpBoxes[i-1].classList.remove('filled'); otpBoxes[i-1].focus(); }
    });
    b.addEventListener('paste', e => {
      e.preventDefault();
      const p = e.clipboardData.getData('text').replace(/\D/g,'').slice(0,6);
      p.split('').forEach((c,j) => { if(otpBoxes[j]){otpBoxes[j].value=c;otpBoxes[j].classList.add('filled');} });
      if (otpHidden) otpHidden.value = p;
    });
  });
  otpBoxes[0]?.focus();
}

// PROGRAMS is now an array of {label, product_code} objects
const PROGRAMS     = <?= json_encode($programs, JSON_UNESCAPED_UNICODE) ?>;
const progSearch   = document.getElementById('progSearch');
const progHidden   = document.getElementById('programHidden');
const progCodeHidden = document.getElementById('productCodeHidden');
const progDrop     = document.getElementById('progDropdown');
const progPreview  = document.getElementById('progPreview');
let selProg = null, hlProg = -1;

function renderProgDrop(q) {
  if (!progDrop) return;
  const list = q
    ? PROGRAMS.filter(p => p.label.toLowerCase().includes(q.toLowerCase()))
    : PROGRAMS;
  if (!list.length) {
    progDrop.innerHTML = '<div class="ss-empty">No programmes found</div>';
  } else {
    progDrop.innerHTML = list.map((p, i) =>
      `<div class="ss-option" data-label="${esc(p.label)}" data-code="${esc(p.product_code)}" data-idx="${i}">${esc(p.label)}</div>`
    ).join('');
    progDrop.querySelectorAll('.ss-option').forEach(o => {
      o.addEventListener('mousedown', e => {
        e.preventDefault();
        selectProg(o.dataset.label, o.dataset.code);
      });
    });
  }
  hlProg = -1;
}

function selectProg(label, code) {
  selProg = label;
  if (progHidden)     progHidden.value     = label;
  if (progCodeHidden) progCodeHidden.value = code;
  if (progSearch) { progSearch.value = label; progSearch.classList.remove('open'); }
  if (progDrop)   progDrop.classList.remove('open');
  if (progPreview) {
    progPreview.style.display = 'block';
    document.getElementById('progPreviewText').textContent = label;
  }
}

if (progSearch) {
  progSearch.addEventListener('focus', () => { renderProgDrop(progSearch.value); progDrop.classList.add('open'); progSearch.classList.add('open'); });
  progSearch.addEventListener('blur',  () => { setTimeout(() => { progDrop.classList.remove('open'); progSearch.classList.remove('open'); if(!selProg) progSearch.value=''; }, 150); });
  progSearch.addEventListener('input', () => {
    selProg = null;
    if (progHidden)     progHidden.value     = '';
    if (progCodeHidden) progCodeHidden.value = '';
    if (progPreview) progPreview.style.display = 'none';
    renderProgDrop(progSearch.value);
  });
  progSearch.addEventListener('keydown', e => {
    const opts = progDrop.querySelectorAll('.ss-option');
    if (e.key === 'ArrowDown') { e.preventDefault(); hlProg = Math.min(hlProg+1, opts.length-1); opts.forEach((o,i)=>o.classList.toggle('hl',i===hlProg)); opts[hlProg]?.scrollIntoView({block:'nearest'}); }
    else if (e.key === 'ArrowUp')  { e.preventDefault(); hlProg = Math.max(hlProg-1, 0); opts.forEach((o,i)=>o.classList.toggle('hl',i===hlProg)); }
    else if (e.key === 'Enter' && hlProg >= 0 && opts[hlProg]) { e.preventDefault(); selectProg(opts[hlProg].dataset.label, opts[hlProg].dataset.code); }
    else if (e.key === 'Escape') { progDrop.classList.remove('open'); progSearch.classList.remove('open'); }
  });
}

function validateProg() {
  if (!selProg) { if(progSearch){progSearch.style.borderColor='#DC2626';progSearch.focus();} return false; }
  return true;
}
function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
</body>
</html>