# Contributing to PushLocal

PushLocal is a small, security-sensitive WordPress developer tool.

Before proposing a change:

1. Keep the deploy path minimal.
2. Do not weaken capability, nonce, path or hash checks.
3. Do not add external telemetry or cloud dependencies without explicit product review.
4. Preserve automatic rollback/compensation behavior.
5. Test NEW, MODIFIED, DELETE, rollback and conflict scenarios.
6. Run PHP lint and JavaScript syntax checks.

Feature requests should explain the developer workflow problem first, not just the proposed UI.
