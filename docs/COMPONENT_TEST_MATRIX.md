# Component Test Matrix

Use this matrix for fast pre-release checks focused on long-term component stability.

## A) Moderation Modal + History
- [ ] In `admin/users.php`, click lock/ban/downgrade icons and confirm modal opens.
- [ ] Empty reason is blocked.
- [ ] Submit with reason updates user state.
- [ ] New reason appears under the user in "Reason history".
- [ ] New action appears in `admin/activity.php` critical timeline.
- [ ] Same action appears in `admin/audit.php`.

## B) Action Button Styling
- [ ] Icon buttons have consistent size/spacing in user actions row.
- [ ] Hover fills remain solid with matching border color.
- [ ] Purple downgrade button remains visually distinct.
- [ ] Dark mode keeps contrast and icon visibility.

## C) Payment Reconciliation
- [ ] Open `admin/reconciliation.php`.
- [ ] Filters (window/method/status) change result sets correctly.
- [ ] Method x status matrix values are populated.
- [ ] "Needs attention" includes stale pending telebirr and cash rows when present.
- [ ] Badge status labels match `qb_payment_status_meta`.

## D) Alerts
- [ ] Trigger any success/warning/info alert.
- [ ] Alert appears fixed at viewport bottom-right.
- [ ] Intro and exit animations run.
- [ ] Alert exits automatically at 5 seconds.

## E) Regression Guard
- [ ] `php -l` passes for changed PHP files.
- [ ] No new linter errors in modified files.
- [ ] Admin sidebar includes "Reconcile" under System.
