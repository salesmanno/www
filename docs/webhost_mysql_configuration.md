# Database configuration on an external webhost

Replit Secrets are supplied to the Replit PHP process. They do not automatically
configure a separate webhost. Keep the same database/account; no migration or
password change is needed for this configuration.

## Supported configuration

1. Supply `MYSQL_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` and,
   optionally, `MYSQL_PORT` in the webhost's PHP environment; or
2. Put a private PHP configuration **outside the public document root**.

For a server whose document root is `/home/account/www`, the default private file is
`/home/account/salesman-private/mysql.php`, even if the project is installed in a
subdirectory. Use `MYSQL_CONFIG_FILE` to identify a different private file. The application
rejects a file inside the document root, including symlinks pointing there.

The file returns an array of these keys:

```php
<?php
return [
    'host' => 'YOUR_DATABASE_HOST',
    'database' => 'YOUR_DATABASE_NAME',
    'user' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'port' => '3306',
];
```

Enter the existing values directly on the host through its secure file manager
or SSH. Do not send passwords in chat, commit this file, or place it under `www`.
Restrict file permissions so only the account/PHP process can read it.
Environment values take precedence over private file settings.

## Portal entrypoints and server errors

Both `/saas-portal/` and `/saas_portal/` redirect to `/saas_portal/login.php`.
Deploy the new entrypoints with the existing portal, not as an isolated login
file. Deploy all `config` helpers together as well.

The root `.htaccess` disables Apache directory listings and protects hidden
files, database exports and project metadata. The `config` and `docs` rules deny
HTTP access while still allowing PHP to load configuration internally.
These rules must be installed on the external host; changing workspace files
alone does not update that host. If its server rejects a directive, inspect the
host's error log and configure the equivalent protection there. Never remove
protection just to enable directory browsing.

A generic HTTP 500 is not proof of a bad database password. Check the webhost
error log for PHP errors, missing helper files, PHP version or Apache directives.
PHP 8 is required by this project. Authentication logs only the exception type
and driver code, not addresses, credentials or raw driver messages.
The login page distinguishes invalid/missing configuration, failed database
connections and failed queries. Updating the Git checkout does not populate
host-managed database configuration.

## Which .htaccess belongs where?

The root `.htaccess` belongs beside the project's root `index.php`, not inside
`public`. The separate `public/.htaccess` belongs inside `public`.
Do not copy a file with `RewriteBase /public/` into the webroot. Do not rewrite
missing assets to the same asset path: Apache can repeat the rewrite until it
returns HTTP 500. Existing company directories need only `DirectoryIndex`.

Keep a backup of the host's existing configuration before replacement. If it
contains unrelated host rules, merge the corrected routing rather than deleting
those rules. The uploaded old file had no database credentials or PHP environment
configuration; it cannot establish whether the host has the MySQL settings.
