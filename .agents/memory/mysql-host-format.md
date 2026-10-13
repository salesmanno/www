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

The external MySQL endpoint can advertise both address families while IPv6 is unreachable from this workspace.

**Why:** On 2026-10-13, connection-stage refusals were intermittent; TCP and PDO checks succeeded over IPv4, while the advertised IPv6 TCP route failed. This does not prove every refusal has that cause.

**How to apply:** Distinguish hostname resolution and transport failures from credentials or schema errors. An isolated test or a single successful live read does not establish sustained availability; do not blame a provider or claim a root cause without evidence.
