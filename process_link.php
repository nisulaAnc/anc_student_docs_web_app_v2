<?php
// Token routing is now handled directly in counsellor_portal.php
// and registration_form.php using GET ?token=XXX
require 'config.php';
$type  = $_GET['type']  ?? '';
$token = $_GET['token'] ?? '';
if ($type === 'counsellor' && $token) { header("Location: counsellor_portal.php?token=$token"); exit; }
if ($type === 'student'    && $token) { header("Location: registration_form.php?token=$token");  exit; }
die("Invalid link.");
