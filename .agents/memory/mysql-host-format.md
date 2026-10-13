---
name: MySQL host format
description: Existing MySQL host configuration may be supplied as a host URL.
---

Accept both plain hostnames and host URLs in the existing MySQL configuration, without exposing or rewriting secret values.

**Why:** Switching the shared connection to environment configuration broke SaaS login because the supplied host included a URL scheme. Treating the whole URL as a DNS hostname failed; extracting its hostname restored the actual connection.

**How to apply:** Preserve host-format compatibility when changing shared database configuration. Verify actual connection and the SaaS administrator lookup, not just synthetic fixture connectivity. Never restore embedded credentials as a fallback.
