# ERP Integration Handover for the Storefront Team

Prepared 2026-10-23 from the repository at commit `8c52da8` (branch `main`, in step with `origin/main`) and from read-only requests to the live ERP made on the same day. It contains no keys, passwords or customer data. "Code today" means what the repo does now. "Decision needed" means the ERP has no rule yet; every such item is listed in section 8.

## 1. What changed recently

The repo has two commits, both on 2026-10-22.
- `f9118e8` (13:08): the whole ERP, committed for the first time.
- `8c52da8` (15:17): `.htaccess` now refuses `.git`, zip, key and backup downloads; a clearer message when the licence server's reply is not valid; a test-then-push script. Nothing else.

Work done in the last two weeks, all inside `f9118e8`:
- **Purchase module:** vendors, vendor bills, approvals, goods receipt, payments, purchase and GST reports. A confirmed goods receipt is the new way stock gets added. Online stock numbers now also rise when an approved bill is received.
- **Barcode Creation** shows the cost price to the Super Admin and managers. Labels carry a QR code. Excel exports, backup and restore, an inventory integrity report, a pre-flight check, an auditor panel, and a layout that works on phones.
- Licence: an expired or unactivated ERP now refuses requests with plain text.

**The next commit (not yet pushed when this was written) changes `public/api/order_create.php` only in its error handling, as described in section 2, and adds a search box to Online Orders. The response shapes did not change.** No change here can break an outside client. One caution: `public/api/product_lookup.php` now includes the purchase cost for Super Admin and manager sessions. It is a staff-only endpoint (login cookie) and must never be called by the storefront.

**In progress, not in this repo:** a read-only order-status endpoint (`public/api/order_status.php`, written by another team, reviewed but not merged, with open issues); a product catalogue endpoint; a database index on `online_orders(updated_at, id)`. None of these exist live.

**Deployed commit:** the repo does not record it. The live ERP was updated by uploading zip files, and there is no version file or tag. We cannot say which commit is live.

## 2. What the live ERP exposes (checked 2026-10-23)

Base URL: `https://superadmin.pratyangi.com/api/…`. The other four ERP hostnames serve the same files, and the storefront should use only this one.

| Endpoint | In repo | Auth | Live answer today |
|---|---|---|---|
| `POST /api/order_create.php` | Yes | `Authorization: Bearer <key>` | **503 "Order API is disabled until a 32+ character key is configured."** The live ERP has no order key set, so orders cannot be sent yet. |
| `GET /api/product_search.php`, `product_stock.php`, `scan_find.php` | Yes | Staff login session cookie | 401 JSON without a session. Not usable by a server. |
| `GET /api/product_lookup.php`, `notification_count.php` | Yes | Staff login session | 302 to the login page. Not usable. |
| `GET /api/order_status.php` | **No** | – | 500 (HTML error page), so not deployed. |
| Catalogue (products with price and stock) | **No** | – | Does not exist. |

**Reading products with price and online stock:** no endpoint an outside system can use. **Creating an online order:** `order_create.php` only. **Reading an order's status:** none.

