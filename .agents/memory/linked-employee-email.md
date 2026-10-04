---
name: Linked employee email
description: Why employee editing protects email when an admin account is attached.
---

Require a coordinated employee/account email-change flow before allowing email changes for employees with an admin login.

**Why:** Account creation copies the employee email into the login record, while the admin list displays employee contact data. Updating only the employee contact email would display a new address without changing the actual login address; silently changing the login record would also have unexpected credential effects.

**How to apply:** Preserve linked-account email protection unless deliberately implementing a validated email-change flow that keeps the displayed employee information and login address consistent. Other employee fields can be edited without changing usernames, passwords or account state.