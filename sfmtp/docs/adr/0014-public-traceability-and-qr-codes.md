# ADR-0014 — Public traceability: approved snapshots, random codes, signed payloads

**Status:** Accepted (2026-09-24, Phase 10)

## Context
Phase 10 publishes batches through QR codes (docs/07 §5). A scan is
anonymous, can come from anywhere, and must never reveal:
- prices or costs;
- people;
- stock;
- exact locations.

Partners (buyers, certifiers) may also want proof that a payload came from
SFMTP. Recalls must reach the public page, and codes must survive printing
and typing.

## Decision
1. **An allow-list of public fields.** The fields are:
   - `product`, `batch_code`, `farm`;
   - `region` (district and country);
   - `origin` (plot codes);
   - `crop`, `dates`;
   - `seed_source` (lot number and supplier);
   - `inputs` (product, date and withholding);
   - `processing`, `certifications`;
   - `journey` (kinds, codes and dates).

   Each field is built from chosen values, never copied from event
   payloads. So prices, costs, users, workers, quantities and GPS cannot
   appear even if every field is approved, and a test checks this. Unknown
   field names are refused.
2. **Approvals store the reviewed payload.** Approving saves the chosen
   fields *and* the exact payload the approver previewed (append-only). The
   public page serves the latest approval's snapshot. What was reviewed is
   what is published, and later events appear only after a new approval.
   Approving and issuing need `trace.publish`; issuing and revoking also
   accept `trace.qr.manage`. Recalled batches and input lots cannot be
   published.
3. **Codes are random.** A code is 10 characters of Crockford base32,
   about 50 bits, unique across all farms. It is not the batch id and
   reveals nothing. Lookups accept lower case and read O, I and L as 0, 1
   and 1. A code is issued under an approval; a newer approval updates what
   every code of the batch shows.
4. **Revocation and recall.** A revoked code returns 200 with a
   "withdrawn" notice and only the product, batch code and farm (when
   approved). A recalled batch shows a "recalled" notice ("do not eat or
   use"). The recall also revokes its active codes. Internal reasons are
   never shown.
5. **The public endpoint.**
   - `GET /public/trace/{code}` (alias `/traceability/qr/{code}`) needs no
     sign-in.
   - It is limited to 60 requests a minute per IP, and `Cache-Control:
     no-store` keeps scans counted.
   - The code is looked up outside tenant isolation (codes are global);
     everything else runs in the code's farm context.
   - The web page `/q/{code}` renders on the server and forwards the
     visitor's IP and country, so limits and counts apply per visitor, not
     per web server.
6. **Signed payloads.** `data` is signed with Ed25519 over canonical JSON
   (keys sorted at every level). The public key is at
   `GET /public/trace/keys`. The seed comes from `SFMTP_TRACE_SIGNING_SEED`,
   or is derived from `APP_KEY` so that every installation signs without
   extra setup. Because JSON columns reorder keys on some engines, only the
   canonical form is signed; clients must not rely on key order.
7. **Scans are counts.** `trace_qr_scans` holds one row per code, day
   (farm time) and country, taken from `CF-IPCountry`; `ZZ` means unknown.
   Codes keep a running total. IPs, user agents and anything else about the
   person are never stored. The owner dashboard shows a QR scans KPI; the
   QR tab shows scans per day and per country.
8. **Labels are a small hand-written PDF**: A4 sheets of 3 × 8 labels. The
   QR is drawn once as a vector form and placed on each label, so 24 labels
   are about 17 KB. Each label shows only approved text (product, farm) and
   the code, and every label decodes. The QR matrix comes from
   `bacon/bacon-qr-code`, which also renders the SVG preview.

## Consequences
- Publishing is deliberate: new events do not reach the public until
  someone approves again. The publish tab shows when the preview differs
  from what is published.
- Key rotation means changing the seed. Old signatures stop verifying
  against the new key; a key list with history can come later if partners
  need it.
- Scan counts are approximate under rate limiting (refused scans are not
  counted) and cannot identify repeat visitors, by design.
- Printing labels on other stock sizes needs more templates (Phase 13
  exports).
