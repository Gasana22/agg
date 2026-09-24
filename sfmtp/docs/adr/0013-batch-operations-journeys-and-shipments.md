# ADR-0013 — Batch operations, journeys and shipments

**Status:** Accepted (2026-09-24, Phase 9)

## Context
Phase 9 completes traceability. The design (docs/07) names the operations
(split, merge, process, package, ship), the journey views, a
`product_journey` read model, a nightly tamper check and alerts. It leaves
open several questions:
- how quantities are accounted for across operations;
- what a recall does downstream;
- how the views stay inside module boundaries (Workforce and Sales depend
  on Traceability, never the other way round);
- how corrections show;
- where shipments live.

## Decision
1. **Consuming links take quantity.** A batch's *available* quantity is its
   quantity minus what its `split`, `merge`, `process`, `package` and `ship`
   links took, all in the batch's unit (there is no unit conversion).
   `derived` links (seed → crop lot, crop lot → harvest, animal → milk)
   take nothing.

   Operations lock the input batches (`SELECT … FOR UPDATE`, ordered by id)
   and refuse more than is left, with 422 `trace_quantity_exceeded` and the
   `available` amount. Manual `links` of a consuming type follow the same
   rule. An input that is used up is closed with a `status_changed` event.
   Closed, recalled and shipment batches cannot be used.
2. **Operations create batches.**
   - **Split** makes children of the same kind.
   - **Merge** needs one kind and one unit.
   - **Process** makes a `processed` batch and **package** a `packaged`
     one. For both, the output quantity may differ from the inputs (drying
     loses weight) and defaults to the inputs when the units agree.

   The created event carries the operation, method, inputs and package
   details, never prices.
3. **Recall cascades downstream only.** Recalling a batch marks it and
   every batch made from it `recalled`, each with a `status_changed` event
   naming the recall. Shipments are included, which is what makes the
   `recalled_shipped` alert fire. Upstream batches are untouched.

   Recall needs `trace.publish`, the permission that will also revoke QR
   codes in Phase 10. It cannot be undone, and a recalled batch cannot be
   reopened.
4. **Views read the event log.** Timeline, workers, inputs, sales and
   locations are computed over the batch's lineage: its ancestors,
   descendants, or both. The data sources are:
   - batch links;
   - event payloads;
   - Farm Structure plots (an allowed dependency);
   - a `WorkerNames` contract that Workforce implements.

   Customer names reach the sales view through the shipment's `dispatched`
   event, so Traceability never reads Sales tables. No view returns money;
   amounts stay on the Sales pages. Worker names need `workers.view`.
5. **Corrections fold into the timeline.** A correction's `corrected`
   values are merged over the original event's payload. The event is
   marked `corrected` and keeps `original_payload` and the list of
   corrections. The original row is never changed, and the chain still
   verifies.
6. **The `product_journeys` projection.** It holds one row per batch:
   upstream and downstream graphs, the timeline, a summary, and the last
   sequence seen.
   - The Recorder marks every batch it writes to.
   - After commit, one queued job per farm refreshes those batches and
     everything connected to them. A rolled-back transaction queues
     nothing.
   - `GET …/journey` serves the projection. `?fresh=true`, a depth or a
     single direction walk the graph live; the web explorer uses `fresh`.
   - `trace:refresh-journeys` rebuilds all rows. The demo seeder pauses
     the projector and projects once at the end.
7. **Tamper evidence.** `trace:verify-chain` runs nightly. On failure it
   logs at critical and emails the farm owner. `POST
   traceability/integrity/verify` (`trace.publish` or `audit.view`) runs
   the same check on demand. Audits are stored with microseconds, so the
   latest check is unambiguous.
8. **Alerts are computed on read:**
   - `chain_failed` (critical);
   - `chain_unverified` (no check in 48 h);
   - `recalled_shipped` (critical);
   - `shipment_undelivered` (dispatched over 7 days ago);
   - `missing_origin` (harvest, processed, packaged, product or shipment
     with no source);
   - `unknown_seed_source` (a crop lot with no seed or nursery).

   They also appear as a widget on the owner and manager dashboards.
9. **Shipments are Sales documents.** `shipments` (SHP-001, customer,
   optional invoice of the same customer, destination, vehicle, driver)
   and append-only `shipment_lines`, one per batch sent.
   - Dispatch creates the `shipment` batch, links it `ship` from each batch
     (taking the quantity) and records `dispatched`.
   - Delivery records `delivered` and closes the batch.
   - A failed delivery records `delivery_failed`.

   Dispatching needs `sales.fulfil`, so the store can do it. The store
   therefore sees the customer list (names and contacts, no money).
   Reading needs `sales.view`, `sales.fulfil` or `sales.invoice`.

## Consequences
- The seed → customer journey is complete for crops, with quantities that
  add up at every step. The tests cover it end to end, along with
  over-taking, merge rules, recall, corrections and a tampered event.
- Units must match. Converting (for example crates to kilograms) comes with
  the products catalogue in Phase 12.
- A processing loss is recorded, not modelled: the output quantity is what
  was weighed.
- Stock does not move with trace operations yet. Harvests enter stock and
  sales orders fulfil from it in Phase 12, which will call the same
  operations.
- The projection can lag by the queue delay (docs/07 §4 allows about 10 s).
  Public QR pages in Phase 10 will read only the projection.
