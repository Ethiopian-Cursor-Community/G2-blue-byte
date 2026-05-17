# QR Bazar Component Guidelines

This document defines stable UI/component contracts so features can evolve without regressions.

## 1) Action Buttons (Admin User Management)
- Use icon buttons with `btn btn-icon btn-sm`.
- Role/action classes:
  - `btn-success`, `btn-warning`, `btn-danger`
  - `btn-admin-deactivate`, `btn-admin-lock`, `btn-admin-downgrade`
- Hover behavior must keep solid fill and matching border color.
- All moderation actions requiring reason must use modal flow (`js-open-moderation-modal`).

## 2) Moderation Modal Contract
- Host page: `admin/users.php`.
- Trigger attributes:
  - `data-op`, `data-user-id`, `data-user-name`
- Submit payload:
  - `action=mod_user`
  - `op`
  - `user_id`
  - `note` (required for `lock`, `ban`, `downgrade_seller`)
- Backend must enforce required reason (never UI-only).

## 3) Moderation History Contract
- Table: `user_moderation_history`
- Required fields:
  - `target_app_user_id`, `actor_app_user_id`, `action`, `reason`, `created_at`
- On each moderation action, persist:
  - row in `user_moderation_history`
  - audit record (`qb_audit_log`) for global timeline

## 4) Alerts / Toasts Contract
- Floating alerts are viewport-fixed (`.qb-alert-floating`), bottom-right.
- Animation lifecycle: intro + visible + exit total of 5 seconds.
- Alerts should not depend on scroll container transforms.

## 5) Payment Reconciliation Contract
- Admin route: `admin/reconciliation.php`.
- Must expose:
  - Method x status matrix
  - stale payment detection
  - failed transaction listing
- Status semantics:
  - `pending` / `pending_confirmation` => operational backlog
  - `failed` => reconciliation needed
  - `completed` => settled

## 6) Audit / Critical Timeline Contract
- Critical actions should be visible in `admin/activity.php` timeline.
- Actions to include at minimum:
  - moderation (`lock`, `ban`, `downgrade`)
  - payment finalization (`cash completed`, `telebirr completed`, `telebirr failed`)
- Audit page (`admin/audit.php`) remains source-of-truth drill-down.
