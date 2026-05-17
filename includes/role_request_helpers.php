<?php
/**
 * Buyer role upgrade requests (seller / organizer) — requires migration app_users columns.
 */

function qb_role_request_columns_ready(): bool {
    return qb_has_column('app_users', 'role_request_status') && qb_has_column('app_users', 'role_requested');
}

function qb_approve_user_role_request(int $appUserId): string {
    $u = db()->fetchOne('SELECT * FROM app_users WHERE id = ?', [$appUserId]);
    if (!$u || ($u['role_request_status'] ?? '') !== 'pending') {
        return 'Invalid request.';
    }
    $req = $u['role_requested'] ?? null;
    if ($req === 'seller') {
        $requireSellerCompliance = qb_setting_get_bool('require_seller_compliance', true);
        $tin = trim((string) ($u['role_request_tin_no'] ?? ''));
        $lic = trim((string) ($u['role_request_license_no'] ?? ''));
        $nid = trim((string) ($u['role_request_national_id_fan_no'] ?? ''));
        $legal = !empty($u['role_request_legal_confirmed']);
        $stallImage = trim((string) ($u['role_request_stall_image'] ?? ''));
        if ($requireSellerCompliance && ($tin === '' || $lic === '' || $nid === '' || !$legal || $stallImage === '')) {
            return 'Seller request is missing compliance fields (TIN, license, National ID/FAN, stall image, legal confirmation).';
        }
        db()->execute(
            "UPDATE app_users
             SET role = 'seller',
                 role_request_status = 'approved',
                 role_requested = NULL,
                 seller_tin_no = COALESCE(NULLIF(role_request_tin_no, ''), seller_tin_no),
                 seller_license_no = COALESCE(NULLIF(role_request_license_no, ''), seller_license_no),
                 seller_national_id_fan_no = COALESCE(NULLIF(role_request_national_id_fan_no, ''), seller_national_id_fan_no),
                 stall_image = COALESCE(NULLIF(role_request_stall_image, ''), stall_image),
                 seller_legal_confirmed = CASE WHEN role_request_legal_confirmed = 1 THEN 1 ELSE seller_legal_confirmed END
             WHERE id = ?",
            [$appUserId]
        );
        $exists = db()->fetchOne('SELECT id FROM sellers WHERE app_user_id = ?', [$appUserId]);
        if (!$exists) {
            $name = $u['display_name'];
            $uid = generateUID();
            db()->execute(
                'INSERT INTO sellers (app_user_id, uid, full_name, market_name, phone, email, password_hash, qr_secret, tin_no, license_no, national_id_fan_no, legal_confirmed, stall_image) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $appUserId,
                    $uid,
                    $name,
                    $name . "'s Shop",
                    $u['phone'] ?? '',
                    $u['email'] ?? '',
                    $u['password_hash'],
                    'qr_sec_' . time(),
                    $u['role_request_tin_no'] ?? null,
                    $u['role_request_license_no'] ?? null,
                    $u['role_request_national_id_fan_no'] ?? null,
                    !empty($u['role_request_legal_confirmed']) ? 1 : 0,
                    $u['role_request_stall_image'] ?? null,
                ]
            );
        } else {
            db()->execute(
                "UPDATE sellers
                 SET tin_no = COALESCE(NULLIF(?, ''), tin_no),
                     license_no = COALESCE(NULLIF(?, ''), license_no),
                     national_id_fan_no = COALESCE(NULLIF(?, ''), national_id_fan_no),
                     stall_image = COALESCE(NULLIF(?, ''), stall_image),
                     legal_confirmed = CASE WHEN ? = 1 THEN 1 ELSE legal_confirmed END
                 WHERE app_user_id = ?",
                [
                    $u['role_request_tin_no'] ?? null,
                    $u['role_request_license_no'] ?? null,
                    $u['role_request_national_id_fan_no'] ?? null,
                    $u['role_request_stall_image'] ?? null,
                    !empty($u['role_request_legal_confirmed']) ? 1 : 0,
                    $appUserId,
                ]
            );
        }
        return '';
    }
    if ($req === 'organizer') {
        db()->execute(
            "UPDATE app_users SET role = 'organizer', role_request_status = 'approved', role_requested = NULL WHERE id = ?",
            [$appUserId]
        );
        return '';
    }
    return 'Unknown requested role.';
}

function qb_reject_user_role_request(int $appUserId): void {
    db()->execute(
        "UPDATE app_users SET role_request_status = 'rejected', role_requested = NULL WHERE id = ?",
        [$appUserId]
    );
}
