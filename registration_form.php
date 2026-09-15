<?php
require 'config.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
if ($token === '') {
    die('Missing student token.');
}

$studentToken = getStudentToken($token);
if (!$studentToken) {
    die('Invalid student link.');
}
if (($studentToken['status'] ?? '') === 'used') {
    die('This student link has already been completed.');
}

$error = '';
$success = '';
$phase = $studentToken['phase'] ?? 'otp_request';

$cfNumber = $studentToken['cf_number'] ?? '';
$studentName = $studentToken['name'] ?? '';
$studentEmail = $studentToken['student_email'] ?? '';
$programLabel = $studentToken['program'] ?? '';
$productCode = $studentToken['product_code'] ?? '';
$programDetails = getProgramDetailsByLabel($programLabel);
$programLevel = $programDetails['level'] ?? $programLabel;
$degreeDescription = $programDetails['description'] ?? '';

// ── Resolve product code and document checklist ──────────────────────────────
//
// The product_code stored in the Student Token should be a real code like
// "ANCAUSLTRDPBMSINGLEDBM". However older tokens (or misconfigured ones) may
// store the programme label (e.g. "Diploma in Business Management") instead.
// We detect that by checking whether getDocumentsForProduct() returns anything;
// if not, we fall back to looking the code up from the label.

$documentChecklist = [];

// Step 1: try the stored product code directly
if ($productCode !== '') {
    $documentChecklist = getDocumentsForProduct($productCode);
}

