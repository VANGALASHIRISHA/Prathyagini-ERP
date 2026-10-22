# ERP ↔ Storefront Integration Specification

Audit of the Pratyangi Fashions PHP ERP (`D:\Downloads\app (3)`) for the Next.js / Node.js storefront team.

**Scope and honesty rules.** Read from the real source and `sql/database.sql` (+ migrations). Nothing in the ERP or database was modified. No production secrets are included. Every statement is tagged:

- **[SRC]** read in source code, not executed in this audit
- **[TESTED]** executed against the local ERP (`http://localhost:8000`) during this audit
- **[PROPOSAL]** does not exist; needs your approval before anything is built

Only unauthenticated/error behaviour was executed (see §9). **No success path of any endpoint was exercised in this audit**, so none is claimed "working" here beyond what is stated. Nothing was implemented.

---

## 0. Summary (read this first)

| Question | Answer |
|---|---|
| Is there a public product catalogue API? | **No.** |
| Is there a stock/price API the storefront can call? | **Partial, unusable as-is.** `product_search.php` / `product_stock.php` exist but require an ERP **staff session cookie**. There is no service authentication for them. |
| Is there an order-intake API? | **Yes, one:** `POST /api/order_create.php` with a Bearer key. It is disabled until a key is configured. |
| Does it accept prices / payment reference / customer email / address parts? | **No.** Items carry only `sku` + `quantity`. `order_amount` is accepted and **not validated** against ERP prices. |
| Can the storefront read order status back? | **No endpoint.** |
| Can the storefront cancel / return / refund through the API? | **No.** These are staff-only UI actions. |
| Rate limiting / CORS on the API? | **None implemented.** |

Bottom line: stock reservation on order intake is solid. Everything around it (catalogue, pricing, status, payments, cancellation) has to be specified and built.

---

## 1. Existing API inventory

All files are in `public/api/`. Base URL on live: the Super Admin ERP host, path `/api/…` (the docroot is `…/public`).

| # | Method + URL | Purpose | Auth | Status |
|---|---|---|---|---|
| 1 | `POST /api/order_create.php` | Create an online order and reserve stock | `Authorization: Bearer <key>` (config `api.order_ingest_key`, ≥32 chars) | **Implemented [SRC]; success path untested.** Returns 503 while the key is blank (current default) **[TESTED]** |
| 2 | `GET /api/product_search.php?q=&exact=1` | Search products by SKU/barcode/serial/name/brand/colour/size | Staff session + permission `view_stock` | Implemented [SRC]; **not usable by a server** (session login). Unauthenticated → 401 **[TESTED]** |
| 3 | `GET /api/product_stock.php?id=` | One product's stock by location/batch/serial | Staff session + `view_stock` | Same as #2. Unauthenticated → 401 **[TESTED]** |
| 4 | `GET /api/product_lookup.php?sku=&barcode=` | Product master row for the product form | Staff session + `manage_products` | Staff-only. Unauthenticated → **302 redirect to login** **[TESTED]** (not JSON) |
| 5 | `GET /api/scan_find.php` | Scan-and-find for staff screens | Staff session | Staff-only, not relevant |
| 6 | `GET /api/notification_count.php` | Staff notification badge | Staff session | Staff-only, not relevant |

Endpoints #2–#6 are internal staff-UI helpers. They must **not** be reused by the storefront (session cookies, role-dependent output, internal location/batch data).

### 1.1 `POST /api/order_create.php` (source: `public/api/order_create.php`, `app/online_fulfillment.php::online_create_and_reserve`)

Headers: `Authorization: Bearer <key>`, `Content-Type: application/json`.

Request body (all fields as read in source):

