# ADR-0005 — Owner-granted read-only support access

**Status:** Proposed — needs confirmation

## Context
"Farm data belongs to the Farm Owner." The System Admin must not operate
farms. Support staff still sometimes need to see what an owner is seeing to
fix a problem.

## Decision
- By default the admin has **no** access to farm operational data.
- The Farm Owner can grant, from a support ticket, **read-only** access for a
  fixed window (default 24 h, max 72 h) to named support staff.
- During the window, support sessions use a special read-only member
  context. Every request is written to the audit log. The owner sees a banner
  and can revoke access at any time.
- Write endpoints return 403 in support sessions, whatever the permissions.

## Alternative
No support access at all. Troubleshooting then relies on screenshots and
aggregate logs.
