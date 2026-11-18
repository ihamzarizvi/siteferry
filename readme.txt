=== SiteFerry – Backup, Restore & Migration ===
Contributors: xsofty, hamzarizvi
Tags: backup, migration, restore, google drive, cloud backup
Requires at least: 5.8
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.47.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and migrate WordPress sites. Scheduled backups to Google Drive, Dropbox, Amazon S3 or FTP, staging and one-click site moves.

== Description ==

**SiteFerry is a free WordPress backup, restore and migration plugin.** Back up your whole site in one click or on a schedule, keep copies in the cloud, and restore it, or move it to a new host or domain, in a few steps. No paid add-ons and no size limits.

= Back up =

* **One click:** "Back up now" saves the database, plugins, themes and media as one file.
* **On a schedule:** daily, weekly or monthly, at the time you choose, with old backups cleaned up for you.
* **Before updates:** a quick backup before WordPress, a plugin or a theme updates.
* **Smaller backups:** leave out media, plugins, caches, spam or revisions, or back up only what changed since the last full backup.
* **Password protection:** AES-256 encryption for backups that leave your server.

= Store anywhere =

* **Google Drive** and **Dropbox**, connected with one click.
* **Amazon S3** and S3-compatible storage: Backblaze B2, Wasabi, Cloudflare R2, DigitalOcean Spaces, MinIO.
* **FTP and FTPS** servers.
* Send each scheduled backup to several places, with a 3-2-1 check that shows how safe your copies are.

= Restore with confidence =

* **Preview first:** SiteFerry checks the backup and shows what will change before anything is touched.
* **Safety backup and Undo:** your site is backed up before a restore, so you can undo it.
* **Partial restore:** bring back only the database, one table, one plugin, one theme or single files.
* **Automatic rollback:** if a restore fails halfway, the site is put back as it was.

= Migrate and copy =

* **Move a site** straight from one server to another with a one-time key, encrypted end to end.
* **Change domain:** addresses are replaced everywhere, serialized data included.
* **Staging:** a private copy of the site in a subfolder, to test changes safely.
* **After-move check:** finds old addresses, http links on https sites, permalink, email and cache problems, with one-click fixes.
* **Multisite:** back up whole networks, take one site out, or add a site to a network.
* **.wpress files:** imports and makes backups in the All-in-One WP Migration format.

= Built for developers too =

