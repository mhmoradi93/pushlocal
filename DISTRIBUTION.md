# Distribution

## Recommended

PushLocal is a free open-source developer tool distributed through:

- This GitHub repository
- GitHub's source ZIP / future Releases
- The GitHub Pages product site

Users can install the repository ZIP through **Plugins → Add Plugin → Upload Plugin** because `pushlocal.php` lives at repository root.

## WordPress.org Plugin Directory

The current PushLocal architecture is not a good fit for the WordPress.org Plugin Directory because its core purpose is to accept local source code and write executable PHP/JavaScript into an installed plugin directory.

The official Plugin Developer FAQ says new plugins that allow arbitrary code insertion or execution are not accepted and explicitly lists PHP/JavaScript editors and file managers as examples.

Do not remove PushLocal's core security model merely to obtain directory eligibility. A separate read-only comparison/diagnostics product could be considered for WordPress.org in the future.
