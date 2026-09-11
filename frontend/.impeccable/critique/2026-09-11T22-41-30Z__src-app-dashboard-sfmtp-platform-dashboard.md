---
target: SFMTP platform dashboard
total_score: 16
p0_count: 2
p1_count: 2
timestamp: 2026-09-11T22-41-30Z
slug: src-app-dashboard-sfmtp-platform-dashboard
---
Method: dual-agent (Assessment A + Assessment B, run as isolated parallel sub-agents)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 2 | No active-page indicator in sidebar nav; role-restricted pages hang on "Loading…" forever after a 403 instead of surfacing an error |
| 2 | Match System / Real World | 2 | Money renders as bare unformatted numbers; farm hierarchy (Farm→Block→Section→Plot) has no cumulative breadcrumb |
| 3 | User Control and Freedom | 1 | Zero confirmation dialogs anywhere in the codebase for destructive actions |
| 4 | Consistency and Standards | 3 | Card→Table→Dialog pattern reused verbatim and predictably across 15+ modules |
| 5 | Error Prevention | 1 | Same zero-confirmation finding; no numeric guards on amount fields |
| 6 | Recognition Rather Than Recall | 1 | Zero aria-label attributes on icon-only action buttons anywhere in the dashboard |
| 7 | Flexibility and Efficiency | 1 | No bulk actions, no shortcuts, no skip-link — full 15-item nav must be tabbed through on every page |
| 8 | Aesthetic and Minimalist Design | 3 | Restrained, uncluttered, sensible OKLCH tokens |
| 9 | Error Recovery | 1 | Restricted routes fail open into silent infinite "Loading…" instead of a permission message |
| 10 | Help and Documentation | 1 | No help affordance or contextual guidance anywhere |
| **Total** | | **16/40** | **Poor — significant improvements needed before users are happy** |

## Anti-Patterns Verdict

**LLM assessment (Assessment A)**: Largely clean of AI slop — no gradient text, no glassmorphism, no numbered-scaffold eyebrows, no hero-metric template. The repeated Card→Table→Dialog pattern is earned consistency for a multi-module data product, not templated filler. One soft symptom: the dashboard's `StatCard` tiles are a generic SaaS shape and currently show all zeros with no onboarding nudge, reading as broken rather than intentionally empty.

**Deterministic scan (Assessment B)**: Static CLI scan (`detect.mjs`) across `src/app/dashboard` and `src/components/ui` returned zero findings (confirmed the tool itself works via a synthetic anti-pattern file that correctly triggered 5 findings). The live browser/DOM overlay, injected into 5 authenticated pages, found two things: `overused-font`/`single-font` (Geist used exclusively) and one `skipped-heading` on the Finance page (`<h1>Finance</h1>` followed directly by `<h3>Income</h3>`, no `<h2>`).

**Synthesis**: The single-font flag is very likely a false positive for this register — `reference/product.md` explicitly permits "System fonts and familiar sans defaults" and states "One family is often right" for product UI, so this isn't actionable. The heading-skip finding usefully corroborates Assessment A's independent finding that the Finance page is one of the weakest surfaces in the product (also flagged there for its silent-403 dead-end and unformatted currency) — both assessments converged on Finance without coordinating.

No user-visible browser overlay was produced (Assessment B ran headless in an automated sub-agent, not a human-observable tab) — the console findings above are the full evidence from that path.

## Overall Impression

This is an honest, unglamorous CRUD product with a correctly-scoped visual language (Restrained color strategy, one font family, consistent card/table/dialog vocabulary) and a genuinely well-architected permission model. It does not look AI-generated and does not chase templated design trends. But it fails the category bar on safety and accessibility: nothing is confirmed before it's deleted, nothing is labeled for assistive tech, and the entire product has no mobile navigation at all — a serious gap given its actual users are farm workers who are often on phones in the field. The biggest opportunity isn't visual polish; it's closing the gap between "looks like a real product" and "behaves like a trustworthy one."

## What's Working

1. **Empty-state copy teaches the domain, not just "no data."** e.g. "Blocks divide a farm into sections and plots for crop seasons." — this is real product thinking, not boilerplate.
2. **The permission model is architected correctly, not bolted on.** `usePermissions()` explicitly mirrors backend `canManage*()` methods and keeps the server as the real enforcement point — buttons that would 403 simply don't render. The role-conditional dashboard (different stat cards per role) shows genuine thought about six distinct job functions.
3. **Component-level a11y primitives are done right, just not propagated.** `focus-visible:ring-2` and disabled states are handled once in shared `button.tsx`/`input.tsx`, and the `Dialog` close button correctly uses `sr-only` text — the pattern exists, it just wasn't carried to the ~15 other icon-button call sites.

## Priority Issues

**[P0] No mobile navigation exists at all**
- **Why it matters**: The sidebar (`layout.tsx`, `hidden ... md:flex`) disappears completely below the `md` breakpoint with no hamburger, drawer, or bottom nav replacement. Verified live at 390px: once on any page, a mobile user cannot navigate anywhere else without manually editing the URL. Given the actual user base (farm workers, often on phones, in the field), this makes the product unusable for its most field-relevant users on the device they're most likely to carry.
- **Fix**: A `Sheet`/drawer triggered by a hamburger button in a mobile header, reusing the existing nav item list.
- **Suggested command**: `/impeccable adapt`

