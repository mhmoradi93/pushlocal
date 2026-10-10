# PushLocal Security

PushLocal is intentionally a privileged developer tool. It can write executable plugin code.

## Security model

- Access requires the WordPress `update_plugins` capability.
- REST routes require authenticated WordPress REST nonces.
- Server targets come from installed plugins, never arbitrary absolute paths.
- Path traversal and existing destination symlinks are rejected.
- Staged uploads are stored as non-PHP `.blob` files.
- SHA-256 checks are performed across compare, staging, commit and rollback.
- PHP files are parsed before commit.
- Modified/deleted files are backed up before mutation.
- Deploy and rollback stop on server-state conflicts.
- Mirror Mode is opt-in after every scan.

## Reporting

If you discover a security issue, avoid publishing exploit details in a public issue until a fix is available. Contact the maintainer privately first.
