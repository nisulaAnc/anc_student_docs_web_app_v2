# ANC Student Docs Portal - Version 2.0

A PHP web application for ANC student onboarding and document collection. The flow is designed for a CF team to register a student, send a counsellor verification link, let the counsellor assign a programme, and then send a student registration link for OTP verification and document upload.

This project uses:

- PHP for the portal logic
- Google Sheets for master data and workflow tracking
- PHPMailer for email delivery
- local file uploads for student supporting documents
- optional MySQL connectivity for additional token storage

## Overview

The app supports this workflow:

1. CF staff submits a student registration request from the main form.
2. The app looks up the assigned counsellor from the Google Sheet and sends them a secure link.
3. The counsellor verifies with OTP, selects the relevant programme, and triggers the student registration link.
4. The student receives an email with a secure link, verifies identity with OTP, uploads required documents, and submits the registration.
5. Submission data and uploaded files are stored for review.

## Features

- CF department student registration page
- Counsellor portal with OTP verification
- Programme selection and token-based workflow validation
- Student portal with OTP validation and document upload
- Google Sheets integration for counsellor and product records
- Dynamic document checklist based on selected product/programme
- Email notifications for counsellor and student workflow steps
- Local upload handling for agreement and supporting documents
- Configuration through environment variables

## Project Structure

- `index.php` — CF registration form and initial counsellor email step
- `counsellor_portal.php` — counsellor OTP verification and programme assignment
- `registration_form.php` — student email verification, OTP validation, and document submission form
- `config.php` — environment loader, session setup, SMTP settings, and core constants
- `includes/functions.php` — main Google Sheets and workflow logic
- `includes/product_documents.php` — document checklist mapping for products/programmes
- `uploads/` — uploaded files and generated submission assets
- `credentials.json` — Google service account credentials (do not commit if sensitive)
- `vendor/` — Composer dependencies
- `.env` — local secrets and configuration values

## Requirements

- PHP 8.0 or later recommended
- Composer
- A local web server such as XAMPP or Apache
- `openssl`, `json`, `curl`, and standard PHP extensions
- A Google service account with access to the target spreadsheet
- SMTP access for outgoing email delivery

## Local Setup

1. Clone or copy the project into your local web root, for example:

```powershell
C:\xampp\htdocs\anc_student_docs_web_app_v2
```

2. Install PHP dependencies:

```powershell
cd C:\xampp\htdocs\anc_student_docs_web_app_v2
composer install
```

3. Create a `.env` file in the project root with the required keys.

4. Place your Google service account JSON file in the project or point the app to an existing file path.

5. Ensure the `uploads/` directory exists and is writable by the web server.

6. Start your local server and open the app in a browser.

## Environment Variables

Example `.env` file:

```env
SPREADSHEET_ID=your_google_sheet_id
BASE_URL=http://localhost/anc_student_docs_web_app_v2/

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your_email@example.com
SMTP_PASSWORD=your_app_password
FROM_EMAIL=your_email@example.com
FROM_NAME=ANC Student Docs

DB_HOST=localhost
DB_NAME=anc_student_docs
DB_USER=root
DB_PASS=

GOOGLE_APPLICATION_CREDENTIALS=C:/xampp/htdocs/anc_student_docs_web_app_v2/credentials.json
# Optional alternative to credentials.json
# GOOGLE_SERVICE_ACCOUNT_JSON={"type":"service_account", ...}
```

Notes:

- `SPREADSHEET_ID` is the Google Sheet ID used by the app.
- `BASE_URL` should match the browser-accessible URL for the project.
- `GOOGLE_APPLICATION_CREDENTIALS` should point to the service account JSON file.
- `GOOGLE_SERVICE_ACCOUNT_JSON` can be used instead of a file path when you want to store the raw JSON in the environment.

## Google Sheets Setup

The application expects a Google spreadsheet with these tabs:

- `Counsellor List`
- `Product List`
- `CF_Tokens`
- `Student_Tokens`
- `Submissions`
- `Check List`

The Google service account must have editor or appropriate access to the sheet.

The project reads counsellor and product information from the spreadsheet and appends workflow tokens and submissions to the relevant tabs.

## Running the App

Open the application in your browser:

```text
http://localhost/anc_student_docs_web_app_v2/index.php
```

The main registration flow starts at that page.

## Email and Upload Notes

- Email notifications are sent using SMTP credentials configured in `.env`.
- Uploaded files are saved inside the `uploads/` folder.
- The document checklist is generated using the selected product/programme.
- Agreements and supporting documents are included in the final submission payload.

## Troubleshooting

### Email delivery fails

- Verify SMTP host, port, username, and password.
- Test whether your email provider allows SMTP app passwords.
- Ensure `FROM_EMAIL` and `SMTP_USERNAME` are valid for your account.

### Google Sheets access fails

- Confirm the service account JSON is valid.
- Check that the service account email has access to the spreadsheet.
- Verify `SPREADSHEET_ID` is correct.

### Uploads do not work

- Make sure the `uploads/` folder exists and is writable.
- Check PHP file upload settings and max file size limits.
- Confirm the correct document field names are present in the form.

### Token links fail or are invalid

- Make sure `BASE_URL` matches the actual project URL.
- Confirm the app has permission to write to the Google Sheets tabs used for tokens.
- Re-send the counsellor or student link from the entry form if the token is expired or invalid.

## Security and Deployment Notes

- Keep `.env`, `credentials.json`, and any secrets out of version control.
- Do not expose the service account JSON publicly.
- Use a production-safe URL and secure SMTP configuration when deploying outside local development.
- Restrict access to the admin and portal pages to authorised users only if this project is deployed in a live environment.

## License

This project is intended for internal ANC operational use. Please check your institutional policy before public or external deployment.
