---
name: Active-schema drift
description: Why tenant deletion must not assume the uploaded database dump matches the active database.
---

Treat the active database schema, not an uploaded SQL dump or previously installed routine, as authoritative for installed optional modules.

**Why:** The user reported that deletion failed because an optional reservation booking table was absent even though the uploaded older dump contained it. Direct database access from the development environment was separately refused, so the dump could not establish live schema details.

**How to apply:** Use read-only active metadata when available and schema-aware operations. Test with optional modules absent. Do not install missing tables merely to satisfy an outdated deletion list, or describe isolated test results as production verification.