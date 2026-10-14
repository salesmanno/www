---
name: External webhost verification
description: Distinguish Replit checks from the independently hosted salesman.no installation.
---

Do not describe a Replit connection or login-page check as verification of the salesman.no installation.

**Why:** The local MySQL connection succeeded while salesman.no authentication and the hyphenated portal entrypoint returned generic HTTP 500 errors. The deployment service reported no Replit deployment for this project; workspace changes alone cannot establish what files or settings the external host runs.

**How to apply:** Verify the exact reported URL and request stage. Obtain the external server's relevant error log or authorized deployment access before attributing its HTTP 500 to database passwords. Replit Secrets do not configure a separate webhost automatically.

Support database constants directly in config/database.php as well as the existing environment variables. Do not introduce a required private configuration file.

**Why:** After initially choosing environment variables, the user explicitly requested the same file-based configuration as another exported project. Environment-only configuration must no longer be treated as a standing requirement.

**How to apply:** Preserve the MYSQL_* interface for existing installations, but respect explicitly configured DB_* constants. Never extract secret values into source or recover the former password from Git. File-configured credentials must be entered by the owner and treated as sensitive export contents.

Verify Apache rewrite and access rules with Apache, not with the PHP development server.

**Why:** The webhost's uploaded asset rule rewrote missing assets to themselves and reproduced HTTP 500 in an Apache fixture. The PHP built-in server ignores `.htaccess`, so an otherwise successful preview cannot validate those rules.

**How to apply:** Check missing-resource responses, directory-listing protection and portal entrypoints in an isolated Apache fixture. Distinguish the webroot configuration from the configuration inside `public`; do not transplant a fixed `/public/` rewrite base into the webroot.

For GitHub Actions deployments, the workflow directory must be exactly `.github/workflows` (lowercase). Verify that a successful run's `head_sha` matches the commit being evaluated; an older green run does not verify a newer push.

**Why:** A case-variant directory was ignored by GitHub Actions, and an older successful FTP run was initially mistaken for verification of a later commit.

**How to apply:** Check registered workflows and the latest run metadata before attributing a deployment to a push. Compare the run's commit SHA and retrieve the failing step log before diagnosing an FTP transfer failure.
