# Domeneshop SFTP deployment

## What changed

The FTPS deployment failed with `Server sent FIN packet unexpectedly, closing connection`
while creating nested PhpSpreadsheet directories. This proves the connection was closed
during directory creation, not that SaaS database authentication failed. The roughly
five-minute duration is consistent with an FTP no-transfer timeout, but the supplied log
does not include a `421` reply or Domeneshop's server-side reason, so the timeout remains
the likely explanation rather than a confirmed provider diagnosis.

The workflow now uses actual SFTP on SSH port 22, not FTPS renamed to SFTP. It uses a
commit-pinned release of easySFTP, verifies the SSH host key, and retains `vendor`.
No separate SSH shell, remote extraction command, or remote Composer installation is needed.

## One-time GitHub setup

In the repository's **Settings → Secrets and variables → Actions**:

1. Keep the existing secrets `FTP_USERNAME` and `FTP_PASSWORD`. Domeneshop documents
   that the same account credentials are used for FTP and SCP/SFTP. Do not paste them
   into the workflow, logs, or chat.
2. Under **Variables**, add `DOMENESHOP_SFTP_HOST_KEY` containing the verified server
   SHA256 SSH fingerprint(s), one per line. The fingerprint is public, not a password.
   Obtain candidate fingerprints with:

   ```sh
   ssh-keyscan -T 10 -p 22 sftp.domeneshop.no | ssh-keygen -lf -
   ```

   Confirm them against Domeneshop or a previously trusted SSH/SFTP connection before
   saving them. A network scan alone is not independent proof of the server's identity.
   The workflow deliberately fails if the pin is absent or does not match; do not
   disable host-key verification to work around an error.
3. Verify that this hosting account permits SFTP. Domeneshop's documentation lists
   SFTP access as dependent on the hosting package.

The destination is the `www` directory relative to the SFTP login directory, preserving
the previous FTP destination. Confirm that it is the directory serving `salesman.no`
in the first preview before allowing an upload.

## Preview and explicit upload

- A push to `main` runs a **read-only preview**, not a live upload.
- A manual **Actions → Domeneshop SFTP → Run workflow** also defaults to preview.
- Only manually selecting **Upload to live salesman.no** enables writes. This overwrites
  matching deployed files and may change the live application immediately.
- `overlay` uploads files but never deletes existing remote files or directories.
  It does not migrate the database, run PHP scripts, or remove old server-only files.
- Runs are serialized so two deployments cannot write to the site simultaneously.

No upload has been performed as part of preparing this workflow. The operator must
approve a live run separately.

After a live run, check that its commit SHA matches the intended source commit and
that the run succeeds. Then verify the affected URLs on the actual webhost; a running
Replit preview is not evidence that Domeneshop has received the files.

## Sources

- https://domene.shop/faq?id=56 — SFTP host, shared FTP/SFTP credentials, and `www` directory.
- https://domene.shop/faq?id=332 — SFTP as an alternative when FTP fails and hosting requirements.
- https://github.com/eiserv/easySFTP/tree/v3.0.0 — SFTP action inputs, host-key verification,
  overlay semantics, and read-only preview.
