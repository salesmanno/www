---
name: Isolated MySQL test environment
description: Prevent test CLI tools from inheriting the application's real MySQL connection settings.
---

Isolated MySQL CLI tests must use an explicit Unix-socket protocol, host, socket and fixture credentials, plus a child-process environment allowlist.

**Why:** MySQL's `--no-defaults` ignores configuration files, not ambient connection environment variables. A test command that specified only a socket still attempted the configured application host.

**How to apply:** Keep fixture sockets under `/tmp`, force `--protocol=SOCKET`, and do not inherit connection variables or print raw client errors. PDO tests must likewise use an explicit fixture Unix-socket DSN, never the app's connection helper.
