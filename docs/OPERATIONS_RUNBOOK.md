# Operations Runbook

## 1) Backup Strategy

- **Database backup frequency**
  - Full backup: daily
  - Incremental/binlog backup: every 15 minutes (if enabled)
- **Uploads backup**
  - `uploads/` directory snapshot: hourly
- **Retention**
  - Daily backups: 30 days
  - Weekly backups: 12 weeks
  - Monthly backups: 12 months

### MySQL backup command (example)

```bash
mysqldump -u root -p qr_bazaar --single-transaction --routines --events > backup_$(date +%F).sql
```

### Restore command (example)

```bash
mysql -u root -p qr_bazaar < backup_YYYY-MM-DD.sql
```

## 2) Recovery Steps

1. Put app in maintenance mode.
2. Restore database from latest healthy snapshot.
3. Restore `uploads/` from matching snapshot.
4. Run smoke checks:
   - login
   - buyer discover
   - seller products
   - organizer dashboard
   - admin reconciliation
5. Re-enable traffic and monitor alerts for 30 minutes.

## 3) Incident Checklist

- Confirm blast radius (roles/pages/endpoints affected)
- Pull last 200 rows from `qb_event_logs`
- Pull failed payment intents from `admin/reconciliation.php`
- Pull suspicious actions from `admin/audit.php`
- Capture timeline and remediation actions

## 4) On-call Commands

- Permission scan:
  - `php tools/permission_audit.php`
- Performance smoke:
  - `php tools/perf_smoke.php http://localhost/QR%20BAZAR 10`

