---
name: External webhost verification
description: Distinguish Replit checks from the independently hosted salesman.no installation.
---

Do not describe a Replit connection or login-page check as verification of the salesman.no installation.

**Why:** The local MySQL connection succeeded while salesman.no authentication and the hyphenated portal entrypoint returned generic HTTP 500 errors. The deployment service reported no Replit deployment for this project; workspace changes alone cannot establish what files or settings the external host runs.

**How to apply:** Verify the exact reported URL and request stage. Obtain the external server's relevant error log or authorized deployment access before attributing its HTTP 500 to database passwords. Replit Secrets do not configure a separate webhost automatically.

Use environment variables for the project's database credentials, not hardcoded values or a newly required private configuration file.

**Why:** The user explicitly chose environment variables after rejecting the proposed private PHP configuration file.

**How to apply:** Preserve the existing MYSQL_* interface and verify that the PHP process in the actual hosting environment receives it. Do not assume an up-to-date Git checkout establishes those settings.

Verify Apache rewrite and access rules with Apache, not with the PHP development server.

**Why:** The webhost's uploaded asset rule rewrote missing assets to themselves and reproduced HTTP 500 in an Apache fixture. The PHP built-in server ignores `.htaccess`, so an otherwise successful preview cannot validate those rules.

**How to apply:** Check missing-resource responses, directory-listing protection and portal entrypoints in an isolated Apache fixture. Distinguish the webroot configuration from the configuration inside `public`; do not transplant a fixed `/public/` rewrite base into the webroot.