// Step 2: if nothing found — the stored value may be a label, not a real code.
// Look up the real code from getProgramsDetailed() (reads Sheet col K) or the
// static getProductCodeFromLabel() as a last resort.
if (empty($documentChecklist)) {
    // Try using the stored product_code as a label first (old-token case)
    $candidates = array_filter([
        $programLabel,  
        $productCode,   
    ]);
    foreach ($candidates as $candidate) {
        $det = getProgramDetailsByLabel($candidate);
        $resolvedCode = $det['product_code'] ?? '';
        if ($resolvedCode === '') {
            $resolvedCode = getProductCodeFromLabel($candidate) ?? '';
        }
        if ($resolvedCode !== '') {
            $productCode       = $resolvedCode;   
            $documentChecklist = getDocumentsForProduct($resolvedCode);
            if (!empty($documentChecklist)) break;
        }
    }
    if (empty($documentChecklist)) {
        error_log("Document checklist: No match for product_code='$productCode' label='$programLabel'");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $fresh = getStudentToken($token);
    if (!$fresh) {
        die('Invalid student link.');
    }
    $phase = $fresh['phase'] ?? 'otp_request';

    if ($action === 'request_otp') {
        $enteredEmail = strtolower(trim($_POST['student_email'] ?? ''));
        if ($enteredEmail === '' || strcasecmp($enteredEmail, $fresh['student_email']) !== 0) {
            $error = 'Email does not match this registration link.';
        } else {
            $otp = generateOTP();
            setStudentOTP($token, $otp);
            setStudentPhase($token, 'otp_verify');
            $html = otpEmailHtml($fresh['name'], $otp, 'student');
            if (sendEmail($fresh['student_email'], 'ANC - Your Verification Code', $html)) {
                $phase = 'otp_verify';
                $success = "OTP sent to <strong>" . htmlspecialchars($fresh['student_email']) . "</strong>.";
            } else {
                $error = 'Failed to send OTP. Please try again.';
            }
        }
    } elseif ($action === 'verify_otp') {
        $enteredOtp = trim($_POST['otp'] ?? '');
        $storedOtp = $fresh['otp'] ?? '';
        $otpTime = (int)($fresh['otp_time'] ?? 0);
        $elapsed = time() - $otpTime;
        if ($storedOtp !== '' && $enteredOtp === $storedOtp && $elapsed <= 600) {
            setStudentPhase($token, 'form');
            $phase = 'form';
        } else {
            $phase = 'otp_verify';
            $error = $elapsed > 600
                ? 'OTP has expired (10 minutes). Please request a new OTP.'
                : 'Invalid OTP. Please try again.';
        }
    } elseif ($action === 'submit_registration') {
        if (($fresh['phase'] ?? '') !== 'form') {
            $error = 'Please complete email verification and OTP first.';
            $phase = $fresh['phase'] ?? 'otp_request';
        } else {
            try {
                // Get document checklist — same robust resolution as the display section
                $fProductCode  = $fresh['product_code'] ?? '';
                $fProgramLabel = $fresh['program']       ?? '';
                $fDocChecklist = [];

                if ($fProductCode !== '') {
                    $fDocChecklist = getDocumentsForProduct($fProductCode);
                }
                if (empty($fDocChecklist)) {
                    foreach ([$fProgramLabel, $fProductCode] as $candidate) {
                        if ($candidate === '') continue;
                        $det = getProgramDetailsByLabel($candidate);
                        $rc  = $det['product_code'] ?? getProductCodeFromLabel($candidate) ?? '';
                        if ($rc !== '') {
                            $fProductCode  = $rc;
                            $fDocChecklist = getDocumentsForProduct($rc);
                            if (!empty($fDocChecklist)) break;
                        }
                    }
                }
                
                $docPrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($fresh['cf_number'] ?? 'StudentID'));
                $docPrefix = $docPrefix !== '' ? $docPrefix : 'StudentID';
                
                // Process dynamic document uploads
                $uploadDocs = $fDocChecklist;
                $docPaths = [];
                $docIndex = 1;
                foreach ($uploadDocs as $docName) {
                    $docId = 'doc' . $docIndex;
                    $safeDocName = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($docName, 0, 20));
                    $baseName = $docPrefix . '_' . ($safeDocName ?: 'Doc' . $docIndex);
                    $docPaths['doc' . $docIndex . '_path'] = storeUploadedFile($_FILES[$docId] ?? [], $token, $docId, $baseName);
                    $docIndex++;
                }
                
                // Process agreement upload
                $agreementPath = storeUploadedFile($_FILES['agreement'] ?? [], $token, 'agreement', $docPrefix . '_Agreement');

                $resolvedDetails = getProgramDetailsByLabel($fresh['program'] ?? '');
                $submissionData = [
                    'token'              => $token,
                    'cf_number'          => $fresh['cf_number']   ?? '',
                    'student_name'       => $fresh['name']        ?? '',
                    'student_email'      => $fresh['student_email'] ?? '',
                    'program_level'      => $resolvedDetails['level'] ?? ($fresh['program'] ?? ''),
                    'degree_description' => $resolvedDetails['description'] ?? '',
                    'product_code'       => $fProductCode,
                    'agreement_path'     => $agreementPath,
                ];
                // Add document paths in order
                foreach ($docPaths as $key => $path) {
                    $submissionData[$key] = $path;
                }
                appendSubmission($submissionData);
                markStudentTokenUsed($token);
                $phase = 'done';
                $success = 'Your registration has been submitted successfully.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $phase = 'form';
            }
        }
    }

    $studentToken = getStudentToken($token) ?: $studentToken;
    $programLabel = $studentToken['program'] ?? $programLabel;
    $programDetails = getProgramDetailsByLabel($programLabel);
    $programLevel = $programDetails['level'] ?? $programLabel;
    $degreeDescription = $programDetails['description'] ?? '';
}

