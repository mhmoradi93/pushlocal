# PushLocal WP

**Local code. Live in seconds.**

PushLocal is a free, open-source WordPress developer tool for deploying local plugin changes without rebuilding ZIP files or using Git/FTP on the server.

![PushLocal banner](site/assets/social-banner-1544x500.png)

## Why PushLocal?

A one-line plugin fix should not mean **ZIP → upload → replace → repeat**.

PushLocal turns that loop into:

**Check → Review → Deploy**

It pairs an installed WordPress plugin with its local source folder, compares SHA-256 manifests, and deploys only the changes you approve.

## Highlights

- Incremental **NEW / MODIFIED** deployment
- Optional **Mirror Mode** for backed-up remote-only deletions
- **PHP syntax validation** before commit
- **SHA-256 verification** across compare, staging, deploy and rollback
- **Conflict protection** if the server changed after comparison
- **Backup + manual rollback**
- Persistent local-folder pairing using browser IndexedDB
- `.pushlocalignore`
- No PushLocal cloud account or telemetry service

## Requirements

- WordPress 6.5+
- PHP 8.1+
- HTTPS wp-admin
- Chromium-based browser with `showDirectoryPicker()`
- Direct PHP write access to the target plugin directory

## Install

1. Download the latest ZIP from the [dist folder](dist/pushlocal-1.0.1.zip).
2. In WordPress go to **Plugins → Add Plugin → Upload Plugin**.
3. Upload and activate PushLocal.
4. Open **Tools → PushLocal**.
5. Select the target plugin and pair its local source folder.

## Safety model

PushLocal is a privileged developer tool. It verifies server state immediately before mutation, stages uploads as non-PHP blobs, parses changed PHP before commit, backs up modified/deleted files, and refuses unsafe rollback conflicts.

See [SECURITY.md](SECURITY.md) for details.

## WordPress.org

PushLocal is distributed directly rather than through the WordPress.org Plugin Directory because its core purpose is deploying executable plugin source code. See [DISTRIBUTION.md](DISTRIBUTION.md).

## Website

The product site is hosted from this repository with GitHub Pages.

## License

GPL-2.0-or-later.
