<?php

function qb_event_approval_schema_ready(): bool
{
    return qb_has_column('bazar_events', 'approval_status');
}

function qb_apply_event_approval_schema(): array
{
    try {
        if (!qb_table_exists('bazar_events')) {
            return ['ok' => false, 'error' => 'bazar_events table missing'];
        }
        if (!qb_has_column('bazar_events', 'approval_status')) {
            db()->execute("ALTER TABLE bazar_events ADD COLUMN approval_status ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved' AFTER status");
        }
        if (!qb_has_column('bazar_events', 'approval_note')) {
            db()->execute('ALTER TABLE bazar_events ADD COLUMN approval_note VARCHAR(400) NULL AFTER approval_status');
        }
        if (!qb_has_column('bazar_events', 'approval_reviewed_by')) {
            db()->execute('ALTER TABLE bazar_events ADD COLUMN approval_reviewed_by INT NULL AFTER approval_note');
        }
        if (!qb_has_column('bazar_events', 'approval_reviewed_at')) {
            db()->execute('ALTER TABLE bazar_events ADD COLUMN approval_reviewed_at DATETIME NULL AFTER approval_reviewed_by');
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

