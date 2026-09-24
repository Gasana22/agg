# ADR-0011 — Stock valuation and the ledger core: average cost per lot, row locks, entries corrected through their documents

**Status:** Accepted (2026-09-24, Phase 7)

## Context
Phase 7 brings in stock, purchasing and the core of the double-entry
ledger. The requirements ask for an append-only stock ledger, stock that
never goes negative when several people issue at once, input lots that stay
traceable, approval for adjustments, and books that always balance. They do
not say how stock is valued, how concurrent issues are serialised, how
purchases reach the books before the invoice arrives, or how mistakes are
corrected. Phase 8 builds the rest of finance on the same ledger, so these
choices have to hold for it too.

## Decision
1. **Quantities and money are exact.** Quantities are stored with three
   decimals and handled as integer thousandths (`Qty`); money has two
   decimals and is handled as integer cents (`Money`). No floats reach the
   database.
2. **Stock ledger and balances.** Every change is a row in `stock_movements`
   (append-only, signed quantity and value, the balance after it, its
   source document and, for issues, its subject). `stock_balances` holds
   the running quantity and value per item, store and lot. A database check
   keeps quantity ≥ 0 unless the row was created while the farm allowed
   negative stock for an untracked item.
3. **Valuation: weighted average per balance row.** A receipt adds its
   value; an issue takes `value × qty / on hand`, and the last unit takes
   exactly what is left, so the value reaches zero with the quantity.
   Because lot-tracked items keep one row per lot, a lot keeps its own
   purchase price. Untracked items are averaged per store. FIFO layers
   were not needed for the requirements, and the average is what small
   farms understand.
4. **Concurrency: lock the balance rows, in a fixed order.** An issue locks
   the item's rows in the store (`SELECT … FOR UPDATE`, ordered by id),
   checks the quantity, picks lots by first expiry, then writes. A missing
   row is inserted only after the lock query found nothing. Inserting first
   (`INSERT IGNORE`) deadlocks on MySQL. A deadlock is retried up to three
   times. The concurrency test forks ten processes that issue from the
   same balance at once, on both engines, and fails if locking is removed.
5. **Lots are trace batches.** Every lot (from a delivery or a stock-in) is
   an `input_lot` batch; its creation event names the supplier, order and
   supplier lot number, never the price. Each issue adds an `issued` event
   with the subject and quantity, so the forward journey of an input
   starts here.
6. **Accounts and postings.** The system chart (created on first use) has
   Inventory 1300, Goods received not invoiced 2100, Accounts payable 2000,
   Opening balances 3100, Inputs used 5000, Stock adjustments 5100 and
   Price variance 5200. A delivery posts Dr Inventory / Cr GRNI at the
   order price. The supplier's invoice (at most what was received and not
   yet invoiced) posts Dr GRNI at the order price, the difference to price
   variance, Cr Payables. Issues post Dr Inputs used / Cr Inventory at
   average cost, with the subject as a cost centre. Stock-in posts against
   Opening balances. A count posts the value of its difference against
   Stock adjustments. The ledger entry is written first and the movement
   points to it, all in one transaction.
7. **Books always balance.** `Ledger::post` refuses an unbalanced entry.
   On PostgreSQL a deferred constraint trigger also refuses it at commit.
   Entries and lines are append-only (triggers), and numbers come from a
   row-locked counter per farm.
8. **Corrections go through the document that posted.** Only manual
   journal entries can be reversed (a mirror entry, once). An entry posted
   by a stock movement, a delivery or an invoice is corrected through
   stock (a count, a return) or purchasing. Otherwise the Inventory
   account would drift from the value of the stock on the shelves.
9. **Counts apply their difference.** A count records what the book said
   when it was taken. On approval, `counted − expected` is applied to the
   stock as it is now, so goods received or issued in between are kept. If
   that would take stock below zero, the count is refused as outdated. A
   count needs someone other than the counter (the owner excepted), and
   the owner above the farm's `stock_adjustment_pct`.
10. **Who sees money.** Stock values need `inventory.values.view`; prices
    on purchase documents need `procurement.orders.manage` or
    `finance.values.view`. The store manager receives deliveries without
    seeing prices.

## Consequences
- Stock value and the Inventory account agree by construction. The demo
  farm and the tests check this.
- Parallel issues serialise per item and store, not per farm. That is
  enough for the expected load (docs/10, Phase 15 load test).
- Payments, customer invoices, budgets and the reports of Phase 8 post to
  the same ledger through `Ledger::post` with source type `manual` or their
  own document type.
- Returns to suppliers and supplier credit notes are not built yet. Until
  they are, a wrong delivery is corrected with a count, and a wrong invoice
  with a manual entry.
