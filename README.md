# ANC Student Docs - Version 2.0

A PHP-based student registration and document submission portal for ANC. The app uses Google Sheets as the backend database, sends email notifications via SMTP, and routes student registration through counsellor approval and OTP verification.

## Features

- CF department registration form (`index.php`)
- Counsellor portal with OTP verification (`counsellor_portal.php`)
- Student registration form with email verification and file uploads (`registration_form.php`)
- Google Sheets integration for storing counsellor, product, token and submission data
- Email notifications using PHPMailer and SMTP
- Environment-based configuration with `.env`

## Requirements

- PHP 7.4+ (recommended PHP 8)
- `openssl`, `json`, `curl`, and other standard PHP extensions
- Composer
- A local web server (XAMPP)
- Google service account credentials with access to the target Google Sheet

## Setup

1. Place the project in your web root, e.g. `C:\xampp\htdocs\anc_student_docs_web_app_v2`.
2. Run Composer install:

```powershell
cd c:\xampp\htdocs\anc_student_docs_web_app_v2
composer install
```

3. Create a `.env` file in the project root.

4. Configure SMTP and Google credentials in `.env`.

5. Ensure `uploads/` is writable by the web server.

### Notes

- `SPREADSHEET_ID` is the Google Sheet ID used by the app.
- `BASE_URL` is the public URL path to the project.
- `GOOGLE_APPLICATION_CREDENTIALS` should point to the local `credentials.json` file.
- Alternatively, `GOOGLE_SERVICE_ACCOUNT_JSON` can store the raw JSON service account payload directly.

## Google Sheets

The app expects several tabs in the spreadsheet:

- `Counsellor List`
- `Product List`
- `CF_Tokens`
- `Student_Tokens`
- `Submissions`
- `Check List`

The service account must have access to the spreadsheet.

## Files and Structure

- `index.php` — CF registration form and counsellor notification
- `counsellor_portal.php` — counsellor OTP verification and programme selection
- `registration_form.php` — student OTP verification and document upload
- `config.php` — environment loader and core constants
- `includes/functions.php` — Google Sheets integration and helper functions
- `includes/product_documents.php` — product document checklist mapping
- `uploads/` — saved uploaded files
- `credentials.json` — Google service account credentials (ignored by git)
- `.env` — local secret configuration (ignored by git)

## Running Locally

Open the project in your browser at:

```text
https://localhost/anc_student_docs_web_app_v2/index.php
```

Use the form to register a student, send a counsellor link, and continue through the workflow.

## Troubleshooting

- If email fails, verify SMTP credentials and port.
- If sheet access fails, verify the service account and `SPREADSHEET_ID`.
- If uploads fail, verify directory permissions for `uploads/`.
