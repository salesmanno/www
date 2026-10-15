# Domeneshop FTP deployment

The workflow uses FTP with mandatory TLS (explicit FTPS) to `ftp.domeneshop.no`,
port 21, uploading to `www/`. It reuses GitHub Actions secrets `FTP_USERNAME`
and `FTP_PASSWORD`. No SSH, SFTP, fingerprint, new credential or server package
is required. Certificate validation stays enabled.

## Transfer correction

The previous FTP action lost its connection while creating nested vendor directories,
before the file-transfer phase. Its roughly five-minute duration suggests a server
no-transfer timeout, but the supplied log does not confirm the server's exact reason.

The replacement uses the standard curl client already present on the GitHub runner.
It creates the parent directory for each file immediately before uploading that file,
instead of creating the entire directory tree before transferring anything. Curl can
reuse its connection across files; reconnects/retries are bounded. A failed file stops
the run and returns a failure, not a successful deployment.

All tracked application files remain eligible, including `vendor` and `.htaccess`.
The existing Git/Replit/cache/agent/node_modules exclusions are preserved. Symlinks
and untracked local files are not uploaded. Temporary credential configuration is
owner-readable only and removed when curl exits. Credentials are never command-line
arguments or printed to the log. No remote delete commands are sent.

## Preview and approved live upload

A push to `main` only lists the selected files. Preview does not connect to the FTP
server and does not require credentials. This is not proof of successful authentication.

To approve a live upload, open **Actions → Domeneshop FTP → Run workflow** on `main`
and explicitly select **Upload to live salesman.no**. This overwrites matching files
on the live site, so approve it only when ready. It does not delete server-only files,
execute migrations, or run application PHP scripts.

Afterward verify the run's SHA matches the intended commit, its transfer step succeeds,
and the affected page on the actual webhost runs the expected version. Offline checks
and a successful preview do not prove that the webhotel has received the files.

For a read-only check, manually select **Read-only FTP file comparison** and leave
**Upload to live salesman.no** off. This reads three fixed entry files from `www`,
compares their hashes against the checkout, and reports existence and match status
only. It never prints file contents, account names, or credentials and never writes
to the webhost. Inspection and publishing cannot be combined.

The overall workflow limit is 90 minutes. The previous 20-minute limit interrupted
a manual run after 383 successful file transfers, leaving the deployment incomplete.

Uploads are not atomic across the whole site. An interruption may leave a mix of old
and new files; the job fails and a new approved run can retry. Removed local files are
not automatically removed from the server.

## Checks

```sh
actionlint .github/workflows/Ftp_deployment.yaml
python3 .github/tests/test_ftp_deployment.py
python3 .github/scripts/deploy_ftp.py
```
