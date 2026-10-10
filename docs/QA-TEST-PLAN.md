# PushLocal 1.0 — QA Smoke Tests

Before a public announcement, run these on a non-critical custom plugin:

1. **Unchanged project** — Check for Changes returns zero NEW/MODIFIED.
2. **Modified file** — deploy one harmless change, then rollback.
3. **New file** — deploy a harmless text file, rollback removes it.
4. **Mirror delete** — create a server-only text file, delete it with Mirror Mode, rollback restores it.
5. **PHP syntax guard** — introduce a syntax error in a harmless changed PHP file; commit must be rejected before target mutation.
6. **Remote conflict** — change a compared server file before deploy; deployment must stop.
7. **Rollback conflict** — change a deployed server file before rollback; rollback must stop.
8. **Folder persistence** — reload and verify folder pairing/reconnect.
9. **.pushlocalignore** — verify ignored patterns disappear from both manifests.
10. **Environment check** — required filesystem checks pass and optional diagnostics never crash the page.