**`POST /api/order_create.php`**
- Request fields (JSON): `items` (array of `{sku, quantity}`, required, whole pieces only); `external_order_id` (the storefront's own id); `source` (`WEBSITE`; `MARKETPLACE` or `API` also accepted, anything else becomes `API`); `order_no` (optional, otherwise generated like `ONL-20261023104500-123`); `customer_name`, `customer_mobile`; `shipping_address` (one text field); `order_amount`; `payment_status`; `notes`; `location_code` or `warehouse`.
- **201** `{ok:true, order_id, order_no, status:"ALLOCATED"}`. **200** `{ok:true, duplicate:true, order:{id, order_no, status}}` when the same `source` and `external_order_id` exist.
- **Errors:** 400 `Invalid JSON`; 401 `Unauthorized`; 405 `POST only`; 503 key not configured. 422 `{ok:false, error}` with messages such as `Unknown SKU: …`, `At least one item is required.`, `Insufficient sellable stock for <SKU> · <name>. Required N piece(s).`, `Multiple fulfilment warehouses are enabled. Send warehouse or location_code.`, `Unknown or disabled fulfilment location.`, `A garment was reserved by another order. Please retry.`
- **Fixed in the repo, not yet live:** a database fault is now logged and the caller gets `500 {"error":"The order could not be saved. Please retry."}` instead of raw database text; two identical requests at the same moment now give one `201` and one `200 duplicate`. Business messages (unknown SKU, no stock) are unchanged.
- **Still true:** an order sent without `external_order_id` has no duplicate protection.
- Because of the licence check, an unactivated ERP answers **402 plain text** and a suspended one **403 plain text**, not JSON.

## 3. Business rules

| Topic | Code today | Decision needed |
|---|---|---|
| Online price | **No online price column.** Products hold `mrp`, `retail_price`, `wholesale_price`, `dealer_price`, `offer_price` (may be empty). The counter sells at `retail_price`, or `offer_price` for the offer tier when it is set. | Which column is the online price? Suggested: `offer_price` if set, else `retail_price`. |
| Tax | Each product has `tax_rate`. At the counter prices are **before tax and tax is added on top**: tax = taxable value × rate ÷ 100, rounded to 2 decimals per line, on the amount after discount. Same state as the company means CGST + SGST, otherwise IGST. | Are storefront prices tax-inclusive? The order API does no tax and does not check `order_amount` against ERP prices. |
| Online stock | Only locations with `online_fulfillment_enabled = 1` count. Sellable = on hand minus reserved. | Which warehouse is online stock? If more than one is enabled, orders must send `warehouse` or `location_code`. |
| Hide a product without deactivating it | **Not possible.** The only flag is `active` (0 hides it everywhere, and orders for it fail with `Unknown SKU`). | Add a separate "show online" flag. |
| SKU and variants | `sku` is unique. Every size and colour is its **own SKU and its own row**; there is no parent product or grouping field. The storefront must send the exact SKU. | A grouping key if the storefront shows one product with many sizes. |
| Images | One file per product. The path is stored as `uploads/products/<file>`, served from the ERP host at `https://<erp-host>/uploads/products/<file>`. | Serve images from the ERP or copy them to the storefront or a CDN. Folder listing is blocked (403). |

## 4. Orders

- **Idempotency key:** the pair `source` + `external_order_id` (unique in the database). A repeat returns 200 with `duplicate:true` and the existing order.
- **PAID, COD, PENDING:** `payment_status` accepts `PAID`, `COD`, `PENDING`, `REFUNDED`; anything else becomes `PENDING`. **The ERP does not treat them differently**: stock is reserved the same way. There is no payment reference or paid-amount field, and nothing checks the amount, so a paid order cannot be recorded safely yet.
- **Stock:** at creation, the exact pieces are reserved in one transaction with row locks (oldest batch first). If any line lacks stock, nothing is created. Unpaid orders keep stock reserved **with no expiry**. Stock is taken off only at dispatch.
- **Cancel:** staff can cancel before anything is picked, which releases the reservation. After picking, it must be reset first. After dispatch, use the delivery or return flow. **The storefront has no cancel or return API.**
- **Return:** staff mark a dispatched order as returned and receive it. Good items go back to stock; damaged ones are recorded and kept out.

## 5. After the order exists

- **Staff flow:** pick queue, scan the rack and then each garment (wrong item, wrong rack, already picked and reserved-elsewhere are refused). Pack verification needs every piece picked. Dispatch records `courier`, `awb`, `package_weight`, `dispatched_by` and `dispatched_at`. There is **no tracking-URL field**. Delivered is set by hand on the Delivery / RTO screen.
- **Status values:** `NEW`, `ALLOCATED`, `PICKING`, `PICKED`, `PACKING`, `PACKED`, `DISPATCHED`, `DELIVERED`, `RETURN_IN_TRANSIT`, `RETURNED`, `CANCELLED`.
- **How the storefront learns the status:** today it cannot. The planned way is to read it from the new status endpoint (section 1).
- **Invoice and GST record:** **a website order creates no invoice and no sales record**, not at creation and not at dispatch. Only the stock ledger changes. GST invoicing for online orders is not built.

## 6. Operations

- **URLs:** live `https://superadmin.pratyangi.com`. There is **no staging ERP**; a development copy runs only on the team lead's computer.
- **API keys:** a key is a text value in the ERP's private config file, set by hand by the administrator, and shared once over a secure channel. The only name that exists is `api.order_ingest_key`. There is no key list, rotation tool or expiry; rotating means editing the file and telling the storefront at the same time. The key of the planned status endpoint will be a separate `api.order_status_key`.
- **Licence:** the ERP must be activated. Without activation, every request returns 402; when expired it becomes read-only, so creating an order fails with 402 but reading continues. `order_create.php` also needs the "online orders" feature in the plan.
- **Authorization header:** **not verified on the live host.** The repo now has a rule that passes it to PHP and the code also accepts the `REDIRECT_` form, but neither is deployed yet, and many shared hosts drop the header. The 503 above shows no key is set, so the header has never been exercised live. Test it before launch.
- **Software:** PHP 8.2 and MySQL or MariaDB are the targets. The live versions are not recorded in the repo; the ERP's Pre-flight Check page shows them. The application time zone defaults to `Asia/Kolkata`; the database session time zone is not set, so `updated_at` uses the server's own.
- **Rate limits:** none on the API. Only staff logins are limited.

## 7. Change policy (proposed)

- **Version:** the API is **v1.0** as described here. Paths are not versioned yet. A breaking change gets a new file name or a `/v2` path, and v1 keeps working for at least 90 days after v2 launches.
- **Notice:** additive changes (a new optional field or endpoint) are announced with the release. Anything that could break a client (removed or renamed fields, new required fields, changed status values or error messages, a new key) is announced **at least 14 days before** by email to the storefront lead, with a test date.
- **Where:** this file is the contract and is edited with each change, with a changelog line. The ERP lead owns it.

## 8. Open questions before launch

| # | Question | Suggested owner |
|---|---|---|
| 1 | Online price column and whether prices include tax | Business owner and accountant |
| 2 | Which warehouse is online stock | ERP lead |
| 3 | Add a "show online" flag and a variant grouping key | ERP lead |
| 4 | Set the live order API key, rotate it, and confirm the Authorization header reaches PHP | ERP lead and host admin |
| 5 | Payment: add payment reference and paid amount, check the amount, and release stock for unpaid orders after a time | ERP lead and storefront lead |
| 6 | Is `GET /api/order_status.php` final; deploy it, with the review fixes | ERP lead and the other team |
| 7 | GST invoice for online orders (at dispatch?) | Accountant |
| 8 | Cancel and return requests from the storefront | Business owner |
| 9 | Create a staging ERP with test keys | ERP lead |
| 10 | Record live PHP, database and time zone settings; add a version marker to each deploy | ERP lead |
| 11 | Address and customer email fields, tracking URL | Storefront lead |
| 12 | Rate limit for the API | ERP lead |