* Standard TAR backup files with SHA-256 checksums, which any TAR tool can open.
* Full WP-CLI support (`wp siteferry`) with JSON output for scripts.
* Actions and filters to extend backups. See the [developer documentation](https://github.com/ihamzarizvi/siteferry).
* Role settings to let editors or shop managers use parts of SiteFerry.

== Installation ==

1. In your dashboard, go to **Plugins → Add Plugin**, search for **SiteFerry** and click **Install Now**, then **Activate**.
2. Open **SiteFerry** in the admin menu and click **Back up now**.
3. To keep copies off your server, open the **Storage** tab and connect Google Drive, Dropbox, S3 or FTP.
4. Click **Schedule** on the Backups tab to back up automatically.

== Frequently Asked Questions ==

= Is SiteFerry free? =

Yes. Every feature is included, with no paid add-ons, upsells or limits on site size.

= How do I restore a backup? =

On the Backups tab, click **Restore** next to the backup. SiteFerry checks it, shows what will change and asks you to confirm. A safety backup of the site is made first, so the Backups tab can offer **Undo this restore** afterwards.

= How do I move my site to a new host or domain? =

Install SiteFerry on the new site, open **Move a site**, choose **Receive a site here** and click **Make a key**. Paste that key into **Move a site** on the old site. The site is backed up, encrypted and sent straight to the new server. On the new site, click **Move it here**. Addresses are replaced for you.

You can also download a backup from the old site and use **Upload a backup** on the new one.

= How do I back up to Google Drive or Dropbox? =

Open the **Storage** tab, open Google Drive or Dropbox and click **Sign in**. SiteFerry can only see the files and folder it makes itself, nothing else in your account. Then choose that storage under **Customize** or in the **Schedule**.

= Where are backups stored on my server? =

In a protected folder with a random name inside the uploads folder (`wp-content/uploads/siteferry-<random>/`). Downloads always go through a signed-in request. On nginx servers, which ignore `.htaccess`, you can add `location ~ /uploads/siteferry-[a-f0-9]+/ { deny all; }` for extra protection.

= Are my passwords and storage keys safe? =

Storage keys, passwords and tokens are encrypted before they are saved, never shown again and never included in a backup. Backups can be protected with a password (AES-256-GCM). The password of a backup you make by hand is not stored anywhere.

= What is not included in a backup? =

Caches, WordPress's update folders, other backup plugins' folders, `.git`, `node_modules`, SiteFerry itself and its own settings.

= Does it work with large sites? =

Yes. Backups, uploads and transfers run in small resumable steps, so they work within normal PHP time limits. An interrupted backup or upload continues where it stopped.

= Does it work on multisite networks? =

Yes. On a network, super admins back up the whole network. You can also take one site out of a network backup as a single site, or add a single-site backup to a network as a new site.

= Can I restore All-in-One WP Migration (.wpress) backups? =

Yes. Upload the .wpress file and restore it like any other backup, or make a .wpress file under **Customize**. Password-protected .wpress files are not supported yet. SiteFerry is not affiliated with or endorsed by ServMask or All-in-One WP Migration, and contains none of its code; the name is used only to say which files SiteFerry can read and write.

= How do I make a staging site? =

Click **Staging** on the Backups tab, choose a subfolder name and click **Create the staging copy**. The copy has its own database tables, a STAGING badge, is hidden from search engines and holds back email. Delete it from the same place when you are done.

= Can I use WP-CLI? =

Yes: `wp siteferry export`, `wp siteferry import`, `wp siteferry backups list`, `wp siteferry storage send`, `wp siteferry schedule run` and more. Run `wp help siteferry` for every command and option.

= Where can I get help or read more? =

Ask in the [support forum](https://wordpress.org/support/plugin/siteferry/). The full documentation, including the backup format, every WP-CLI option and the developer hooks, is on [GitHub](https://github.com/ihamzarizvi/siteferry).

== External services ==

SiteFerry runs on your server. It contacts another service only when you set that service up or ask for it. Nothing is sent in the background, there is no telemetry, and on a site with no storage set up SiteFerry contacts nothing.

= Amazon S3 and S3-compatible storage =

* **What and why:** cloud storage for backup copies, used only if you add an S3-compatible storage on the Storage tab: Amazon S3, or another provider such as Backblaze B2, Wasabi, Cloudflare R2 or DigitalOcean Spaces, at the endpoint you enter.
* **What is sent, and when:** when you save or test the settings, and when you (or a schedule you set) send, list, download, check or delete backups, SiteFerry sends requests signed with your access keys to that endpoint, and the backup files themselves when sending.
* **Terms and privacy:** for Amazon S3, the [AWS service terms](https://aws.amazon.com/service-terms/) and [AWS privacy notice](https://aws.amazon.com/privacy/). For another provider, its own terms and privacy policy apply.

= FTP and FTPS servers =

* **What and why:** a file server of your choice that holds backup copies, used only if you add an FTP or FTPS storage on the Storage tab.
* **What is sent, and when:** when you save or test the settings, and when you (or a schedule you set) send, list, download or delete backups, SiteFerry logs in with the user name and password you entered and transfers the backup files.
* **Terms and privacy:** the server is yours or your host's; its owner's terms and privacy policy apply.

= Google Drive =

* **What and why:** Google's cloud storage for backup copies, used only if you connect Google Drive on the Storage tab.
* **What is sent, and when:** when you connect, your browser signs in at Google (accounts.google.com) and this site exchanges the sign-in code for access tokens (oauth2.googleapis.com). When you (or a schedule you set) send, list, download or delete backups, SiteFerry uses the Drive API (www.googleapis.com) with the drive.file scope, so it only sees files it made and the folder you picked. When you click "Choose a folder in Drive", the Storage tab loads Google's folder picker from apis.google.com. When you disconnect, the token is revoked at oauth2.googleapis.com.
* **Terms and privacy:** Google's [terms of service](https://policies.google.com/terms) and [privacy policy](https://policies.google.com/privacy).

= Dropbox =

* **What and why:** Dropbox's cloud storage for backup copies, used only if you connect Dropbox on the Storage tab.
* **What is sent, and when:** when you connect, your browser signs in at Dropbox (www.dropbox.com) and this site exchanges the sign-in code for access tokens (api.dropboxapi.com). When you (or a schedule you set) send, list, download or delete backups, SiteFerry uses the Dropbox API (api.dropboxapi.com, content.dropboxapi.com) with access to its own app folder only.
* **Terms and privacy:** Dropbox's [terms of service](https://www.dropbox.com/terms) and [privacy policy](https://www.dropbox.com/privacy).

= SiteFerry sign-in service (auth.xsofty.com) =

* **What and why:** a small service run by the publisher of SiteFerry at `https://auth.xsofty.com/siteferry`. It lets you connect Google Drive or Dropbox with one click, without making your own Google OAuth client or Dropbox app. It is used only if you choose "Sign in with Google" or "Sign in with Dropbox"; with your own client or app it is never contacted.
* **What is sent, and when:** while you sign in, your browser passes through it with this site's SiteFerry page address, a random state value and a PKCE challenge. For Google Drive, this site then sends it the one-time sign-in code with the PKCE verifier, and later the refresh token whenever access needs renewing; the service adds SiteFerry's app credentials and passes the request on to Google. For Dropbox, this site only asks it for the public app key. The service stores nothing, and backups never go through it.
* **Terms and privacy:** xsofty's [privacy policy](https://xsofty.com/products/siteferry/privacy-policy) and [terms and conditions](https://xsofty.com/products/siteferry/terms-and-conditions).

= Another WordPress site (moving a site) =

* Only when you paste a move key made on another site that runs SiteFerry and start the move, SiteFerry sends this site's backup, encrypted, to the address in that key. That site is chosen by you and run by you or its owner.

== Screenshots ==

1. Back up the whole site in one click and see every backup, with where each copy is stored.
2. Before a restore, SiteFerry checks the backup and shows what will change. A safety backup lets you undo it.
3. Choose what goes into a backup, and see its size and how long it takes before you start.
4. Storage: Amazon S3 and S3-compatible services, FTP/FTPS servers, Google Drive and Dropbox.
5. Scheduled backups: daily, weekly or monthly, to one or more storages, with a 3-2-1 check.
6. Move a site straight to another server with a one-time key.
7. Staging: a private copy of the site in a subfolder, to try changes safely.
8. After a move or restore, check the site for common problems, with fixes you run with one click.

== Changelog ==

= 0.47.1 =
* New plugin name on WordPress.org and a clearer description.

= 0.47.0 =
* Backups are now kept in the uploads folder. Existing backups are moved there automatically after the update.
* The emergency kit was removed. Kits made with earlier versions stop working.
* Database work during restores now goes through WordPress's database layer.

= 0.46.0 =
* New "Check the site" after a move or restore: finds old addresses, mixed content, permalink, email and cache problems, with one-click fixes.
* New WP-CLI command: `wp siteferry checklist`.

= 0.45.0 =
* Multisite: take one site out of a network backup, or add a single-site backup to a network as a new site.

[Full changelog](https://github.com/ihamzarizvi/siteferry/blob/main/changelog.txt)