| Field | Required | Behaviour |
|---|---|---|
| `items[]` | yes | Each `{ "sku": string, "quantity": number }`. SKU must exist and be `active=1`, else 422 `Unknown SKU: …`. Quantity must be a positive **whole** number. |
| `external_order_id` | strongly recommended | Idempotency key. See §4.2. |
| `source` | no | `WEBSITE`, `MARKETPLACE` or `API`. Anything else silently becomes `API`. Use `WEBSITE`. |
| `order_no` | no | If blank, ERP generates `ONL-YYYYMMDDHHMMSS-NNN`. Unique in DB. |
| `customer_name`, `customer_mobile` | no (not validated) | Stored as free text. |
| `shipping_address` | no | One free-text blob. |
| `order_amount` | no | Stored as given, `max(0, value)`. **Not checked against ERP prices.** |
| `payment_status` | no | `PAID`, `COD`, `PENDING`, `REFUNDED`; unknown → `PENDING`. |
| `notes` | no | ≤500 chars column (`VARCHAR(500)`). |
| `location_code` / `warehouse` | conditional | Chooses the fulfilment location. If neither is sent and more than one fulfilment warehouse is enabled → 422 `Multiple fulfilment warehouses are enabled…`. Only locations with `online_fulfillment_enabled=1` are eligible. |

Example request (illustrative, SKU/IDs are placeholders):

```json
{
  "source": "WEBSITE",
  "external_order_id": "WEB-100245",
  "customer_name": "Asha Rao",
  "customer_mobile": "9999999999",
  "shipping_address": "12-3 Main Rd, Vijayawada, AP 520001",
  "order_amount": 1499.00,
  "payment_status": "PAID",
  "location_code": "MAIN-GDN",
  "items": [ { "sku": "SKU-EXAMPLE-1", "quantity": 1 } ]
}
```

Responses (from source):

| HTTP | Body | When |
|---|---|---|
| 201 | `{"ok":true,"order_id":123,"order_no":"ONL-…","status":"ALLOCATED"}` | Order created, stock reserved |
| 200 | `{"ok":true,"duplicate":true,"order":{"id":..,"order_no":"..","status":".."}}` | Same `source`+`external_order_id` already exists |
| 400 | `{"ok":false,"error":"Invalid JSON"}` | |
| 401 | `{"ok":false,"error":"Unauthorized"}` | Bad/missing bearer |
| 405 | `{"ok":false,"error":"POST only"}` | **[TESTED]** |
| 422 | `{"ok":false,"error":"<message>"}` | Unknown SKU, no items, insufficient stock, bad location, non-integer qty. Whole transaction rolled back. |
| 503 | `{"ok":false,"error":"Order API is disabled until a 32+ character key is configured."}` | **[TESTED]** (key blank locally; note 503 is returned *before* the bearer is checked) |

Known defects to fix before production **[SRC]**:
1. The 422 handler returns the raw exception message. A database error (PDO) would leak internals.
2. Idempotency is check-then-insert. The unique index `uq_online_source_external(source, external_order_id)` prevents duplicates, but a concurrent second request gets a 422 with a DB message instead of the clean `duplicate:true` response.
3. If `external_order_id` is empty there is **no idempotency at all**.
4. No rate limit, no request size limit, no IP allow-list.

---

## 2. Product catalogue

There is **no catalogue endpoint**. The storefront needs a read API (proposal §8). What the database holds today (`products` table, `sql/database.sql`):

