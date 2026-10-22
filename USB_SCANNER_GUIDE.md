# USB Barcode Scanner Guide

## Short version

Every scan-relevant screen in this ERP already supports a USB handheld
scanner (RETSOL LS 450A and any similar "keyboard wedge" laser/CCD scanner)
out of the box. **Nothing had to be built for this** — it was inspected and
tested against the running app, not assumed. This file documents how it
works, where it's wired in, and how to set the scanner up.

## Why it works with no special driver or app setting

A USB handheld barcode scanner like the RETSOL LS 450A is a **HID keyboard
wedge** device: to the computer, it is indistinguishable from someone typing
very fast on a keyboard, followed by pressing Enter. It needs no driver on
Windows and no browser extension. Because of that:

- Every scan-capable input in this app is a plain `<input>` with the
  `data-scanner` attribute. [public/assets/js/app.js](public/assets/js/app.js)
  auto-focuses the first such input when a page loads
  (`focusScanner()`), so the cursor is already waiting for the scanner.
- Pressing Enter inside a text input that is the only (or first) input in an
  HTML `<form>` submits that form natively — this is standard browser
  behaviour, not custom JavaScript. So "scan → Enter → submit, no mouse
  click" already works everywhere these inputs appear.
- An optional **Camera** button is attached next to `data-scanner` inputs on
  a few screens (pick, pack, return-receive, stock count) for phone/webcam
  use — it is a separate button the user must click, never automatic, and
  never required. USB scanning needs it on no screen.

## Barcode format compatibility

All barcodes this app generates or accepts are **Code128** — printable ASCII,
3–80 characters, validated by `/^[\x21-\x7E]{3,80}$/`
([app/barcode.php](app/barcode.php), reused in `barcode_create.php`,
`product_form.php`, `locations.php`). Code128 is one of the most common 1D
symbologies and is supported by essentially every handheld laser/CCD
scanner, RETSOL LS 450A included, with no reconfiguration. No changes were
needed or made to barcode generation.

**The one setting worth checking on the scanner itself:** most scanners ship
configured to send a trailing **Enter (CR)** after each scan — this is what
triggers the auto-submit behaviour above. If yours doesn't, scan the
"Enable Suffix: CR/Enter" code from the scanner's own configuration manual
(every USB HID scanner, RETSOL included, ships one). Everything else
(prefix characters, keyboard layout, etc.) can stay at factory default.

## Where scanning is already wired in, by workflow

| Workflow | Screen | What happens on scan |
|---|---|---|
| Product search / locate in godown | [public/scan_find.php](public/scan_find.php) | Resolves a unique piece, batch, product barcode, SKU, or rack/bin label to full detail: product name, SKU, size, colour, barcode, price, stock status, and every location holding it with warehouse/floor/section/rack/bin path, physical/reserved/sellable qty. Unknown code → "Product not found: `<code>`", nothing is created. |
| All-branch stock list | [public/stock.php](public/stock.php) | Same lookup, filtered into the stock table. |
| Quick product/SKU finder | [public/product_finder.php](public/product_finder.php) ([app/product_finder.php](app/product_finder.php)) | Ranked search across SKU, barcode, batch, serial and name; returns per-location stock. |
| Purchase inward | [public/purchase_new.php](public/purchase_new.php) | A dedicated scan box above the line table adds/increments a line for the scanned SKU/barcode client-side, no page reload. Unknown code → "Not found: `<code>`. Create the product first in Product Master." — never auto-creates a product. |
| Stock picking (online orders) | [public/online_pick.php](public/online_pick.php) | Validates the scanned piece against the order's reserved rack/bin; rejects wrong product/variant and duplicate scans; tracks picked vs missing. |
| Packing verification | [public/online_pack.php](public/online_pack.php) | Rejects items not on the order and already-packed duplicates; blocks completion until every ordered quantity is packed. |
| Branch/godown stock transfers | [public/stock_transfer.php](public/stock_transfer.php), `stock_transfer_approve.php`, `stock_transfer_dispatch.php`, `stock_transfer_receive.php` | Scan to add a piece/batch to a transfer draft; scan to confirm receipt at the destination (added in the transfer-workflow phase of this project). |
| Returns / RTO receive | [public/online_return_receive.php](public/online_return_receive.php) | Scan each returned piece; GOOD restocks, DAMAGED is kept out of sellable stock. |
| Stock adjustment | [public/stock_adjust.php](public/stock_adjust.php) | Scan to identify the exact item/batch before posting an increase, decrease or damage write-off. |
| Physical stock count | [public/stock_count.php](public/stock_count.php) | Scan each piece being counted; duplicate scans in the same session are rejected. |

Everything in this table already existed before today, except for the four
transfer-workflow screens, which were added in this project's transfer
approval phase and already follow the same `data-scanner` pattern.

## "Unknown barcode" behaviour (requirement: never auto-create)

Verified live against the running app, not just read in code:

```
GET /api/scan_find.php?code=NOPE-DOES-NOT-EXIST-999
→ {"ok": false, "error": "Not found"}
```

No row was created in `products`, `batches`, or `product_serials` as a
result. Every lookup screen in the table above follows this same rule — a
scan either resolves to something that already exists, or tells the
operator so, full stop.

## Tested with real data, end to end

Using a product already in the local database (SKU `12345`, barcode
`BRCODE12`, one serialized piece `PFS-12345-1-00001-67`):

- `scan_find.php` by SKU → returned full product + per-location stock with
  rack/bin path.
- `scan_find.php` by the individual piece's serial barcode → returned that
  exact piece's status (`AVAILABLE`) and its one current location.
- `stock.php` by SKU → product row appears in the stock table.
- `product_finder`'s search API by serial barcode → resolved to the same
  product with `"match": "serial_barcode"`.
- An unrecognised code → `Not found`, confirmed nothing was created in the
  database.

## Not using raw barcode scan (by design, not a gap)

`public/sale_return.php` (in-store POS returns) looks up a sale by invoice
number and lets the operator pick from that invoice's actual line items,
rather than scanning a barcode — because the exact item is already narrowed
down to "this invoice's lines," scanning wouldn't add precision there. If
you'd rather it accept a barcode too (to jump straight to a line without
typing the invoice number), say so and it can be added the same way as the
other screens.
