# QR Bazar Regression Smoke Checklist

Use this checklist after UI or payment-flow changes.

## 1) Auth + Portal Routing
- [ ] Login as buyer and land on `buyer/home.php`.
- [ ] Login as seller and land on `seller/dashboard.php`.
- [ ] Login as organizer and open `organizer/dashboard.php`.
- [ ] Login as admin and open `admin/dashboard.php`.
- [ ] Gatekeeper account opens `gatekeeper/dashboard.php`.

## 2) Ticket + Buyer Flow
- [ ] Buyer can open event cards and click `Get ticket`.
- [ ] Successful ticket purchase returns `ticket_ok`.
- [ ] Duplicate ticket attempt is blocked with `ticket_err`.
- [ ] Buyer can open `buyer/tickets.php` and see active ticket.

## 3) Seller QR Purchase Flow
- [ ] Buyer scans seller QR and opens `buyer/vendor.php`.
- [ ] Cash purchase creates transaction in `pending_confirmation`.
- [ ] Wallet/instant method creates `completed` transaction.
- [ ] Receipt opens with status badge and item lines.

## 4) Telebirr
- [ ] If Telebirr is disabled, user sees clear fallback alert.
- [ ] If return callback is successful, buyer sees success flash.
- [ ] If callback is cancelled/invalid, buyer sees informative flash.
- [ ] Purchases table shows normalized status badge.

## 5) Cash Confirmation
- [ ] Buyer can confirm cash on receipt.
- [ ] Seller can confirm cash on seller receipt.
- [ ] When both confirm, transaction transitions to `completed`.

## 6) Product Management
- [ ] Seller product filters work: search/category/visibility/stock.
- [ ] Approval filter appears only when `approval_status` exists.
- [ ] Reset clears all filters.
- [ ] Edit/Delete/Toggle actions still work on filtered results.

## 7) Promo + Report Dialog
- [ ] Community promo cards render.
- [ ] `Report` opens centered modal dialog.
- [ ] Submit report sends request and closes modal.

## 8) Visual Consistency
- [ ] Light mode: warm cream background and white cards.
- [ ] Sidebar/nav spacing and radius are consistent across portals.
- [ ] Dark mode toggle works without layout shifts.
- [ ] Forms use consistent label/input spacing and focus rings.

