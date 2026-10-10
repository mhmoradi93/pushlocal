=== PushLocal ===
Contributors: mhmoradi93
Tags: development, deploy, plugin, local, rollback
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later

Deploy local WordPress plugin changes without ZIP, Git, FTP, or repeated manual uploads.

== Description ==

PushLocal pairs an installed WordPress plugin with its local source folder, compares SHA-256 manifests, and deploys only the changes you approve.

Features:
* Persistent local-folder pairing.
* NEW and MODIFIED incremental deployment.
* Optional Mirror Mode for backed-up REMOTE ONLY deletions.
* PHP syntax validation before commit.
* SHA-256 verification.
* Server preflight.
* Deployment History and manual Rollback.
* Conflict protection.
* .pushlocalignore.
* Configurable retention.

PushLocal is a privileged developer tool and requires direct PHP write access to the target plugin directory.

== Installation ==

1. Upload the plugin ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate PushLocal.
3. Open Tools > PushLocal.
4. Select a target plugin and pair its local source folder.

== Browser Requirements ==

HTTPS wp-admin, Chromium with showDirectoryPicker(), and IndexedDB.

== License ==

GPLv2 or later.
