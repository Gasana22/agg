# ADR-0007 — Web session handling (BFF, refresh races)

**Status:** Accepted (2026-09-23, Phase 1)

## Context
The API issues short-lived JWT access tokens and rotating refresh tokens, and
treats a reused refresh token as theft (ADR-0006, docs/01 §6). A browser
often sends several requests at once. If the access token has just expired,
each of them would try to rotate the same refresh token. The second rotation
would then look like theft and sign the user out.

## Decision
1. **Tokens never reach browser JavaScript.** The Next.js server is a
   backend-for-frontend (`/api/auth/*`, `/api/proxy/*`). It keeps the tokens in
   `httpOnly`, `SameSite=Lax` cookies (`Secure` in production). It also refuses
   state-changing requests whose `Origin`/`Referer` is not the app's own
   origin (CSRF).
2. **The BFF deduplicates refreshes.** Concurrent requests carrying the same
   refresh token share one rotation, and the result is reused for 10 s.
3. **The API allows a 30 s reuse window.** A just-rotated refresh token presented
   again within `sfmtp.refresh.reuse_grace_seconds` (30 s) gets
   `401 refresh_token_superseded` *without* revoking the session. After that
   window, reuse is treated as theft: the whole session is revoked and the
   event is audited.

## Consequences
- An attacker who replays a stolen refresh token within 30 s of the real
  client's rotation gets refused, but the session is not revoked. Replays after
  that revoke it. This matches common practice (a "reuse interval").
- With several web instances, deduplication is per instance. The 30 s API
  window covers the cross-instance case.