**[P0] Every destructive action fires with zero confirmation**
- **Why it matters**: Confirmed via full-repo grep — 0 hits for `confirm(`/`AlertDialog` anywhere. Member removal, block/section/document deletion, task deletion, PO line-item deletion — every one calls `.mutate()` directly on click. An accidental tap on a shared device, or a rushed field worker, permanently deletes farm records with no recovery path.
- **Fix**: Wrap destructive triggers in a lightweight `AlertDialog` confirm (Radix family already used for `Dialog`) and add an undo toast for soft-deletable records.
- **Suggested command**: `/impeccable harden`

**[P1] Accessibility gaps: unlabeled icon buttons, no skip-link, one heading-hierarchy skip**
- **Why it matters**: Zero `aria-label` attributes anywhere in the dashboard — every Trash2/Pencil icon button announces only "button" to a screen reader, with no way to tell which row or action it targets. No skip-to-content link means a keyboard user must Tab through all 15 sidebar items on every single page before reaching page content (confirmed live: 16 Tabs from page load landed on the last nav item). The detector's live-DOM scan independently caught a heading-hierarchy skip on the Finance page (`h1` → `h3`, no `h2`), reinforcing the same pattern.
- **Fix**: Add `aria-label` to every icon-only button (the `Dialog` close button already does this correctly — propagate the pattern); add a visually-hidden-until-focused skip link at the top of `layout.tsx`; fix the Finance page's heading levels.
- **Suggested command**: `/impeccable audit`

**[P1] Restricted routes fail open into a silent infinite-loading dead end**
- **Why it matters**: Live network trace as `field_worker` hitting `/dashboard/finance` directly shows the API correctly returning 403 on every endpoint, but the page never renders an error state — it's stuck on "Loading…" forever. The sidebar correctly hides Finance from this role, but the route itself isn't guarded, so any shared link, bookmark, or history entry drops a non-technical user into what looks like a hung app rather than "you don't have access."
- **Fix**: Check `isError`/`error.response.status === 403` in the relevant queries and render a clear permission message with a link back to Overview.
- **Suggested command**: `/impeccable harden`

**[P2] Money values render as bare unformatted numbers everywhere**
- **Why it matters**: No currency utility exists anywhere in `src/lib`. Finance, Procurement, and crop-season Budget fields all show raw numbers with no currency symbol, separator, or decimal precision — genuinely ambiguous for an accountant persona reconciling real farm income and expenses.
- **Fix**: A shared `formatCurrency()` helper alongside the existing `formatRole()` in `src/lib/utils.ts`.
- **Suggested command**: `/impeccable clarify`

## Persona Red Flags

**Casey (distracted mobile user) — most severe finding of the whole review**: Completely stranded after one tap — no nav reachable past the first mobile page load, confirming the P0 above. On `/dashboard/finance` at 390px, the date-range "To" field and "Apply" button are cut off past the viewport edge.

**Jordan (confused first-timer)**: Lands on an Overview page showing four stat cards all reading "0" with no "let's set up your farm" prompt — no clear next action. Understanding the Farm→Block→Section→Plot hierarchy requires drilling 3 levels deep with only a single "← Back to farm" link at each level, no cumulative breadcrumb. The Add Animal dialog presents all 11 fields (including breeding terminology like Dam/Sire) at once with no progressive disclosure.

**Sam (accessibility-dependent, keyboard/screen-reader)**: Cannot distinguish a row's delete vs. edit control without guessing from tab order (unlabeled icon buttons). Must tab through all 15 nav items before reaching page actions on every page. Baseline keyboard experience isn't broken — focus-visible rings are implemented and visible, Escape correctly closes dialogs — it's just inefficient and under-labeled.

## Minor Observations

- A Next.js dev-mode indicator badge overlaps the sidebar's "Log out" button in every screenshot — dev-only, won't ship to production, but worth confirming it's excluded from any staging/demo build shown to stakeholders.
- Status badges use a sensible, consistent color system (success=emerald, warning=amber, destructive=red) across crop-season and animal statuses.
- The crops catalog renders as clickable pill/Badge buttons rather than the `Table` pattern every other list uses — likely fine given how few/short crop names are, but worth being a deliberate choice rather than a drift.
- The farm switcher is invisible for `system_administrator` and single-farm users with no explanation of why it disappears.
- No loading skeletons anywhere — all async states are a plain "Loading…" line; functional but flatter than a skeleton placeholder would be.
- Cold-open experience: the very first post-login view is a wall of zeros with no onboarding CTA — the best-written copy in the product (empty-state explanations) only appears incidentally once a table is already empty, rather than being front-loaded into a real first-run flow.

## Questions to Consider

1. If the real user base skews toward Jordan and Casey — non-technical, often on a phone, in a field — why does the product currently have a fully-featured desktop IA (15 nav items, 4-level drill-downs, 11-field forms) but zero mobile navigation? What would it look like if mobile were designed first?
2. The permission model gets real architectural rigor (`usePermissions()` mirrors the backend exactly). Why does action *safety* (confirmations, undo) get none of that same rigor? What would it take to treat "can this be undone" as seriously as "can this user see this"?
3. The empty-state copy is the best-written text in the product, but it's only discovered incidentally when a table happens to be empty. What if that same explanatory instinct were front-loaded into a real first-run onboarding flow?