| Storefront need | ERP column | Present? |
|---|---|---|
| Product ID / SKU | `products.id`, `sku` (UNIQUE) | Yes |
| Barcode | `product_barcode` (UNIQUE) | Yes (internal use) |
| Name | `product_name` | Yes |
| Description | none | **Missing, must add** |
| Category | `category_id` → `categories.name` (flat list, no parent) | Yes, flat |
| Brand, fabric, pattern, gender, season, design model | `brand`, `fabric`, `pattern_name`, `gender`, `season`, `design_model` | Yes |
| Colour, size | `color`, `size` (plain text) | Yes |
| **Variants** | **Not modelled.** Each size/colour combination is its **own product row with its own SKU**. There is no parent/group id. | **Missing, must add** a grouping key (or group by `design_model` + name, which is a convention, not a guarantee) |
| Images | `product_image` = relative path `uploads/products/<file>` (one image per product), stored under `public/uploads/products/` | One image only. No gallery. URL = `<ERP public host>/` + path. Only paths matching `uploads/products/[A-Za-z0-9._-]+` are exposed by the finder helper. |
| MRP | `mrp` | Yes |
| Selling price | `retail_price` | Yes |
| Offer price | `offer_price` (nullable) | Yes |
| Other tiers | `wholesale_price`, `dealer_price` | Yes (not for storefront) |
| Discount | none beyond `offer_price`; no % / coupon / date range | **Missing** |
| GST | `tax_rate`, `hsn_code` | Yes. **Unknown whether storefront prices are tax-inclusive. Needs a decision (§10).** |
| Active flag | `active` (1/0) | Yes |
| Stock tracking mode | `serial_tracking` (1 = every piece has its own barcode, 0 = batch) | Yes |
| Timestamps | `created_at`, `updated_at` | Yes (usable for incremental sync) |

Do **not** expose: `purchase_cost`, `cost_code`, `wholesale_price`, `dealer_price`, batch/supplier data, serial barcodes, location racks.

Pagination / search / filtering: only `product_search.php` (max 30 results, text query, staff session). **No pagination, no category filter, no price filter, no "changed since" filter.** All proposed (§8).

---

## 3. Inventory and pricing

