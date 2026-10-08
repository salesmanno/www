---
name: Active-schema drift
description: Why tenant deletion must not assume the uploaded database dump matches the active database.
---

Treat the active database schema, not an uploaded SQL dump or previously installed routine, as authoritative for installed optional modules.

**Why:** The user reported that deletion failed because an optional reservation booking table was absent even though the uploaded older dump contained it. Direct database access from the development environment was separately refused, so the dump could not establish live schema details.

**How to apply:** Use read-only active metadata when available and schema-aware operations. Test with optional modules absent. Do not install missing tables merely to satisfy an outdated deletion list, or describe isolated test results as production verification.

Existing legacy endpoints are also not authoritative documentation of the active schema.

**Why:** Older website editing code referenced a company-level SEO configuration that differed from the active installation's module-level configuration and separate general website settings.

**How to apply:** Verify field ownership in live metadata before reusing old SQL. Preserve unrelated settings and support the installed layout rather than creating a competing settings table.

Preserve existing credentials by default when no compatible encoding contract has been established; a column ending in `_encrypted` does not establish that its values are actually encrypted.

**Why:** The portal's obsolete settings layout did not establish how the active modules decode their encrypted credentials. Writing plaintext into a column named “encrypted” would risk both broken connections and insecure storage.

**How to apply:** Settings adapters may update non-secret fields and explicitly remove secrets without a codec. SaaS-admin must manage payment setup without company-module restrictions. Default replacements in encrypted-named fields require the owning module's verified codec. Following the user's request to remove the blanket block, raw replacements may instead use an explicit, fresh confirmation that the payment module expects plaintext, with a visible unencrypted-storage warning. Never select raw storage automatically, convert untouched values, or infer the format from the column name.

Separate schema compatibility from request-time database availability.

**Why:** Direct company SEO reads succeeded while later authenticated portal requests were refused by the external MySQL connection. A successful point-in-time schema/read check did not establish that the reported HTTP failure was fixed.

**How to apply:** Correlate the reported error with current server logs in the correct environment. Distinguish connection-stage failures from schema errors, and do not describe isolated tests or an unauthenticated screenshot as verification of the user's signed-in flow.

Isolated MySQL fixtures that use the application's audit-table initializer need an explicit compatible database collation.

**Why:** MySQL 8's default collation differed from the initializer's legacy Unicode collation, causing otherwise valid audit/tenant joins to fail in integration tests.

**How to apply:** Match the fixture's collation to the intended installation; don't mistake a fresh test database's defaults for the live schema.