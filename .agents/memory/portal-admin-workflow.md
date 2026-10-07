---
name: Portal administration workflow
description: User's intended relationship between the company overview, employees and login accounts.
---

Keep global activity and logged-in users together on the company overview, rather than separating them into different screens. Company detail is centered on its activity, with employees at top-right and company settings (including SEO, excluding separate users/admins tabs) below.

**Why:** The user explicitly requested this administration flow rather than the previous split views.

**How to apply:** Preserve this hierarchy when extending the portal. New “Opprett SuperUser” actions belong to the selected employee and create a tenant login, not a SaaS-wide administrator. Existing unlinked accounts must remain manageable; do not delete or silently attach them during UI restructuring.

Bulk company-module assignment adds permissions without removing existing grants.

**Why:** The requested operation was to give selected companies module access, not to replace all their existing permissions.

**How to apply:** Any future bulk revoke/replace operation must be separately labelled and explicitly confirmed.