### 3.1 How stock is stored **[SRC]**
- Source of truth: `inventory_balances` — one row per **product + batch + location** (`qty`, `reserved_qty`).
- Sellable quantity = `qty − reserved_qty` (floored at 0). Stock is **per product row (= per size/colour SKU), per batch, per location**.
- Every movement is in `stock_ledger` (`qty_in`, `qty_out`, `txn_type` incl. `PURCHASE, SALE, SALE_RETURN, ONLINE_DISPATCH, ONLINE_RETURN, TRANSFER_*, ADJUST_*, DAMAGE`).
- `product_serials` identifies individual garments (`AVAILABLE`, `RESERVED`, `SOLD`, `DAMAGED`, `IN_TRANSIT`…) and is **never added to quantities**; it must agree with the balance (the ERP's Integrity Report checks this).
- Storefront-available stock = sum of sellable over locations with `online_fulfillment_enabled=1` (`locations` table). The finder code does not compute exactly this for a public caller, so a purpose-built endpoint is proposed.

### 3.2 Reservation and deduction **[SRC]**
1. Order intake (`online_create_and_reserve`): inside one DB transaction, locks the product row and the candidate balance rows (`SELECT … FOR UPDATE`), allocates oldest purchased batches first, flips serials `AVAILABLE → RESERVED`, increases `reserved_qty`, inserts `online_order_allocations`. Any shortage throws and rolls everything back (order is not created).
2. Stock is **physically deducted at dispatch** (`public/online_dispatch.php`, ledger `ONLINE_DISPATCH`), not at order intake.
3. Cancel before pick releases reservations (`online_release_reservations`). If anything is already picked/packed it must be reset by staff first.
4. Return: `online_return_receive.php` restocks only `GOOD` items (`ONLINE_RETURN`), damaged items go to `DAMAGE`.

### 3.3 Concurrency with POS **[SRC]**
POS reads `qty − reserved_qty` and locks the balance/serial rows inside its transaction, so a piece reserved by an online order is not sellable at the counter, and two buyers cannot reserve the same serial (the second gets "reserved by another order. Please retry"). **This was read, not load-tested.** A concurrency test is listed in §9.

### 3.4 Pricing **[SRC]**
- POS price comes from `products` by customer tier: `retail_price`, `wholesale_price`, `dealer_price`, or `offer_price` for the `offer` tier (falls back if null). `discount` at POS is per invoice with role caps from `settings` (`cashier_max_discount_pct`, `manager_max_discount_pct`).
- **There is no online price rule.** Online order items store no price at all (`online_order_items` has only `product_id`, `quantity`).
- Recommendation: price the storefront from `offer_price` if set, otherwise `retail_price`; confirm with the business (§10).

### 3.5 How changes reach the storefront
Today: **they don't**. No webhook, no change feed, no cache invalidation. Proposed: poll `GET /products?updated_since=` and a stock endpoint (§8), plus an optional signed webhook.

---

## 4. Online orders

### 4.1 What the ERP stores (`online_orders`, `online_order_items`, `online_order_allocations`)
Order: `order_no` (unique), `source`, `external_order_id`, `customer_name`, `customer_mobile`, `shipping_address` (one text field), `order_amount`, `payment_status` (`PAID/COD/PENDING/REFUNDED`), `status`, `courier`, `awb`, `package_weight`, `notes`, timestamps and staff ids.

**Lifecycle:** `NEW → ALLOCATED → PICKING → PICKED → PACKING → PACKED → DISPATCHED → DELIVERED → RETURN_IN_TRANSIT → RETURNED`, or `CANCELLED`.
- Intake sets `ALLOCATED`.
- Staff screens: `online_orders.php` (cancel, reset pick), `online_pick.php`, `online_pack.php`, `online_dispatch.php` (courier + AWB), `online_after_dispatch.php` (mark delivered / start return), `online_return_receive.php`.
- Staff are notified of new API orders (`ONLINE_ORDER_NEW`, `ONLINE_ORDER_PICK` events).

### 4.2 Idempotency
Unique index on `(source, external_order_id)`. Resend with the same pair → HTTP 200 `duplicate:true` with the existing order. The storefront **must always send** a stable `external_order_id` (its own order id) and retry safely on timeouts.

### 4.3 Gaps (documented, not assumed)

| Requirement | Status |
|---|---|
| Validated prices per line | **Missing.** Only `order_amount` (unchecked). |
| Per-line price, tax, discount, shipping fee, totals | **Missing columns.** |
| Customer id / email | **Missing.** Only name + mobile text. `customers` table is the ERP's billing customer list (name, mobile, GSTIN, tier, credit limit). It is not storefront accounts. |
| Structured address (line1, city, state, pincode, state code for GST) | **Missing.** One text blob. |
| Payment reference / gateway transaction id / paid amount / paid-at | **Missing.** Only `payment_status`. |
| Refunds | **Nothing sets `REFUNDED`** except accepting it on intake. No refund record, amount or reason. |
| Order status read-back for the storefront | **Missing.** |
| Customer cancellation | **Missing via API**; staff can cancel in the UI only before pick. |
| Returns initiated by customer | **Missing via API**; ERP has a staff return flow after dispatch. |
| Invoice (GST) for online orders | Not generated by this flow (stock moves via ledger `ONLINE_DISPATCH`; no `sales` row). **Needs a business/tax decision.** |

---

## 5. Data ownership and synchronisation (recommendation)

| Data | Owner | Notes |
|---|---|---|
| Products, categories, SKUs | **ERP** | Storefront reads. Storefront-only content (description, SEO, extra images, size chart) lives in the storefront DB keyed by `sku`. |
| Prices and discounts | **ERP** | Storefront never sets prices; ERP re-validates at order time (proposal). |
| Inventory | **ERP only** | Storefront never writes stock. Reservation happens only inside ERP order intake. |
| Customer accounts, passwords, Google identity | **Storefront** | Never share with ERP staff `users`. |
| Delivery addresses (address book) | **Storefront** | A snapshot is sent with each order. |
| Online orders | **ERP is the fulfilment master; storefront is the customer-facing master.** One shared key: `external_order_id`. Storefront creates, ERP changes fulfilment status, storefront only reads status. |
| Payment records | **Storefront / payment gateway** (ERP keeps `payment_status` + reference for staff view). |
| Dispatch (courier, AWB), returns, refund decision | **ERP** for physical steps; **storefront/gateway** executes the money refund. |

Sync design (all proposed): storefront → ERP is request/response with idempotent order creation; ERP → storefront is polling (`updated_since`) with optional signed webhook for order status. The storefront must never connect to the ERP database directly and must never run its own stock arithmetic.

---

## 6. Authentication and security

Current state **[SRC]**:
- Service-to-service auth exists **only** for `order_create.php` (single shared Bearer key, constant-time compare). One key, no rotation, no scopes, no per-client identity.
- Staff endpoints use PHP sessions + role permissions (`has_permission`, `location_scope_sql`). Not suitable for a server.
- CSRF applies to staff forms; not relevant to the Bearer API.
- No CORS headers anywhere (browser calls from the storefront domain would be blocked; and should not be made from the browser anyway).
- The ERP `.htaccess` sends strict CSP, `X-Frame-Options`, `nosniff`. No rate limiting on the API (the login has attempt limiting; the API does not).
- Audit: order creation writes `audit()` (`ONLINE_ORDER_API_CREATE`) with source but not the client IP/key id.

Requirements for production:
1. Storefront **backend** (Node) calls the ERP server-to-server only; never from the browser. HTTPS only.
2. Generate a random 40+ char key; store it only in the ERP `config`/`.env` and the Node secrets store. Rotate if ever shared (the key must not appear in docs or chat).
3. Proposed: separate read key and write key, key id logged on every call, IP allow-list, per-key rate limit, request body cap, generic 422 messages.
4. Never reuse staff logins for customers. Customer PII (name, mobile, address) is stored in `online_orders`; restrict DB access and backups accordingly.

---

## 7. Missing endpoints and functionality (checklist)

1. Catalogue list / detail (public, key-auth) — **missing**
2. Storefront-safe stock availability (per SKU, online-eligible locations) — **missing**
3. Price + offer price served for storefront — **missing**
4. Product description, variant grouping, image gallery, discount rules — **missing in schema**
5. Order status read — **missing**
6. Customer cancel request — **missing**
7. Return request / RTO sync, refund record — **missing**
8. Order line prices, tax, shipping fee, payment reference, structured address, email — **missing in schema**
9. Change notification (webhook or feed) — **missing**
10. Service auth with scopes/rotation/rate limit — **missing**
11. Server-side price validation on order intake — **missing**

---

## 8. Proposed endpoints — **PROPOSALS, NOT IMPLEMENTED, awaiting approval**

Base `/api/v1/store/…`, header `Authorization: Bearer <read or write key>`, JSON.

| Proposed | Purpose | Notes |
|---|---|---|
| `GET /products?page=&per_page=&category=&q=&min_price=&max_price=&updated_since=` | Paginated active products | Only active, only price/stock-safe fields |
| `GET /products/{sku}` | Product detail | Variants grouped once a group key exists |
| `GET /stock?skus=A,B,C` | `[{sku, available}]` from online-eligible locations | Never exposes batches/serials/racks |
| `POST /orders` | Extends today's order_create | Adds `lines[{sku, qty, unit_price}]`, `shipping_fee`, `payment{status, reference, amount}`, structured `shipping_address`, `customer_email`; server **re-prices** and rejects on mismatch (409 with current prices) |
| `GET /orders/{external_order_id}` | Status, courier, AWB, timestamps | Read-only |
| `POST /orders/{external_order_id}/cancel` | Cancel if still pre-pick | 409 if too late |
| `POST /orders/{external_order_id}/return-request` | Customer return request | Staff approve in ERP |
| `POST /webhooks` (ERP → storefront, HMAC signed) | Order status / stock-low events | Optional, polling works first |

Proposed schema additions (additive only): `products.description`, `products.variant_group`, extra images table, `online_order_items.unit_price/tax_amount`, `online_orders.customer_email/payment_reference/paid_amount/shipping_fee/address_json`, `api_clients` table (key hash, scope, last_used).

---

## 9. Testing

### 9.1 Executed in this audit (local, read-only) **[TESTED]**

| Request | Result |
|---|---|
| `GET /api/product_search.php?q=saree` (no session) | 401 `{"ok":false,"error":"Your session has expired…","code":"auth"}` |
| `GET /api/product_stock.php?id=1` (no session) | 401 same body |
| `GET /api/product_lookup.php?sku=X` (no session) | 302 redirect (not JSON) |
| `GET /api/order_create.php` | 405 `POST only` |
| `POST /api/order_create.php` `{}` (key blank) | 503 `Order API is disabled…` |
| `POST /api/order_create.php` with `Bearer wrong` (key blank) | 503 (key check comes after the "disabled" check) |

### 9.2 To run against a **test database copy** once a key is set (not yet run)

1. Valid order, 1 SKU in stock → 201, `status:ALLOCATED`, `reserved_qty` +1, serial `RESERVED`.
2. Same `external_order_id` again → 200 `duplicate:true`, no second reservation.
3. Two simultaneous requests, same `external_order_id` → exactly one order; check the loser's response (defect #2 in §1.1).
4. Unknown SKU → 422, nothing created. Inactive SKU → 422.
5. Quantity > sellable → 422, no partial reservation (verify `reserved_qty` unchanged).
6. Two simultaneous orders for the last piece → one 201, one 422.
7. Wrong / missing bearer → 401. Invalid JSON → 400.
8. Online order reserved, then a POS sale of the same SKU → POS cannot sell the reserved piece.
9. Cancel in ERP before pick → `reserved_qty` back to prior, serial `AVAILABLE`.
10. Dispatch → `stock_ledger` has one `ONLINE_DISPATCH` row, balance reduced once; run ERP **Inventory Integrity Report** afterwards, expect all PASS.
11. Send `order_amount` different from real prices → currently accepted (confirms gap §4.3).

Use a restored copy of the DB, never the live one; use the ERP's Backup & Restore first.

---

## 10. Dependencies and decisions needed

1. **Pricing rule:** `offer_price` else `retail_price`? Tax-inclusive or extra GST? Any online-only discounts/coupons?
2. **Variants:** accept adding a `variant_group` (product family) key; who maintains it for existing SKUs?
3. **Order-time price validation:** reject on mismatch (recommended) or trust storefront?
4. **Fulfilment location(s)** for online stock: which locations get `online_fulfillment_enabled=1`; one warehouse or several?
5. **Stock visibility:** show exact count or only In stock / Low / Out; reservation hold time for unpaid orders (today reservations never expire, so unpaid `PENDING` orders would hold stock forever).
6. **Payment:** gateway choice; what ERP must store (reference, amount); who issues refunds and how ERP is told.
7. **GST invoice for online sales:** does ERP need to generate one at dispatch? (not built today)
8. **Customer cancellation / returns policy** and the window.
9. **Sync method:** polling interval vs webhook.
10. **Images:** serve from the ERP host, or upload to storefront/CDN; one image or several.
11. **Hosting:** the ERP is on shared cPanel hosting; confirm it can take storefront traffic, or add caching on the Node side.
12. **API key handover:** a new key must be generated (never reuse any key shared in chat) and delivered through a secrets channel.

---

## Appendix: source and table references

- API: `public/api/order_create.php`, `product_search.php`, `product_stock.php`, `product_lookup.php`
- Logic: `app/online_fulfillment.php`, `app/product_finder.php`, `app/bootstrap.php` (`online_order_api_key`, `has_permission`, `location_scope_sql`)
- Staff order screens: `public/online_orders.php`, `online_pick.php`, `online_pack.php`, `online_dispatch.php`, `online_after_dispatch.php`, `online_return_receive.php`, `online_order_new.php`
- Product management: `public/product_form.php`, `barcode_create.php`
- Tables: `products`, `categories`, `batches`, `inventory_balances`, `stock_ledger`, `product_serials`, `locations`, `online_orders`, `online_order_items`, `online_order_allocations`, `customers`, `settings`, `workflow_events`
- Schema: `sql/database.sql`, `sql/migration_v1_*.sql`
