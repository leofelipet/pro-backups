=== Pro Backups ===
Contributors: leofelipet
Tags: backup, restore, migration, wpress, clone
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free, unlimited backup and restore of .wpress archives. No upload size limit, no paid add-ons.

== Description ==

Pro Backups creates and restores complete WordPress backups (database, media, plugins, themes) in the
`.wpress` format, the same format used by All-in-One WP Migration. Everything is free:

* **No size limit.** Backups are uploaded in small parts, so the PHP upload limit of your host does not matter, and the upload resumes after connection drops. Or copy the file by FTP to `wp-content/pro-backups/` and restore it from the list.
* **Restore .wpress backups made by All-in-One WP Migration**, including gzip/bzip2 compressed and encrypted ones. Backups found in `wp-content/ai1wm-backups/` are listed too.
* **Backups made by Pro Backups can be restored by All-in-One WP Migration.**
* Moves sites between domains and folders: URLs, paths and e-mail domains are replaced, also inside serialized data.
* AES-256 encryption, gzip / bzip2 compression.
* Exclude media, plugins, themes, inactive plugins/themes, cache, spam comments, post revisions, specific files or tables.
* Scheduled backups (hourly, twice daily, daily, weekly, monthly) with retention (keep N, delete older than X days, total size limit).
* Reset tools (media, plugins, themes, database) that always make a full backup first.
* WP-CLI: `wp pro-backups backup`, `wp pro-backups restore`, `wp pro-backups list`, `wp pro-backups delete`.
* Works on MySQL, MariaDB and SQLite (SQLite Database Integration).

Every long task is split into short requests, so it works on hosts with a 30 second time limit.

Pro Backups is an independent project and is not affiliated with ServMask or All-in-One WP Migration.

= Security =

* Backups are stored in `wp-content/pro-backups/`, protected from direct web access (`.htaccess`, `web.config`, `index.php`). Downloads go through an authenticated, nonce-protected endpoint. On nginx, add a `deny` rule for that folder.
* Starting a backup, restore or reset requires the `manage_options` capability and a nonce. A running job is continued with a random per-job token (a restore replaces the users table, so the session cannot be used).
* Archive entries with `../` or absolute paths are rejected.

== Installation ==

1. Upload the plugin and activate it.
2. Open **Pro Backups** in the admin menu.

== Frequently Asked Questions ==

= My backup is bigger than the upload limit of my host =

That is fine: the file is sent in parts. For very large files (many GB) on slow connections, upload the `.wpress` by FTP/SFTP to `wp-content/pro-backups/` and click **Restore** in the list.

= Can I restore a multisite network backup? =

Not yet. Network backups and restoring into a multisite network are refused with a clear message, before anything is changed.

= What happens to my login after a restore? =

The users of the backup replace the current ones. Log in with a user from the backup.

= Where is the encryption password of a scheduled backup stored? =

In the database (options table), so that backups can run unattended. Leave it empty if that is a concern.

== Changelog ==

= 0.1.0 =
* First release: backup, restore (unlimited size), schedules, retention, reset tools, WP-CLI.
