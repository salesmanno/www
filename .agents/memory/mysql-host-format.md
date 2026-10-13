---
name: MySQL host format
description: Existing MySQL host configuration may be supplied as a host URL.
---

Accept both plain hostnames and host URLs in the existing MySQL configuration, without exposing or rewriting secret values.

**Why:** Switching the shared connection to environment configuration broke SaaS login because the supplied host included a URL scheme. Treating the whole URL as a DNS hostname failed; extracting its hostname restored the actual connection.

**How to apply:** Preserve host-format compatibility when changing shared database configuration. Verify actual connection and the SaaS administrator lookup, not just synthetic fixture connectivity. Never restore embedded credentials as a fallback.

The same project must run in Replit and after GitHub export to Domeneshop web hosting.

**Why:** The user reported that their two other websites connect to the same database, while this project's export lost login after the switch to Replit environment configuration.

**How to apply:** Preserve an explicit private configuration route for external hosting; do not assume Replit environment variables accompany GitHub exports or restore credentials to the repository.