$agreementTemplatePath = __DIR__ . '/Agreement_template.pdf';
$agreementTemplateUrl = file_exists($agreementTemplatePath) ? 'Agreement_template.pdf' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ANC — Student Registration</title>
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

        .wrap{max-width:680px;margin:48px auto;padding:0 20px 60px;width:100%;}

        .alert{display:flex;align-items:flex-start;gap:12px;padding:16px 20px;border-radius:var(--r);font-size:14px;margin-bottom:24px;animation:slideDown 0.3s ease;}
        .alert-success{background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;}
        .alert-error  {background:#FEF2F2;border:1px solid #FECACA;color:var(--red);}
        .alert svg{flex-shrink:0;margin-top:1px;}
        @keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

        .card{background:var(--white);border:1px solid var(--border);border-radius:20px;overflow:hidden;box-shadow:var(--shadow);}
        .card-head{padding:32px 40px;}
        .card-head.blue{background:linear-gradient(135deg,var(--navy),var(--blue));}
        .card-head.green{background:linear-gradient(135deg,#15803D,#16A34A);}
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

        /* OTP boxes — identical to counsellor portal */
        .otp-row{display:flex;gap:10px;justify-content:center;margin-bottom:24px;}
        .otp-box{width:54px;height:64px;background:#F8FAFC;border:2px solid var(--border);border-radius:12px;text-align:center;font-size:28px;font-weight:800;color:var(--navy);font-family:'DM Mono',monospace;outline:none;transition:all 0.2s;}
        .otp-box:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,0.10);}
        .otp-box.filled{border-color:var(--accent);background:#EFF6FF;}

        .field{margin-bottom:20px;}
        .label{display:block;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--navy);margin-bottom:7px;opacity:0.75;}
        input[type="email"],input[type="text"]{width:100%;padding:12px 14px;background:#F8FAFC;border:1.5px solid var(--border);border-radius:var(--r);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--navy);outline:none;transition:all 0.2s;}
        input[type="email"]:focus,input[type="text"]:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(37,99,235,0.10);}

        .readonly-val{padding:12px 14px;border:1.5px solid var(--border);border-radius:var(--r);background:#F8FAFC;font-size:14px;font-weight:600;color:var(--navy);}

        .upload-box{border:2px dashed var(--border);border-radius:var(--r);padding:16px;background:#F8FAFC;transition:border-color 0.2s;}
        .upload-box:focus-within{border-color:var(--accent);background:#EFF6FF;}
        .upload-box input[type="file"]{width:100%;font-size:13px;}
        .file-name{font-size:12px;color:var(--accent);margin-top:6px;word-break:break-all;font-weight:600;}

        .doc-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}

        .btn{width:100%;padding:15px;border:none;border-radius:var(--r);font-family:'DM Sans',sans-serif;font-size:15px;font-weight:700;color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:transform 0.15s,box-shadow 0.15s;}
        .btn-navy{background:linear-gradient(135deg,var(--navy),var(--accent));box-shadow:0 6px 24px rgba(20,71,184,0.28);}
        .btn-navy:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(10,36,99,0.30);}
        .btn-green{background:linear-gradient(135deg,#15803D,#16A34A);box-shadow:0 6px 24px rgba(22,163,74,0.25);}
        .btn-green:hover{transform:translateY(-2px);box-shadow:0 10px 28px rgba(22,163,74,0.32);}
        .btn-outline{background:transparent;border:1.5px solid var(--border);color:var(--text);box-shadow:none;}
        .btn-outline:hover{border-color:var(--accent);color:var(--blue);background:#EFF6FF;}
        .btn:active{transform:translateY(0);}
        .btn-row{display:flex;gap:12px;margin-top:8px;}
        .btn-link{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;border-radius:10px;background:var(--navy);color:#fff;font-size:13px;font-weight:700;text-decoration:none;}
        .btn-link:hover{background:var(--blue);}

        .success-icon{width:80px;height:80px;border-radius:50%;background:#F0FDF4;border:2px solid #BBF7D0;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;animation:popIn 0.5s 0.1s cubic-bezier(.22,.68,0,1.5) both;}
        @keyframes popIn{from{opacity:0;transform:scale(0.5)}to{opacity:1;transform:scale(1)}}

        @media(max-width:640px){
          .topbar{padding:0 20px;}
          .card-body{padding:24px 20px;}
          .card-head{padding:24px 20px;}
          .info-grid,.doc-grid{grid-template-columns:1fr;}
          .otp-box{width:42px;height:56px;font-size:22px;}
        }
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
            <?= htmlspecialchars($studentEmail) ?>
        </div>
    </div>
</div>

<div class="wrap">

  <?php if ($success !== ''): ?>
  <div class="alert alert-success">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span><?= $success ?></span>
  </div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
  <div class="alert alert-error">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($phase === 'done'): ?>
  <div class="card">
    <div class="card-head green">
      <div class="card-head-eyebrow">Complete</div>
      <div class="card-head-title">Registration Submitted!</div>
      <div class="card-head-sub">Your documents and agreement have been received.</div>
    </div>
    <div class="card-body" style="text-align:center;padding:48px 40px;">
      <div class="success-icon">
        <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="#16A34A" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h3 style="font-family:'Playfair Display',serif;font-size:22px;color:var(--navy);margin-bottom:10px;">Thank You, <?= htmlspecialchars($studentName) ?></h3>
      <p style="color:var(--muted);font-size:14px;line-height:1.7;max-width:380px;margin:0 auto;">
        Your submission is now complete. You will be contacted if any further action is required.
      </p>
    </div>
  </div>

  <?php elseif ($phase === 'otp_request'): ?>
  <div class="card">
    <div class="card-head blue">
      <div class="card-head-eyebrow">Step 3 · Identity Verification</div>
      <div class="card-head-title">Student Verification</div>
      <div class="card-head-sub">Enter your assigned student email to receive a one-time verification code.</div>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="request_otp">
        <div class="field">
          <label class="label">Student Email</label>
          <input type="email" name="student_email" placeholder="Enter your student email" required>
        </div>
        <button class="btn btn-navy" type="submit">
          <!-- <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> -->
          Send OTP to My Email
        </button>
      </form>
    </div>
  </div>

  <?php elseif ($phase === 'otp_verify'): ?>
  <div class="card">
    <div class="card-head blue">
      <div class="card-head-eyebrow">Step 3 · Identity Verification</div>
      <div class="card-head-title">Enter Your OTP</div>
      <div class="card-head-sub">We sent a 6-digit code to <strong style="color:#fff;"><?= htmlspecialchars($studentEmail) ?></strong></div>
    </div>
    <div class="card-body" style="text-align:center;">
      <form method="POST" id="otpForm">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="otp"    id="otpHidden">
        <div style="margin-bottom:20px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin-bottom:14px;">Enter 6-digit code</div>
          <div class="otp-row">
            <?php for ($i = 0; $i < 6; $i++): ?><input class="otp-box" type="text" maxlength="1" inputmode="numeric"><?php endfor; ?>
          </div>
        </div>
        <div class="btn-row">
          <button class="btn btn-navy" type="submit">
            Verify & Continue
            <!-- <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg> -->
          </button>
        </div>
      </form>
      <form method="POST" style="margin-top:16px;">
        <input type="hidden" name="token"         value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action"        value="request_otp">
        <input type="hidden" name="student_email" value="<?= htmlspecialchars($studentEmail) ?>">
        <button type="submit" class="btn btn-outline" style="max-width:220px;margin:0 auto;font-size:13px;padding:10px;">
          ↺ Resend OTP
        </button>
      </form>
    </div>
  </div>

  <?php else: ?>
  <div class="card">
    <div class="card-head blue">
      <div class="card-head-eyebrow">Step 3 · Document Upload</div>
      <div class="card-head-title">Student Registration</div>
      <div class="card-head-sub">Identity verified ✓ — Upload your documents and signed agreement below.</div>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="token"  value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="action" value="submit_registration">

        <div class="sec">Student Information</div>
        <div class="info-grid">
          <div class="info-item"><div class="info-label">CF Number</div><div class="info-val"><?= htmlspecialchars($cfNumber) ?></div></div>
          <div class="info-item"><div class="info-label">Student Name</div><div class="info-val"><?= htmlspecialchars($studentName) ?></div></div>
          <div class="info-item" style="grid-column:1/-1;"><div class="info-label">Student Email</div><div class="info-val"><?= htmlspecialchars($studentEmail) ?></div></div>
          <div class="info-item" style="grid-column:1/-1;"><div class="info-label">Programme</div><div class="info-val"><?= htmlspecialchars($programLabel) ?></div></div>
          <?php if (!empty($degreeDescription)): ?>
            <div class="info-item" style="grid-column:1/-1;"><div class="info-label">Degree Description</div><div class="info-val"><?= htmlspecialchars($degreeDescription) ?></div></div>
          <?php endif; ?>
        </div>

        <?php // Degree description field removed as requested - not displayed ?>

        <hr class="divider">
        <div class="sec">Required Document Checklist</div>
        <?php 
        // Debug: Log product code and checklist info
        error_log("Registration form - Product Code: '$productCode', Programme: '$programLabel', Checklist count: " . count($documentChecklist));
        
        if (!empty($documentChecklist)): 
          // Get the degree description for the heading
          //$displayHeading = !empty($degreeDescription) ? $degreeDescription : $programLabel;

          $displayHeading = $degreeDescription;
        ?>
        <div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:16px 20px;margin-bottom:22px;">
            <div style="font-size:13px;color:#15803D;line-height:1.8;">
                <strong style="display:block;margin-bottom:8px;font-size:14px;">Documents Required for <?= htmlspecialchars($degreeDescription) ?>:</strong>
                <ul style="margin:0;padding-left:20px;list-style-type:disc;">
                    <?php foreach ($documentChecklist as $doc): ?>
                    <li><?= htmlspecialchars($doc) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php else: ?>
        <div style="background:#FEF3C7;border:1px solid #FCD34D;border-radius:10px;padding:16px 20px;margin-bottom:22px;font-size:13.5px;color:#92400E;line-height:1.5;">
            <strong>⚠️ Note:</strong> Document checklist not available for this programme. Please upload the common required documents.
        </div>
        <?php endif; ?>

        <div class="sec">Upload Documents</div>
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 18px;margin-bottom:22px;font-size:13.5px;color:#1E40AF;line-height:1.5;">
            Max file size: <strong>10MB per file</strong><br>
            Allowed formats: <strong>PDF</strong>
        </div>
        <div class="doc-grid">
          <?php 
          // Generate upload boxes dynamically based on document checklist
          // If checklist is empty, use default document names
          $uploadDocs = !empty($documentChecklist) ? $documentChecklist : ['Academic Transcript', 'Identity Document', 'Proof of Address'];
          $docIndex = 1;
          foreach ($uploadDocs as $docName): 
            $docId = 'doc' . $docIndex;
            $fileId = 'f' . $docIndex;
          ?>
          <div class="upload-box">
            <label class="label"><?= htmlspecialchars($docName) ?></label>
            <input type="file" name="<?= $docId ?>" id="<?= $docId ?>" accept=".pdf" required>
            <div class="file-name" id="<?= $fileId ?>"></div>
          </div>
          <?php 
            $docIndex++;
          endforeach; 
          ?>
        </div>

        <hr class="divider">
        <div class="sec">Agreement</div>
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:14px 18px;margin-bottom:22px;font-size:13.5px;color:#1E40AF;line-height:1.5;">
            Download Agreement: <br><strong>Please review the agreement carefully before signing.</strong><br>
            Upload Completed Agreement: <br><strong>After signing, please scan the document and upload it in PDF format.</strong>
        </div>
        <div class="doc-grid">
          <div class="upload-box" style="display:flex;flex-direction:column;justify-content:center;">
            <?php if ($agreementTemplateUrl !== ''): ?>
              <a class="btn-link" href="<?= htmlspecialchars($agreementTemplateUrl) ?>" target="_blank" rel="noopener">
                <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
                Download Agreement Template
              </a>
            <?php else: ?>
              <div class="readonly-val" style="font-size:12px;color:var(--red);">Agreement_template.pdf not found.</div>
            <?php endif; ?>
          </div>
          <div class="upload-box">
            <label class="label">Upload Completed Agreement</label>
            <input type="file" name="agreement" id="agreement" accept=".pdf" required>
            <div class="file-name" id="f4"></div>
          </div>
        </div>

        <div class="btn-row" style="margin-top:24px;">
          <button class="btn btn-green" type="submit">
            Submit Registration
            <!-- <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg> -->
          </button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
// OTP boxes (identical logic to counsellor portal)
const otpBoxes  = document.querySelectorAll('.otp-box');
const otpHidden = document.getElementById('otpHidden');
if (otpBoxes.length) {
    otpBoxes.forEach((b, i) => {
        b.addEventListener('input', () => {
            b.value = b.value.replace(/\D/g, '');
            b.classList.toggle('filled', !!b.value);
            if (b.value && i < otpBoxes.length - 1) otpBoxes[i + 1].focus();
            if (otpHidden) otpHidden.value = [...otpBoxes].map(x => x.value).join('');
        });
        b.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !b.value && i > 0) {
                otpBoxes[i - 1].classList.remove('filled');
                otpBoxes[i - 1].focus();
            }
        });
        b.addEventListener('paste', e => {
            e.preventDefault();
            const p = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
            p.split('').forEach((c, j) => {
                if (otpBoxes[j]) { otpBoxes[j].value = c; otpBoxes[j].classList.add('filled'); }
            });
            if (otpHidden) otpHidden.value = p;
        });
    });
    otpBoxes[0]?.focus();
}

// File name display
// Handle all document uploads dynamically
const docInputs = document.querySelectorAll('.upload-box input[type="file"]');
docInputs.forEach(input => {
    const uploadBox = input.closest('.upload-box');
    const fileNameLabel = uploadBox.querySelector('.file-name');
    if (!fileNameLabel) return;
    input.addEventListener('change', () => {
        fileNameLabel.textContent = input.files && input.files[0] ? input.files[0].name : '';
    });
});
</script>
</body>
</html>