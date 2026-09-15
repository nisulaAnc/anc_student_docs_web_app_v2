<?php
require 'config.php';

$error         = '';
$success       = '';
$student_email = ''; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cf_number       = trim($_POST['cf_number']       ?? '');
    $name            = trim($_POST['name']            ?? '');
    $student_email   = trim($_POST['student_email']   ?? '');
    $counsellor_name = trim($_POST['counsellor_name'] ?? '');

    if (!$cf_number || !$name || !filter_var($student_email, FILTER_VALIDATE_EMAIL) || !$counsellor_name) {
        $error = 'All fields are required. Please check the form.';
    } else {
        $counsellor_email = getCounsellorEmail($counsellor_name);
        if (!$counsellor_email) {
            $error = 'Counsellor <strong>' . htmlspecialchars($counsellor_name) . '</strong> was not found in the master list.';
        } else {
            $token = generateToken();
            saveCounsellorToken($token, [
                'cf_number'       => $cf_number,
                'name'            => $name,
                'student_email'   => $student_email,
                'counsellor_name' => $counsellor_name,
                'counsellor_email'=> $counsellor_email,
            ]);

            $link = BASE_URL . 'counsellor_portal.php?token=' . $token;
            $html = emailHtml(
                "Student Registration — Action Required",
                "<p style='font-size:15px;color:#334155;line-height:1.7;margin-bottom:20px;'>
                    Dear <strong>{$counsellor_name}</strong>,<br><br>
                    A new student has been registered by the CF Department and assigned to you.
                    Please click the button below to access the Counsellor Portal, verify your identity,
                    and select the appropriate programme for this student.
                </p>
                <table style='width:100%;border-collapse:collapse;margin-bottom:8px;border-radius:10px;overflow:hidden;border:1px solid #E2E8F0;'>
                  <tr style='background:#F8FAFC;'>
                    <td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:1px;width:40%;border-bottom:1px solid #E2E8F0;'>CF Number</td>
                    <td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0A2463;border-bottom:1px solid #E2E8F0;'>{$cf_number}</td>
                  </tr>
                  <tr>
                    <td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:1px;'>Student Name</td>
                    <td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0A2463;'>{$name}</td>
                  </tr>
                </table>",
                "Open Counsellor Portal →",
                $link
            );

            if (sendEmail($counsellor_email, "ANC - Action Required: Student Registration [{$cf_number}]", $html)) {
                $success = "Link sent successfully to <strong>{$counsellor_name}</strong> ({$counsellor_email}).";
            } else {
                $error = "Form saved but email failed. Please contact IT to resend.";
            }
        }
    }
}

// Load counsellors for the dropdown
try {
    $counsellors = getCounsellors();
} catch (Exception $e) {
    $counsellors = [];
    $error = 'Could not load counsellors from Google Sheets: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ANC — CF Department Registration</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<style>
/* ── Reset & Root ─────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#0A2463; --blue:#1447B8; --accent:#2563EB; --sky:#38BDF8;
  --white:#fff; --bg:#F1F5F9; --card:#fff;
  --border:#E2E8F0; --muted:#94A3B8; --text:#334155;
  --green:#16A34A; --red:#DC2626;
  --r:14px; --shadow:0 8px 40px rgba(10,36,99,0.10);
}
body{min-height:100vh;background:var(--bg);font-family:'DM Sans',sans-serif;color:var(--text);display:flex;flex-direction:column;}

/* Top Bar */
.topbar{
  position:sticky;top:0;z-index:100;
  height:64px;background:var(--white);border-bottom:1px solid var(--border);
  box-shadow:0 2px 10px rgba(10,36,99,0.06);
  display:flex;align-items:center;justify-content:space-between;
  padding:0 40px;
}
.brand-logo{display:flex;align-items:center;gap:12px;}
.logo-mark{
  width:38px;height:38px;background:linear-gradient(135deg,var(--blue),var(--accent));
  border-radius:9px;display:flex;align-items:center;justify-content:center;
  box-shadow:0 4px 12px rgba(37,99,235,0.30);
}
.logo-mark svg{width:22px;height:22px;}
.logo-name{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--navy);letter-spacing:-0.2px;}
.logo-tag{font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--accent);display:block;}
.topbar-right{display:flex;align-items:center;gap:14px;}
.email-chip{
  display:flex;align-items:center;gap:8px;
  background:#EFF6FF;border:1.5px solid #DBEAFE;
  color:var(--blue);font-size:13px;font-weight:600;
  padding:6px 14px;border-radius:40px;
}
.email-chip svg{opacity:0.6;}

