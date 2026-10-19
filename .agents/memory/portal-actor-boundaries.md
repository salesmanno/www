---
name: SaaS and tenant actor boundaries
description: Why onboarding must not invent tenant accounts to satisfy required configuration audit fields.
---

SaaS administrator IDs and tenant administrator IDs are different identity domains, even though both use CHAR(36). Never attach the SaaS actor ID to a tenant-admin foreign key or create a tenant login merely to satisfy a configuration row's creator requirement.

**Why:** The portal has SaaS-only authentication, and prospect conversion must create employees without login accounts. The supplied schema requires a tenant administrator as the website configuration creator, which cannot be satisfied honestly by the portal actor.

**How to apply:** Check the active foreign-key target and nullability when provisioning audit fields. Initialize configuration only when a valid actor is available. If mandatory tenant ownership prevents initialization, report the deferred setup explicitly before and after creation; preserve the company and employee workflow without manufacturing accounts or disabling constraints.
