# ADR-0012 — Finance as documents: approval by threshold, voids by reversal, one payable registry, control accounts, payroll by task time

**Status:** Accepted (2026-09-24, Phase 8)

## Context
Phase 8 completes finance on the ledger core of ADR-0011: expenses with
approval thresholds, income, customer invoices, payments, payroll from
attendance and tasks, budgets, and statements (profit and loss, cash flow,
cost per crop and per acre). The exit criteria are that the ledger always
balances, that corrections are reversals only, and that the field worker is
kept out of all finance. The requirements do not say how approval works
below and above a threshold. They also do not say how payments reach
documents that live in other modules, how labour cost reaches crops, or
how accountants correct mistakes without editing entries.

## Decision
1. **Accountants record documents, not journal entries.** Expenses, income,
   customer invoices, supplier invoices, payments and payroll runs each post
   their own balanced entry through `Ledger::post`. A document is never
   edited after it posts. It is **voided**, which posts the mirror of its
   entry (`Ledger::reverseDocument`) and records who voided it and why. A
   document with payments must have them voided first.
2. **Control accounts belong to their documents.** Receivables, inventory,
   payables, goods received not invoiced, wages payable and deductions
   payable accept no manual lines. Their balances therefore always equal the
   open documents behind them. Manual entries are kept for what no document
   covers: opening balances, capital, loans, moving money between accounts.
   Only a manual entry can be reversed by hand. (This replaces ADR-0011's
   stop-gap of correcting a wrong supplier invoice with a manual entry: a
   supplier invoice is now cancelled, which reverses it and reopens the
   order for invoicing.)
3. **Approval by threshold, never your own.** A request (`finance.expenses.request`)
   is approved by someone else. Finance (`finance.manage`) approves within the
   farm's expense threshold; above it only `finance.approve` (the owner)
   does. The accountant's own receipts within the threshold, and anything
   the owner records, count as approved when recorded. Payroll needs
   `finance.payroll.approve` and a different person from its preparer
   (the owner excepted).
4. **One payable registry.** A payment settles exactly one document. Each
   module registers its documents with `Finance\Application\Payables`
   through the `Payable` contract: direction, the control account cleared,
   the permission needed, what is outstanding, and how to apply an amount.
   The registered documents are customer invoices (Sales), supplier invoices
   (Procurement), and expenses and payroll runs (Finance). Finance never
   depends on Sales or Procurement (docs/09). The document row is locked,
   so two payments can never pay more than is owed.
5. **Cost centres are work subjects.** A crop cycle, plot, location, animal
   or animal group, validated through Workforce's `WorkSubjects`. Stock
   issues, expenses, income, invoice lines and wages carry them. Profit per
   crop cycle or animal group is therefore a query over ledger lines.
6. **Payroll from attendance, charged by task time.** A run pays each
   worker with a daily rate for the days they checked in, plus a bonus, less
   deductions. The gross is split over the cost centres of the tasks
   verified in the period, in proportion to their worked minutes. Time on
   general work, or a worker with no tasks, is charged to no cost centre.
   Runs do not overlap and end by today.
7. **Sales owns customers and invoices.** Completed livestock sales (Phase 5)
   are billed on an invoice line, at most once while that invoice is not
   void, and charged to the animal. Invoices are drafts until issued; only
   issuing posts.
8. **Reports read the ledger.** P&L, monthly income and expenses, cash flow
   (movements on money accounts by source) and cost per crop cycle and
   animal group are read from ledger lines. The 13-week cash forecast adds
   open documents by due date. There are no report tables to keep in step.
9. **Money stays behind permissions.** Finance, invoicing and financial
   report routes need money permissions (the `finance.*` permissions other
   than `finance.payroll.view_hours`, plus `sales.invoice` and
   `reports.finance.view`), which a role holding `worker.self` can never be
   granted (a guard-rail since Phase 3); the customer list needs
   `customers.view` or `sales.invoice`. The field worker, and the crop,
   livestock and store leads, get 403 on every finance, sales and report
   route (a test). Payroll shows hours without wages to
   `finance.payroll.view_hours`.

## Consequences
- The trial balance equals the documents at all times; the tests check
  that every entry balances and that ledger rows cannot change.
- A wrong document is visible twice in the journal: its entry and its
  reversal. Nothing disappears from the books.
- Not yet built: payment approval above a threshold, credit notes and
  returns (a wrong invoice is voided and reissued), tax (VAT) lines, bank
  reconciliation, sales orders and products (Phase 12), and payroll
  statutory deductions such as PAYE and NSSF, which are entered as
  deductions for now.