/* Progress Strip */
.progress{
  background:var(--white);border-bottom:1px solid var(--border);
  padding:0 40px;display:flex;align-items:center;height:52px;gap:0;
}
.prog-step{
  display:flex;align-items:center;gap:10px;
  font-size:13px;font-weight:600;color:var(--muted);
  padding:0 20px;height:100%;border-bottom:3px solid transparent;
  transition:all 0.2s;
}
.prog-step.active{color:var(--blue);border-bottom-color:var(--blue);}
.prog-step.done{color:var(--green);}
.prog-num{
  width:24px;height:24px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;background:var(--bg);
  border:1.5px solid var(--border);
}
.prog-step.active .prog-num{background:var(--blue);border-color:var(--blue);color:#fff;}
.prog-step.done .prog-num{background:var(--green);border-color:var(--green);color:#fff;}
.prog-sep{width:28px;height:1.5px;background:var(--border);flex-shrink:0;}

/* Main Content */
.wrap{max-width:660px;margin:48px auto;padding:0 20px 60px;width:100%;}

/* Alerts */
.alert{
  display:flex;align-items:flex-start;gap:12px;
  padding:16px 20px;border-radius:var(--r);font-size:14px;margin-bottom:24px;
  animation:slideDown 0.3s ease;
}
.alert-success{background:#F0FDF4;border:1px solid #BBF7D0;color:#15803D;}
.alert-error  {background:#FEF2F2;border:1px solid #FECACA;color:var(--red);}
.alert svg{flex-shrink:0;margin-top:1px;}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}

/* Card */
.card{
  background:var(--card);border:1px solid var(--border);
  border-radius:20px;overflow:hidden;box-shadow:var(--shadow);
}
.card-head{
  background:linear-gradient(135deg,var(--navy) 0%,var(--blue) 100%);
  padding:32px 40px;
}
.card-head-eyebrow{
  font-size:10px;font-weight:700;letter-spacing:2.5px;
  text-transform:uppercase;color:rgba(255,255,255,0.45);margin-bottom:8px;
}
.card-head-title{
  font-family:'Playfair Display',serif;
  font-size:24px;font-weight:700;color:#fff;margin-bottom:6px;
}
.card-head-sub{font-size:13px;color:rgba(255,255,255,0.60);line-height:1.6;}
.card-body{padding:36px 40px;}

/* Section heading */
.sec{
  font-size:10px;font-weight:700;letter-spacing:2px;
  text-transform:uppercase;color:var(--muted);
  display:flex;align-items:center;gap:10px;margin-bottom:18px;
}
.sec::after{content:'';flex:1;height:1px;background:var(--border);}
.divider{border:none;border-top:1px solid var(--border);margin:8px 0 24px;}

/* Field Grid */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.field{margin-bottom:20px;}
.label{
  display:block;font-size:11px;font-weight:700;
  letter-spacing:1.2px;text-transform:uppercase;
  color:var(--navy);margin-bottom:7px;opacity:0.75;
}
.input-wrap{position:relative;}
.input-wrap .ico{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--accent);width:16px;height:16px;pointer-events:none;
}
input[type=text],input[type=email]{
  width:100%;padding:12px 14px 12px 42px;
  background:#F8FAFC;border:1.5px solid var(--border);
  border-radius:var(--r);font-family:'DM Sans',sans-serif;
  font-size:14px;color:var(--navy);outline:none;
  transition:border-color 0.2s,box-shadow 0.2s,background 0.2s;
}
input[type=text]:focus,input[type=email]:focus{
  border-color:var(--accent);background:#fff;
  box-shadow:0 0 0 3px rgba(37,99,235,0.10);
}
input::placeholder{color:var(--muted);font-weight:400;}

/* Searchable Select */
.search-select-wrap{position:relative;}
.search-input{
  width:100%;padding:12px 42px 12px 42px;
  background:#F8FAFC;border:1.5px solid var(--border);
  border-radius:var(--r);font-family:'DM Sans',sans-serif;
  font-size:14px;color:var(--navy);outline:none;
  transition:border-color 0.2s,box-shadow 0.2s,background 0.2s;
  cursor:pointer;
}
.search-input:focus{
  border-color:var(--accent);background:#fff;
  box-shadow:0 0 0 3px rgba(37,99,235,0.10);
  border-radius:var(--r) var(--r) 0 0;
}
.search-input.open{
  border-color:var(--accent);background:#fff;
  border-radius:var(--r) var(--r) 0 0;
  box-shadow:0 0 0 3px rgba(37,99,235,0.10);
}
.ss-chevron{
  position:absolute;right:14px;top:50%;transform:translateY(-50%);
  color:var(--muted);pointer-events:none;transition:transform 0.2s;
}
.search-input.open ~ .ss-chevron{transform:translateY(-50%) rotate(180deg);}
.ss-dropdown{
  position:relative;top:100%;left:0;right:0;z-index:50;
  background:#fff;border:1.5px solid var(--accent);
  border-top:none;border-radius:0 0 var(--r) var(--r);
  box-shadow:0 12px 32px rgba(10,36,99,0.12);
  max-height:220px;overflow-y:auto;display:none;
}
.ss-dropdown.open{display:block;}
.ss-option{
  padding:11px 16px;font-size:14px;color:var(--navy);
  cursor:pointer;display:flex;align-items:center;gap:10px;
  transition:background 0.1s;border-bottom:1px solid var(--border);
}
.ss-option:last-child{border-bottom:none;}
.ss-option:hover,.ss-option.highlighted{background:#EFF6FF;color:var(--blue);}
.ss-option .ss-avatar{
  width:28px;height:28px;border-radius:50%;background:var(--blue);
  color:#fff;font-size:11px;font-weight:800;
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.ss-option .ss-email{font-size:11px;color:var(--muted);margin-top:1px;}
.ss-empty{padding:20px;text-align:center;color:var(--muted);font-size:13px;}

/* Counsellor preview badge */
.counsellor-preview{
  display:none;align-items:center;gap:12px;
  background:#EFF6FF;border:1.5px solid #BFDBFE;
  border-radius:var(--r);padding:12px 16px;margin-top:10px;
  animation:fadeIn 0.2s ease;
}
.counsellor-preview.show{display:flex;}
.c-av{
  width:38px;height:38px;border-radius:50%;background:var(--blue);
  color:#fff;font-family:'Playfair Display',serif;font-weight:700;
  font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.c-name{font-weight:700;color:var(--navy);font-size:14px;}
.c-mail{font-size:12px;color:var(--muted);font-family:'DM Mono',monospace;}

/* Hidden real input */
#counsellor_name_hidden{display:none;}

/* Submit Button */
.btn{
  width:100%;padding:15px;border:none;border-radius:var(--r);
  font-family:'DM Sans',sans-serif;font-size:15px;font-weight:700;
  color:#fff;cursor:pointer;
  background:linear-gradient(135deg,var(--navy),var(--accent));
  box-shadow:0 6px 24px rgba(20,71,184,0.28);
  transition:transform 0.15s,box-shadow 0.15s;
  display:flex;align-items:center;justify-content:center;gap:8px;
}
.btn:hover{transform:translateY(-2px);box-shadow:0 10px 30px rgba(10,36,99,0.30);}
.btn:active{transform:translateY(0);}

@keyframes fadeIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:640px){
  .topbar{padding:0 20px;}.progress{padding:0 16px;}
  .card-body{padding:24px 20px;}.card-head{padding:24px 20px;}
  .grid-2{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- Topbar -->
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
            <?= htmlspecialchars($student_email ?: 'cf.department@ancedu.com') ?>
        </div>
    </div>
</div>

<!-- Progress -->
<div class="progress">
  <div class="prog-step active"><div class="prog-num">1</div>CF Department</div>
  <div class="prog-sep"></div>
  <div class="prog-step"><div class="prog-num">2</div>Counsellor</div>
  <div class="prog-sep"></div>
  <div class="prog-step"><div class="prog-num">3</div>Student</div>
</div>

<!-- Content -->
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

  <div class="card">
    <div class="card-head">
      <div class="card-head-eyebrow">Step 1 of 3 · Center Function</div>
      <div class="card-head-title">Center Function Registration</div>
      <div class="card-head-sub">Fill in student details and assign a counsellor. The counsellor will receive a secure email with a verification link.</div>
    </div>
    <div class="card-body">
      <form method="POST" id="cfForm" onsubmit="return validateForm()">

        <!-- Student Info -->
        <div class="sec">Student Information</div>
        <div class="grid-2">
          <div class="field">
            <label class="label" for="cf_number">CF Number</label>
            <div class="input-wrap">
              <svg class="ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
              <input type="text" id="cf_number" name="cf_number" placeholder="e.g. CFN-000001" required
                value="<?= htmlspecialchars($_POST['cf_number'] ?? '') ?>">
            </div>
          </div>
          <div class="field">
            <label class="label" for="name">Full Name</label>
            <div class="input-wrap">
              <svg class="ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
              <input type="text" id="name" name="name" placeholder="As per your NIC or Passport" required
                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
            </div>
          </div>
        </div>
        <div class="field">
          <label class="label" for="student_email">Student Email Address</label>
          <div class="input-wrap">
            <svg class="ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input type="email" id="student_email" name="student_email" placeholder="student@email.com" required
              value="<?= htmlspecialchars($_POST['student_email'] ?? '') ?>">
          </div>
        </div>

        <hr class="divider">

        <!-- Counsellor -->
        <div class="sec">Assign Counsellor</div>
        <div class="field">
          <label class="label">Counsellor Name</label>
          <!-- Hidden real input submitted with form -->
          <input type="hidden" name="counsellor_name" id="counsellor_name_hidden">

          <div class="input-wrap search-select-wrap" id="counsellorWrap">
            <svg class="ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/>
              <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
            </svg>
            <input type="text" class="search-input" id="counsellorSearch"
              placeholder="Type to search counsellor…" autocomplete="off"
              value="<?= htmlspecialchars($_POST['counsellor_name'] ?? '') ?>">
            <svg class="ss-chevron" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>
            <div class="ss-dropdown" id="counsellorDropdown">
              <!-- filled by JS -->
            </div>
          </div>

          <!-- Preview of selected counsellor -->
          <div class="counsellor-preview" id="counsellorPreview">
            <div class="c-av" id="cAv">?</div>
            <div>
              <div class="c-name" id="cName">—</div>
              <div class="c-mail" id="cMail">—</div>
            </div>
          </div>
        </div>

        <div style="margin-top:8px;">
          <button class="btn" type="submit">
            Send Link to Counsellor
            <!-- <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg> -->
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Counsellor data from PHP
const COUNSELLORS = <?= json_encode($counsellors, JSON_UNESCAPED_UNICODE) ?>;

const searchInput  = document.getElementById('counsellorSearch');
const hiddenInput  = document.getElementById('counsellor_name_hidden');
const dropdown     = document.getElementById('counsellorDropdown');
const preview      = document.getElementById('counsellorPreview');
let selectedCounsellor = null;
let highlightIndex = -1;

// Pre-select if form was re-submitted
<?php if (!empty($_POST['counsellor_name'])): ?>
const preselect = <?= json_encode($_POST['counsellor_name']) ?>;
const found = COUNSELLORS.find(c => c.name === preselect);
if (found) selectCounsellor(found);
<?php endif; ?>

function renderDropdown(filter) {
  const q = filter.trim().toLowerCase();
  const list = q
    ? COUNSELLORS.filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q))
    : COUNSELLORS;

  if (list.length === 0) {
    dropdown.innerHTML = '<div class="ss-empty">No counsellors found</div>';
  } else {
    dropdown.innerHTML = list.map((c, i) => `
      <div class="ss-option" data-name="${c.name}" data-email="${c.email}" data-idx="${i}">
        <div class="ss-avatar">${c.name.charAt(0).toUpperCase()}</div>
        <div>
          <div>${escHtml(c.name)}</div>
          <div class="ss-email">${escHtml(c.email)}</div>
        </div>
      </div>`).join('');
    dropdown.querySelectorAll('.ss-option').forEach(opt => {
      opt.addEventListener('mousedown', e => {
        e.preventDefault();
        selectCounsellor({name: opt.dataset.name, email: opt.dataset.email});
      });
    });
  }
  highlightIndex = -1;
}

function openDropdown() {
  renderDropdown(searchInput.value);
  dropdown.classList.add('open');
  searchInput.classList.add('open');
}

function closeDropdown() {
  dropdown.classList.remove('open');
  searchInput.classList.remove('open');
  // If nothing was selected, restore previous value or clear
  if (!selectedCounsellor) searchInput.value = '';
  else searchInput.value = selectedCounsellor.name;
}

function selectCounsellor(c) {
  selectedCounsellor = c;
  hiddenInput.value  = c.name;
  searchInput.value  = c.name;
  closeDropdown();
  // Show preview
  document.getElementById('cAv').textContent   = c.name.charAt(0).toUpperCase();
  document.getElementById('cName').textContent = c.name;
  document.getElementById('cMail').textContent = c.email;
  preview.classList.add('show');
}

searchInput.addEventListener('focus', openDropdown);
searchInput.addEventListener('blur',  closeDropdown);
searchInput.addEventListener('input', () => {
  selectedCounsellor = null;
  hiddenInput.value  = '';
  preview.classList.remove('show');
  renderDropdown(searchInput.value);
  if (!dropdown.classList.contains('open')) openDropdown();
});

// Keyboard nav
searchInput.addEventListener('keydown', e => {
  const opts = dropdown.querySelectorAll('.ss-option');
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    highlightIndex = Math.min(highlightIndex + 1, opts.length - 1);
    opts.forEach((o,i) => o.classList.toggle('highlighted', i === highlightIndex));
    if (opts[highlightIndex]) opts[highlightIndex].scrollIntoView({block:'nearest'});
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    highlightIndex = Math.max(highlightIndex - 1, 0);
    opts.forEach((o,i) => o.classList.toggle('highlighted', i === highlightIndex));
  } else if (e.key === 'Enter' && highlightIndex >= 0 && opts[highlightIndex]) {
    e.preventDefault();
    const o = opts[highlightIndex];
    selectCounsellor({name: o.dataset.name, email: o.dataset.email});
  } else if (e.key === 'Escape') {
    closeDropdown();
  }
});

function validateForm() {
  if (!hiddenInput.value) {
    searchInput.style.borderColor = '#DC2626';
    searchInput.focus();
    return false;
  }
  return true;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>