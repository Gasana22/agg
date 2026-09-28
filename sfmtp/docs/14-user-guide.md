# 14 — User guide

A short guide for the people who use SFMTP. The menus show only what your
role allows, so you may not see every item below.

## Everyone

- **Signing in.** Use your email and password at the web address you were
  given, or in the SFMTP phone app.
  - Owners, accountants, platform staff (and everyone on farms that ask for
    it) set up a **second step**: scan the code with an authenticator app
    (Google Authenticator, Microsoft Authenticator, Authy), then keep the
    10 recovery codes somewhere safe.
- **Forgotten password.** Use "Forgot password" on the sign-in page. After
  5 wrong passwords the account waits 15 minutes.
- **Several farms or portals.** Switch with the selector at the top left.
- **Notifications.**
  - The bell at the top right sets which notices also come by email or
    SMS. SMS needs a phone number on your account.
  - The inbox is always on the dashboard and in the phone app.
- **Help.** Owners open a support ticket from the Support page. Support can
  only look at your farm if the owner grants access, for a few hours,
  read-only.

## Farm owner

1. **Create the farm** (name, district, size). Platform staff approve it;
   meanwhile you can set it up.
2. **Farm map.** Draw blocks, sections and plots, and add locations (stores,
   animal houses, water points). Plot areas are measured for you.
3. **Members.**
   - Invite people by email with one or more roles: manager, agronomist,
     livestock, store, accountant, field worker.
   - **Roles & permissions** adjusts what each role may do, or copies one
     into a new role.
4. **Farm settings.** Currency, time zone, approval thresholds, "MFA for
   all", and **online payments**: turn them on and give your Flutterwave
   subaccount so customers can pay invoices by mobile money or card.
5. **Subscription.**
   - Shows your plan, its limits and usage.
   - **Pay online** pays the plan by mobile money or card; the page
     confirms when the payment is through.
6. **Dashboard.** It shows the whole farm: money, work, stock, health,
   weather and alerts. Click a number to see how it is counted.

## Manager

- **Tasks.** Plan work (what, where, who, when). Each worker gets a task on
  their phone. **Verify** submitted work, or reject it with a reason.
- **Workers.**
  - Profiles, attendance (check-ins with location), and leave requests to
    approve.
  - Payroll is prepared from attendance and verified tasks.
- **My day** lists what needs you today.

## Field worker (phone app)

1. **Check in** when you arrive. Your location is recorded.
2. Open a **task**: **Start**, add notes or photos, then **Submit** with
   the quantity done.
3. **No signal?** Keep working. Everything is saved on the phone and sent
   when the network returns. A small badge shows what is waiting.
4. **Check out** when you leave. Ask for leave from the app.

## Agronomist

- **Crops.**
  - Seasons, crop plans (approved by the owner), and cycles from nursery to
    harvest.
  - Record field work with the inputs used. Withholding periods are
    checked for you.
  - Record pest and disease scouting, and harvests.
- The **crop health map** shows each cycle's score and why it lost points.
- The phone app does the same in the field, offline.

## Livestock manager

- **Livestock.** Animals and groups, breeding and births, feeding, health
  and vaccinations, weights, milk and eggs, movements, deaths and sales.
- **Withdrawal periods** block milk and meat from treated animals until
  they end.
- The **animal health** list shows who needs attention first.

## Store manager

- **Inventory.** Items and lots, receiving, issuing to work, transfers,
  counts (approved by the owner), and low-stock and expiry alerts.
- **Purchasing.** Purchase requests, orders to suppliers, deliveries into
  stock, and supplier invoices matched to what arrived.

## Accountant

- **Finance.**
  - Expenses (large ones need approval), income, customer invoices, and
    payments against any document.
  - Payroll, budgets and the ledger.
- **Reports.**
  - Profit and loss, cash flow with a forecast, cost per crop and per
    animal group, and aged balances.
  - The **journal** report exports every ledger line for your accounting
    package (CSV or Excel).
- **Mistakes** are reversed, never deleted: void the document and enter it
  again.

## Traceability and QR codes

- **Traceability** shows every batch's journey: from seed and inputs,
  through work and processing, to the customer. Split, merge, process and
  package batches here.
- **Recall** a batch to see every customer who received it.
- **QR codes.**
  - Publish a batch with the fields you approve, then print labels (PDF,
    several per sheet).
  - Anyone scanning the code sees only those fields.
  - Revoking a code shows a recall notice instead.

## Sales

- **Sales.** Products and prices, and orders from customers (approve, then
  invoice).
- **Shipments.** Dispatch and delivery; the batch's journey ends at the
  customer.
- **Portal access** invites your suppliers and customers to their portals.

## Suppliers and customers (portals)

- **Suppliers.** See your orders from each farm. Accept or answer them,
  announce dispatches, send invoices, and follow payment.
- **Customers.**
  - Browse the farm's products, order, and follow deliveries (confirm when
    received).
  - See invoices, and **Pay now** online where the farm offers it.
  - Check the traceability of what you bought.
- **One sign-in** covers every farm you deal with.

## Reports and exports

- **Reports** has:
  - standard reports with a preview;
  - exports to CSV, Excel or PDF, built in the background and kept
    24 hours for you only;
  - the **metrics** catalogue: what every dashboard number means, and its
    trend.
- The farm map's **Activity** layer shows where work happened (counts, not
  people).

## Sensors

On the **Farm map** page, the sensors card registers devices such as tank
levels, soil probes and weather stations.
- Each gets a token, shown once, for whoever installs it.
- Latest readings appear on the card.
- "Rotate token" cuts off a lost device.

## Platform staff

The admin portal covers:
- farms (approve or suspend);
- plans and subscriptions (record bank payments);
- catalogues, accounts and staff roles;
- **Integrations** (SMS, email, maps, weather, push, payments, each with a
  **Test** button and health);
- **System**: health, failed jobs, backups;
- support tickets.
