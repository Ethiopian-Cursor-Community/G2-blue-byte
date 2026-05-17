<?php
/**
 * Apply bazar_events.lifecycle_note + extended status ENUM (postponed, canceled).
 * Safe to call repeatedly (idempotent checks).
 */

/** True when postpone/cancel DB support is present. */
function qb_event_lifecycle_ready(): bool {
    if (!function_exists('qb_has_column') || !qb_has_column('bazar_events', 'lifecycle_note')) {
        return false;
    }
    try {
        $ct = db()->fetchOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bazar_events' AND COLUMN_NAME = 'status'"
        );
        $type = strtolower((string) ($ct['t'] ?? ''));

        return $type !== '' && strpos($type, 'postponed') !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{ok: bool, messages: list<string>, error?: string}
 */
function qb_apply_event_lifecycle_schema(): array {
    $out = ['ok' => true, 'messages' => []];

    try {
        if (!qb_has_column('bazar_events', 'lifecycle_note')) {
            try {
                db()->execute('ALTER TABLE bazar_events ADD COLUMN lifecycle_note TEXT NULL AFTER notes');
                $out['messages'][] = 'Added column lifecycle_note.';
            } catch (Throwable $e) {
                db()->execute('ALTER TABLE bazar_events ADD COLUMN lifecycle_note TEXT NULL');
                $out['messages'][] = 'Added column lifecycle_note (fallback position).';
            }
        } else {
            $out['messages'][] = 'Column lifecycle_note already exists.';
        }

        $ct = db()->fetchOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bazar_events' AND COLUMN_NAME = 'status'"
        );
        $type = strtolower((string) ($ct['t'] ?? ''));
        if ($type !== '' && strpos($type, 'postponed') === false) {
            db()->execute(
                "ALTER TABLE bazar_events MODIFY COLUMN status ENUM('draft','published','live','ended','postponed','canceled') NOT NULL DEFAULT 'draft'"
            );
            $out['messages'][] = 'Extended status enum (postponed, canceled).';
        } else {
            $out['messages'][] = 'Status enum already includes postponed/canceled.';
        }
    } catch (Throwable $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }

    return $out;
}
