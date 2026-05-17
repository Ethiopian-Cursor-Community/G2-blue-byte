<?php
/**
 * Shared layout renderer for all portals
 */

require_once __DIR__ . '/ethiopian_datetime.php';

function qb_navbar(string $portal, string $activePage = ''): void {
    $user = currentUser();
    $name = $user['display_name'] ?? 'User';
    $initial = strtoupper(mb_substr($name, 0, 1));
    $avatarUrl = $user ? qb_avatar_url($user) : null;
    $unread = 0;
    $ownedTickets = 0;
    if ($portal === 'buyer' && !empty($_SESSION['app_user_id'])) {
        $buyerId = (int) $_SESSION['app_user_id'];
        $unread = getUnreadCount($buyerId);
        try {
            $r = db()->fetchOne("SELECT COUNT(*) AS c FROM tickets WHERE buyer_id = ? AND status <> 'cancelled'", [$buyerId]);
            $ownedTickets = (int) ($r['c'] ?? 0);
        } catch (Throwable $e) {
            $ownedTickets = 0;
        }
    }

    ?>
    <nav class="navbar qb-global-header <?= $portal === 'seller' ? 'navbar--seller' : ($portal === 'organizer' ? 'navbar--organizer' : ($portal === 'buyer' ? 'navbar--buyer' : ($portal === 'gatekeeper' ? 'navbar--gatekeeper' : ''))) ?>" aria-label="Primary">
      <div class="navbar-inner qb-global-header__inner <?= $portal === 'seller' ? 'navbar-inner--seller' : ($portal === 'organizer' ? 'navbar-inner--organizer' : ($portal === 'buyer' ? 'navbar-inner--buyer' : ($portal === 'gatekeeper' ? 'navbar-inner--gatekeeper' : ''))) ?>">
        <div class="nav-left">
        <?php if ($portal === 'seller'): ?>
        <div class="nav-logo nav-logo--static" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
          <?= qb_icon('home', 'qb-icon', 22) ?>
          <span><?= htmlspecialchars(APP_NAME) ?></span>
        </div>
        <?php elseif ($portal === 'organizer'): ?>
        <div class="nav-logo nav-logo--static nav-logo--organizer" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
          <?= qb_icon('calendar', 'qb-icon', 22) ?>
          <span><?= htmlspecialchars(APP_NAME) ?></span>
          <span class="nav-badge-role badge-organizer"><?= ucfirst($portal) ?></span>
        </div>
        <?php elseif ($portal === 'buyer'): ?>
        <div class="nav-logo nav-logo--static nav-logo--buyer" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
          <?= qb_icon('home', 'qb-icon', 22) ?>
          <span><?= htmlspecialchars(APP_NAME) ?></span>
          <span class="nav-badge-role badge-buyer"><?= ucfirst($portal) ?></span>
        </div>
        <?php elseif ($portal === 'gatekeeper'): ?>
        <div class="nav-logo nav-logo--static nav-logo--gatekeeper" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
          <?= qb_icon('ticket', 'qb-icon', 22) ?>
          <span><?= htmlspecialchars(APP_NAME) ?></span>
          <span class="nav-badge-role badge-gatekeeper">Gate</span>
        </div>
        <?php else: ?>
        <div class="nav-logo nav-logo--static" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
          <?= qb_icon('cart', 'qb-icon', 22) ?>
          <span><?= htmlspecialchars(APP_NAME) ?></span>
          <span class="nav-badge-role badge-<?= htmlspecialchars($portal) ?>"><?= ucfirst($portal === 'super_admin' ? 'Admin' : $portal) ?></span>
        </div>
        <?php endif; ?>
        </div>

        <?php if ($portal === 'seller'): ?>
        <div class="nav-mid nav-mid--seller" role="group" aria-label="Time">
        <div class="nav-ethio-wrap" aria-label="Ethiopia time">
          <?php qb_navbar_ethiopian_clock(); ?>
        </div>
        </div>
        <?php elseif (in_array($portal, ['admin', 'organizer', 'seller', 'buyer', 'gatekeeper'], true)): ?>
        <div class="nav-ethio-wrap nav-ethio-wrap--center" aria-label="Ethiopia time">
          <?php qb_navbar_ethiopian_clock(); ?>
        </div>
        <?php endif; ?>

        <div class="nav-actions">
          <?php if ($portal === 'buyer'): ?>
            <a href="<?= APP_URL ?>/buyer/tickets.php" class="nav-ticket-chip" title="My owned tickets (<?= $ownedTickets > 99 ? '99+' : $ownedTickets ?>)" aria-label="My owned tickets: <?= $ownedTickets > 99 ? '99 plus' : (int) $ownedTickets ?>">
              <?= qb_icon('ticket', 'qb-icon', 28) ?>
              <strong><?= $ownedTickets > 99 ? '99+' : $ownedTickets ?></strong>
            </a>
          <?php endif; ?>
          <?php if ($portal === 'buyer' && $unread > 0): ?>
            <a href="<?= APP_URL ?>/buyer/tickets.php" class="nav-bell" title="Notifications">
              <?= qb_icon('bell', 'qb-icon', 20) ?>
              <span class="bell-badge"><?= $unread ?></span>
            </a>
          <?php endif; ?>
          <div class="nav-avatar <?= $portal === 'seller' ? 'nav-avatar--mustard' : ($portal === 'organizer' ? 'nav-avatar--organizer' : ($portal === 'buyer' ? 'nav-avatar--buyer' : ($portal === 'gatekeeper' ? 'nav-avatar--gatekeeper' : ''))) ?>" title="<?= htmlspecialchars($name) ?>">
            <?php if ($avatarUrl): ?>
              <img class="qb-avatar-photo" src="<?= htmlspecialchars($avatarUrl) ?>" alt="" width="34" height="34"/>
            <?php else: ?>
              <?= $initial ?>
            <?php endif; ?>
          </div>
          <?php if ($portal === 'seller'): ?>
          <form method="get" action="<?= htmlspecialchars(APP_URL . '/logout.php', ENT_QUOTES, 'UTF-8') ?>" class="nav-signout-form">
            <button type="submit" class="btn btn-ghost btn-sm nav-signout"><?= qb_icon('logout', 'qb-icon', 16) ?> Sign out</button>
          </form>
          <?php else: ?>
          <a href="<?= APP_URL ?>/logout.php" class="btn btn-ghost btn-sm nav-signout">
            <?= qb_icon('logout', 'qb-icon', 16) ?> Sign out
          </a>
          <?php endif; ?>
        </div>
      </div>
    </nav>
    <?php
}

/** Mobile bottom navigation for buyer portal (sidebar hidden on small screens). */
function qb_buyer_mobile_nav(string $activePage): void {
    $base = APP_URL . '/buyer/';
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $current = basename($path);
    if ($current === '' || $current === 'buyer') {
        $current = $activePage !== '' ? basename($activePage) : 'home.php';
    }
    $uid = (int) ($_SESSION['app_user_id'] ?? 0);
    $unread = ($uid > 0 && function_exists('getUnreadCount')) ? getUnreadCount($uid) : 0;
    $items = [
        ['href' => $base . 'home.php', 'file' => 'home.php', 'icon' => 'home', 'label' => 'Home'],
        ['href' => $base . 'discover.php', 'file' => 'discover.php', 'icon' => 'star', 'label' => 'Discover'],
        ['href' => $base . 'scan.php', 'file' => 'scan.php', 'icon' => 'scan', 'label' => 'Scan'],
        ['href' => $base . 'tickets.php', 'file' => 'tickets.php', 'icon' => 'ticket', 'label' => 'Tickets', 'badge' => $unread],
        ['href' => $base . 'map.php', 'file' => 'map.php', 'icon' => 'map', 'label' => 'Map'],
    ];
    ?>
    <nav class="mobile-nav mobile-nav--buyer" aria-label="Buyer">
      <?php foreach ($items as $it):
          $active = ($current === $it['file'])
              || ($it['file'] === 'scan.php' && $current === 'vendor.php');
          $badge = (int) ($it['badge'] ?? 0);
          ?>
      <a href="<?= htmlspecialchars($it['href']) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
        <?= qb_icon($it['icon'], 'qb-icon', 20) ?><span><?= htmlspecialchars($it['label']) ?></span>
        <?php if ($badge > 0 && $it['file'] === 'tickets.php'): ?>
          <span class="mobile-nav-badge"><?= $badge > 99 ? '99+' : $badge ?></span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

/** Mobile bottom navigation for seller portal (sidebar is hidden on small screens). */
function qb_seller_mobile_nav(string $activePage): void {
    $base = APP_URL . '/seller/';
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $current = basename($path ?: ($activePage !== '' ? $activePage : ''));
    $items = [
        ['href' => $base . 'dashboard.php', 'file' => 'dashboard.php', 'icon' => 'chart', 'label' => 'Home'],
        ['href' => $base . 'leaderboards.php', 'file' => 'leaderboards.php', 'icon' => 'star', 'label' => 'Top'],
        ['href' => $base . 'payments.php', 'file' => 'payments.php', 'icon' => 'receipt', 'label' => 'Sales'],
        ['href' => $base . 'products.php', 'file' => 'products.php', 'icon' => 'package', 'label' => 'Stock'],
        ['href' => $base . 'qr.php', 'file' => 'qr.php', 'icon' => 'qr', 'label' => 'QR'],
        ['href' => $base . 'events.php', 'file' => 'events.php', 'icon' => 'calendar', 'label' => 'Events'],
        ['href' => $base . 'promotion_create.php', 'file' => 'promotion_create.php', 'icon' => 'tag', 'label' => 'Promo'],
        ['href' => $base . 'profile.php', 'file' => 'profile.php', 'icon' => 'user', 'label' => 'Me'],
    ];
    ?>
    <nav class="mobile-nav mobile-nav--seller" aria-label="Seller">
      <?php foreach ($items as $it):
          $active = ($current === $it['file'])
              || ($it['file'] === 'payments.php' && $current === 'receipt.php');
          ?>
      <a href="<?= htmlspecialchars($it['href']) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
        <?= qb_icon($it['icon'], 'qb-icon', 20) ?><span><?= htmlspecialchars($it['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

/** Bottom nav for gate portal (phone-first). */
function qb_gatekeeper_mobile_nav(string $activePage): void {
    $base = APP_URL . '/gatekeeper/';
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $current = basename($path ?: ($activePage !== '' ? $activePage : ''));
    $items = [
        ['href' => $base . 'dashboard.php', 'file' => 'dashboard.php', 'icon' => 'chart', 'label' => 'Home'],
        ['href' => $base . 'leaderboards.php', 'file' => 'leaderboards.php', 'icon' => 'star', 'label' => 'Top'],
        ['href' => $base . 'ticket_scan.php', 'file' => 'ticket_scan.php', 'icon' => 'ticket', 'label' => 'Scan'],
        ['href' => $base . 'seller_scan.php', 'file' => 'seller_scan.php', 'icon' => 'qr', 'label' => 'Seller'],
    ];
    ?>
    <nav class="mobile-nav mobile-nav--gatekeeper" aria-label="Gate">
      <?php foreach ($items as $it):
          $active = ($current === $it['file']);
          ?>
      <a href="<?= htmlspecialchars($it['href']) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
        <?= qb_icon($it['icon'], 'qb-icon', 22) ?><span><?= htmlspecialchars($it['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

/** Bottom nav for organizer on phone screens. */
function qb_organizer_mobile_nav(string $activePage): void {
    $base = APP_URL . '/organizer/';
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $current = basename($path ?: ($activePage !== '' ? $activePage : ''));
    $items = [
        ['href' => $base . 'dashboard.php', 'file' => 'dashboard.php', 'icon' => 'chart', 'label' => 'Home'],
        ['href' => $base . 'event.php', 'file' => 'event.php', 'icon' => 'plus', 'label' => 'Event'],
        ['href' => $base . 'sellers.php', 'file' => 'sellers.php', 'icon' => 'people', 'label' => 'Sellers'],
        ['href' => $base . 'staff.php', 'file' => 'staff.php', 'icon' => 'user', 'label' => 'Staff'],
        ['href' => $base . 'ticket_scan.php', 'file' => 'ticket_scan.php', 'icon' => 'ticket', 'label' => 'Scan'],
        ['href' => $base . 'announcements.php', 'file' => 'announcements.php', 'icon' => 'announce', 'label' => 'Notify'],
        ['href' => $base . 'promotion_create.php', 'file' => 'promotion_create.php', 'icon' => 'tag', 'label' => 'Promo'],
        ['href' => $base . 'leaderboards.php', 'file' => 'leaderboards.php', 'icon' => 'star', 'label' => 'Ranks'],
    ];
    ?>
    <nav class="mobile-nav mobile-nav--organizer" aria-label="Organizer">
      <?php foreach ($items as $it):
          $active = ($current === $it['file']);
          ?>
      <a href="<?= htmlspecialchars($it['href']) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
        <?= qb_icon($it['icon'], 'qb-icon', 20) ?><span><?= htmlspecialchars($it['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

/** Bottom nav for admin on phone screens. */
function qb_admin_mobile_nav(string $activePage): void {
    $base = APP_URL . '/admin/';
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $current = basename($path ?: ($activePage !== '' ? $activePage : ''));
    $items = [
        ['href' => $base . 'dashboard.php', 'file' => 'dashboard.php', 'icon' => 'chart', 'label' => 'Home'],
        ['href' => $base . 'leaderboards.php', 'file' => 'leaderboards.php', 'icon' => 'star', 'label' => 'Ranks'],
        ['href' => $base . 'health.php', 'file' => 'health.php', 'icon' => 'info', 'label' => 'Health'],
        ['href' => $base . 'users.php', 'file' => 'users.php', 'icon' => 'users', 'label' => 'Users'],
        ['href' => $base . 'seller_approvals.php', 'file' => 'seller_approvals.php', 'icon' => 'store', 'label' => 'Sellers'],
        ['href' => $base . 'role_requests.php', 'file' => 'role_requests.php', 'icon' => 'people', 'label' => 'Roles'],
        ['href' => $base . 'organizers.php', 'file' => 'organizers.php', 'icon' => 'user', 'label' => 'Orgs'],
        ['href' => $base . 'products_pending.php', 'file' => 'products_pending.php', 'icon' => 'package', 'label' => 'Approval'],
        ['href' => $base . 'events.php', 'file' => 'events.php', 'icon' => 'calendar', 'label' => 'Events'],
        ['href' => $base . 'event_staff.php', 'file' => 'event_staff.php', 'icon' => 'ticket', 'label' => 'Gate'],
        ['href' => $base . 'promos.php', 'file' => 'promos.php', 'icon' => 'tag', 'label' => 'Promos'],
        ['href' => $base . 'promo_posts_queue.php', 'file' => 'promo_posts_queue.php', 'icon' => 'star', 'label' => 'Queue'],
        ['href' => $base . 'reports.php', 'file' => 'reports.php', 'icon' => 'flag', 'label' => 'Reports'],
        ['href' => $base . 'fraud.php', 'file' => 'fraud.php', 'icon' => 'alert', 'label' => 'Fraud'],
        ['href' => $base . 'activity.php', 'file' => 'activity.php', 'icon' => 'activity', 'label' => 'Activity'],
        ['href' => $base . 'observability.php', 'file' => 'observability.php', 'icon' => 'chart', 'label' => 'Observe'],
        ['href' => $base . 'audit.php', 'file' => 'audit.php', 'icon' => 'list', 'label' => 'Audit'],
        ['href' => $base . 'settings.php', 'file' => 'settings.php', 'icon' => 'info', 'label' => 'Settings'],
        ['href' => $base . 'reconciliation.php', 'file' => 'reconciliation.php', 'icon' => 'receipt', 'label' => 'Reconcile'],
        ['href' => $base . 'exports.php', 'file' => 'exports.php', 'icon' => 'download', 'label' => 'Export'],
    ];
    ?>
    <nav class="mobile-nav mobile-nav--admin" aria-label="Admin">
      <?php foreach ($items as $it):
          $active = ($current === $it['file'])
              || ($it['file'] === 'events.php' && strpos($path, '/admin/event_brand.php') !== false)
              || ($it['file'] === 'promos.php' && strpos($path, '/admin/promo_posts_queue.php') !== false);
          ?>
      <a href="<?= htmlspecialchars($it['href']) ?>" class="mobile-nav-item <?= $active ? 'active' : '' ?>">
        <?= qb_icon($it['icon'], 'qb-icon', 20) ?><span><?= htmlspecialchars($it['label']) ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <?php
}

function qb_sidebar(string $portal, string $activePage = ''): void {
    if ($portal === 'gatekeeper') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $gkLinks = [
            ['icon' => 'chart', 'label' => 'Dashboard', 'href' => APP_URL . '/gatekeeper/dashboard.php', 'match' => '/gatekeeper/dashboard'],
            ['icon' => 'star', 'label' => 'Leaderboards', 'href' => APP_URL . '/gatekeeper/leaderboards.php', 'match' => '/gatekeeper/leaderboards'],
            ['icon' => 'ticket', 'label' => 'Ticket scan', 'href' => APP_URL . '/gatekeeper/ticket_scan.php', 'match' => '/gatekeeper/ticket_scan'],
            ['icon' => 'qr', 'label' => 'Seller scan', 'href' => APP_URL . '/gatekeeper/seller_scan.php', 'match' => '/gatekeeper/seller_scan'],
        ];
        ?>
    <aside class="sidebar sidebar--gatekeeper sidebar--simple" aria-label="Gate navigation">
      <nav class="sidebar-nav">
      <div class="sidebar-menu">
        <?php foreach ($gkLinks as $link):
            $active = strpos($uri, $link['match']) !== false;
        ?>
        <a href="<?= htmlspecialchars($link['href']) ?>"
           class="sidebar-link <?= $active ? 'active' : '' ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
          <span class="sidebar-icon"><?= qb_icon($link['icon'], 'qb-icon', 18) ?></span>
          <span><?= htmlspecialchars($link['label']) ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      </nav>
    </aside>
        <?php
        return;
    }

    $menus = [
        'admin' => [
            [
                'section' => 'Overview',
                'items' => [
                    ['icon'=>'chart',    'label'=>'Dashboard',    'href'=>APP_URL.'/admin/dashboard.php'],
                    ['icon'=>'star',     'label'=>'Leaderboards', 'href'=>APP_URL.'/admin/leaderboards.php'],
                    ['icon'=>'activity', 'label'=>'Activity',     'href'=>APP_URL.'/admin/activity.php'],
                    ['icon'=>'chart',    'label'=>'Observability','href'=>APP_URL.'/admin/observability.php'],
                    ['icon'=>'info',     'label'=>'Health',       'href'=>APP_URL.'/admin/health.php'],
                ],
            ],
            [
                'section' => 'People & access',
                'items' => [
                    ['icon'=>'users',    'label'=>'Users',         'href'=>APP_URL.'/admin/users.php'],
                    ['icon'=>'store',    'label'=>'Seller approvals', 'href'=>APP_URL.'/admin/seller_approvals.php'],
                    ['icon'=>'people',   'label'=>'Role requests', 'href'=>APP_URL.'/admin/role_requests.php'],
                    ['icon'=>'user',     'label'=>'Organizers',    'href'=>APP_URL.'/admin/organizers.php'],
                ],
            ],
            [
                'section' => 'Catalog & events',
                'items' => [
                    ['icon'=>'package',  'label'=>'Approvals',  'href'=>APP_URL.'/admin/products_pending.php'],
                    ['icon'=>'calendar', 'label'=>'Events',       'href'=>APP_URL.'/admin/events.php'],
                    ['icon'=>'ticket',   'label'=>'Gate staff',   'href'=>APP_URL.'/admin/event_staff.php'],
                    [
                        'icon' => 'tag',
                        'label' => 'Promotions',
                        'children' => [
                            ['icon' => 'tag', 'label' => 'Admin promos', 'href' => APP_URL . '/admin/promos.php'],
                            ['icon' => 'star', 'label' => 'Community queue', 'href' => APP_URL . '/admin/promo_posts_queue.php'],
                        ],
                    ],
                ],
            ],
            [
                'section' => 'Trust & safety',
                'items' => [
                    ['icon'=>'flag',     'label'=>'Reports', 'href'=>APP_URL.'/admin/reports.php'],
                    ['icon'=>'alert',    'label'=>'Fraud',   'href'=>APP_URL.'/admin/fraud.php'],
                ],
            ],
            [
                'section' => 'System',
                'items' => [
                    ['icon'=>'list',     'label'=>'Audit',    'href'=>APP_URL.'/admin/audit.php'],
                    ['icon'=>'info',     'label'=>'Settings', 'href'=>APP_URL.'/admin/settings.php'],
                    ['icon'=>'receipt',  'label'=>'Reconcile', 'href'=>APP_URL.'/admin/reconciliation.php'],
                    ['icon'=>'download', 'label'=>'Exports',  'href'=>APP_URL.'/admin/exports.php'],
                ],
            ],
        ],
        'organizer' => (function() {
            $uid = (int) ($_SESSION['app_user_id'] ?? 0);
            $isCoOnly = function_exists('qb_organizer_is_co_only') && qb_organizer_is_co_only($uid);
            
            if ($isCoOnly) {
                return [
                    [
                        'section' => 'Operations',
                        'items' => [
                            ['icon' => 'chart', 'label' => 'Dashboard', 'href' => APP_URL . '/organizer/dashboard.php'],
                            ['icon' => 'star', 'label' => 'Leaderboards', 'href' => APP_URL . '/organizer/leaderboards.php'],
                            ['icon' => 'ticket', 'label' => 'Ticket scan', 'href' => APP_URL . '/organizer/ticket_scan.php'],
                            ['icon' => 'list', 'label' => 'Scan History', 'href' => APP_URL . '/organizer/scan_history.php'],
                        ],
                    ],
                ];
            }
            
            return [
                [
                    'section' => 'Overview',
                    'items' => [
                        ['icon' => 'chart', 'label' => 'Dashboard', 'href' => APP_URL . '/organizer/dashboard.php'],
                        ['icon' => 'star', 'label' => 'Leaderboards', 'href' => APP_URL . '/organizer/leaderboards.php'],
                    ],
                ],
                [
                    'section' => 'Events & stalls',
                    'items' => [
                        ['icon' => 'plus', 'label' => 'New Event', 'href' => APP_URL . '/organizer/event.php'],
                        ['icon' => 'people', 'label' => 'Sellers', 'href' => APP_URL . '/organizer/sellers.php'],
                        ['icon' => 'user', 'label' => 'Gate staff', 'href' => APP_URL . '/organizer/staff.php'],
                        ['icon' => 'ticket', 'label' => 'Ticket scan', 'href' => APP_URL . '/organizer/ticket_scan.php'],
                    ],
                ],
                [
                    'section' => 'Communications',
                    'items' => [
                        [
                            'icon' => 'announce',
                            'label' => 'Campaigns',
                            'children' => [
                                ['icon' => 'announce', 'label' => 'Announcements', 'href' => APP_URL . '/organizer/announcements.php'],
                                ['icon' => 'tag', 'label' => 'Promotion', 'href' => APP_URL . '/organizer/promotion_create.php'],
                            ],
                        ],
                    ],
                ],
            ];
        })(),
        'buyer' => [
            ['icon'=>'home',     'label'=>'Home',         'href'=>APP_URL.'/buyer/home.php'],
            ['icon'=>'activity', 'label'=>'Leaderboards', 'href'=>APP_URL.'/buyer/leaderboards.php'],
            ['icon'=>'star',     'label'=>'Discover',     'href'=>APP_URL.'/buyer/discover.php'],
            ['icon'=>'scan',     'label'=>'Scan QR',      'href'=>APP_URL.'/buyer/scan.php'],
            ['icon'=>'receipt',  'label'=>'Purchases',    'href'=>APP_URL.'/buyer/purchases.php'],
            ['icon'=>'ticket',   'label'=>'My Tickets',   'href'=>APP_URL.'/buyer/tickets.php'],
            ['icon'=>'map',      'label'=>'Live Map',     'href'=>APP_URL.'/buyer/map.php'],
            ['icon'=>'user',     'label'=>'Profile',      'href'=>APP_URL.'/buyer/profile.php'],
        ],
    ];

    if ($portal === 'seller') {
        $isActive = static function (string $pathFragment): bool {
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            return strpos($uri, $pathFragment) !== false;
        };
        $sellerLinks = [
            ['icon' => 'chart', 'label' => 'Dashboard', 'href' => APP_URL . '/seller/dashboard.php', 'match' => 'seller/dashboard'],
            ['icon' => 'star', 'label' => 'Leaderboards', 'href' => APP_URL . '/seller/leaderboards.php', 'match' => 'leaderboards'],
            [
                'icon' => 'receipt',
                'label' => 'Commerce',
                'children' => [
                    ['icon' => 'receipt', 'label' => 'Payments', 'href' => APP_URL . '/seller/payments.php', 'match' => 'seller/payments'],
                    ['icon' => 'package', 'label' => 'Products', 'href' => APP_URL . '/seller/products.php', 'match' => 'seller/products'],
                ],
            ],
            [
                'icon' => 'qr',
                'label' => 'Tools',
                'children' => [
                    ['icon' => 'scan', 'label' => 'Shop stalls', 'href' => APP_URL . '/buyer/scan.php', 'match' => 'buyer/scan'],
                    ['icon' => 'qr', 'label' => 'My QR', 'href' => APP_URL . '/seller/qr.php', 'match' => 'seller/qr'],
                ],
            ],
            ['icon' => 'calendar', 'label' => 'Apply Events', 'href' => APP_URL . '/seller/events.php', 'match' => 'seller/events'],
            ['icon' => 'tag', 'label' => 'Promotion', 'href' => APP_URL . '/seller/promotion_create.php', 'match' => 'promotion_create'],
            ['icon' => 'user', 'label' => 'Profile', 'href' => APP_URL . '/seller/profile.php', 'match' => 'seller/profile'],
        ];
        ?>
    <aside class="sidebar sidebar--seller sidebar--seller-shell" aria-label="Seller navigation">
      <nav class="sidebar-nav">
      <div class="sidebar-menu">
        <?php foreach ($sellerLinks as $link):
            $children = isset($link['children']) && is_array($link['children']) ? $link['children'] : [];
            if ($children !== []):
                $childActive = false;
                foreach ($children as $childLink) {
                    $match = (string) ($childLink['match'] ?? '');
                    if ($match !== '' && $isActive($match)) {
                        $childActive = true;
                        break;
                    }
                }
        ?>
        <details class="sidebar-dropdown <?= $childActive ? 'is-active' : '' ?>" <?= $childActive ? 'open' : '' ?>>
          <summary class="sidebar-link sidebar-link--dropdown">
            <span class="sidebar-icon"><?= qb_icon((string) ($link['icon'] ?? 'list'), 'qb-icon', 18) ?></span>
            <span><?= htmlspecialchars((string) ($link['label'] ?? 'More')) ?></span>
            <span class="sidebar-dropdown-caret"><?= qb_icon('arrow-right', 'qb-icon', 14) ?></span>
          </summary>
          <div class="sidebar-dropdown-menu">
            <?php foreach ($children as $childLink):
                $childMatch = (string) ($childLink['match'] ?? '');
                $childItemActive = $childMatch !== '' && $isActive($childMatch);
                if (($childLink['label'] ?? '') === 'Payments') {
                    $childItemActive = $childItemActive || $isActive('seller/receipt');
                }
            ?>
            <a href="<?= htmlspecialchars((string) ($childLink['href'] ?? '#')) ?>"
               class="sidebar-link sidebar-link--sub <?= $childItemActive ? 'active' : '' ?>"
               <?= $childItemActive ? 'aria-current="page"' : '' ?>>
              <span class="sidebar-icon"><?= qb_icon((string) ($childLink['icon'] ?? 'dot'), 'qb-icon', 16) ?></span>
              <span><?= htmlspecialchars((string) ($childLink['label'] ?? 'Item')) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </details>
        <?php else:
            $active = $isActive((string) ($link['match'] ?? ''));
        ?>
        <a href="<?= htmlspecialchars($link['href']) ?>"
           class="sidebar-link <?= $active ? 'active' : '' ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
          <span class="sidebar-icon"><?= qb_icon($link['icon'], 'qb-icon', 18) ?></span>
          <span><?= htmlspecialchars($link['label']) ?></span>
        </a>
        <?php endif; endforeach; ?>
      </div>
      </nav>
    </aside>
        <?php
        return;
    }

    if ($portal === 'buyer') {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isActive = static function (string $fragment) use ($uri): bool {
            return strpos($uri, $fragment) !== false;
        };
        $buyerLinks = [
            ['icon' => 'home', 'label' => 'Home', 'href' => APP_URL . '/buyer/home.php', 'match' => '/buyer/home'],
            ['icon' => 'activity', 'label' => 'Leaderboards', 'href' => APP_URL . '/buyer/leaderboards.php', 'match' => '/buyer/leaderboards'],
            ['icon' => 'star', 'label' => 'Discover', 'href' => APP_URL . '/buyer/discover.php', 'match' => '/buyer/discover'],
            [
                'icon' => 'scan',
                'label' => 'Shop & pay',
                'children' => [
                    ['icon' => 'scan', 'label' => 'Scan QR', 'href' => APP_URL . '/buyer/scan.php', 'match' => '/buyer/scan'],
                    ['icon' => 'receipt', 'label' => 'Purchases', 'href' => APP_URL . '/buyer/purchases.php', 'match' => '/buyer/purchases'],
                    ['icon' => 'ticket', 'label' => 'My Tickets', 'href' => APP_URL . '/buyer/tickets.php', 'match' => '/buyer/tickets'],
                    ['icon' => 'map', 'label' => 'Live Map', 'href' => APP_URL . '/buyer/map.php', 'match' => '/buyer/map'],
                ],
            ],
            ['icon' => 'user', 'label' => 'Profile', 'href' => APP_URL . '/buyer/profile.php', 'match' => '/buyer/profile'],
        ];
        ?>
    <aside class="sidebar sidebar--buyer sidebar--buyer-shell" aria-label="Buyer navigation">
      <nav class="sidebar-nav">
      <div class="sidebar-menu">
        <?php foreach ($buyerLinks as $link):
            $children = isset($link['children']) && is_array($link['children']) ? $link['children'] : [];
            if ($children !== []):
                $childActive = false;
                foreach ($children as $childLink) {
                    $childMatch = (string) ($childLink['match'] ?? '');
                    if ($childMatch !== '' && $isActive($childMatch)) {
                        $childActive = true;
                        break;
                    }
                    if (($childLink['label'] ?? '') === 'Purchases' && $isActive('/buyer/receipt')) {
                        $childActive = true;
                        break;
                    }
                    if (($childLink['label'] ?? '') === 'Scan QR' && $isActive('/buyer/vendor')) {
                        $childActive = true;
                        break;
                    }
                }
        ?>
        <details class="sidebar-dropdown <?= $childActive ? 'is-active' : '' ?>" <?= $childActive ? 'open' : '' ?>>
          <summary class="sidebar-link sidebar-link--dropdown">
            <span class="sidebar-icon"><?= qb_icon((string) ($link['icon'] ?? 'list'), 'qb-icon', 18) ?></span>
            <span><?= htmlspecialchars((string) ($link['label'] ?? 'More')) ?></span>
            <span class="sidebar-dropdown-caret"><?= qb_icon('arrow-right', 'qb-icon', 14) ?></span>
          </summary>
          <div class="sidebar-dropdown-menu">
            <?php foreach ($children as $childLink):
                $childItemActive = $isActive((string) ($childLink['match'] ?? ''));
                if (($childLink['label'] ?? '') === 'Purchases') {
                    $childItemActive = $childItemActive || $isActive('/buyer/receipt');
                }
                if (($childLink['label'] ?? '') === 'Scan QR') {
                    $childItemActive = $childItemActive || $isActive('/buyer/vendor');
                }
            ?>
            <a href="<?= htmlspecialchars((string) ($childLink['href'] ?? '#')) ?>"
               class="sidebar-link sidebar-link--sub <?= $childItemActive ? 'active' : '' ?>"
               <?= $childItemActive ? 'aria-current="page"' : '' ?>>
              <span class="sidebar-icon"><?= qb_icon((string) ($childLink['icon'] ?? 'dot'), 'qb-icon', 16) ?></span>
              <span><?= htmlspecialchars((string) ($childLink['label'] ?? 'Item')) ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </details>
        <?php else:
            $active = $isActive((string) ($link['match'] ?? ''));
        ?>
        <a href="<?= htmlspecialchars($link['href']) ?>"
           class="sidebar-link <?= $active ? 'active' : '' ?>"
           <?= $active ? 'aria-current="page"' : '' ?>>
          <span class="sidebar-icon"><?= qb_icon($link['icon'], 'qb-icon', 18) ?></span>
          <span><?= htmlspecialchars($link['label']) ?></span>
        </a>
        <?php endif; endforeach; ?>
      </div>
      </nav>
    </aside>
        <?php
        return;
    }

    $links = $menus[$portal] ?? $menus['buyer'];
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $sidebarGrouped = in_array($portal, ['admin', 'organizer'], true);
    ?>
    <aside class="sidebar sidebar--simple <?= $sidebarGrouped ? 'sidebar--grouped' : '' ?> <?= $portal === 'organizer' ? 'sidebar--organizer' : '' ?>" aria-label="App sidebar">
      <nav class="sidebar-nav">
      <div class="sidebar-menu">
        <?php
        if ($sidebarGrouped && isset($links[0]['section'])) {
            foreach ($links as $block):
                $sectionLabel = $block['section'] ?? '';
                $items = $block['items'] ?? [];
        ?>
        <div class="sidebar-group">
          <?php if ($sectionLabel !== ''): ?>
          <div class="sidebar-group-label"><?= htmlspecialchars($sectionLabel) ?></div>
          <?php endif; ?>
          <?php foreach ($items as $link):
                $children = isset($link['children']) && is_array($link['children']) ? $link['children'] : [];
                if ($children !== []):
                    $childActive = false;
                    foreach ($children as $childLink) {
                        $cpath = parse_url((string) ($childLink['href'] ?? ''), PHP_URL_PATH) ?: '';
                        $cfile = $cpath !== '' ? basename($cpath) : '';
                        $currentFile = $activePage !== '' ? basename($activePage) : basename(parse_url($uri, PHP_URL_PATH) ?: '');
                        if ($cfile !== '' && $currentFile === $cfile) {
                            $childActive = true;
                            break;
                        }
                    }
          ?>
          <details class="sidebar-dropdown <?= $childActive ? 'is-active' : '' ?>" <?= $childActive ? 'open' : '' ?>>
            <summary class="sidebar-link sidebar-link--dropdown">
              <span class="sidebar-icon"><?= qb_icon((string) ($link['icon'] ?? 'list'), 'qb-icon', 18) ?></span>
              <span><?= htmlspecialchars((string) ($link['label'] ?? 'More')) ?></span>
              <span class="sidebar-dropdown-caret"><?= qb_icon('arrow-right', 'qb-icon', 14) ?></span>
            </summary>
            <div class="sidebar-dropdown-menu">
              <?php foreach ($children as $childLink):
                    $cpath = parse_url((string) ($childLink['href'] ?? ''), PHP_URL_PATH) ?: '';
                    $cfile = $cpath !== '' ? basename($cpath) : '';
                    $currentFile = $activePage !== '' ? basename($activePage) : basename(parse_url($uri, PHP_URL_PATH) ?: '');
                    $cActive = ($cfile !== '' && $currentFile === $cfile);
              ?>
              <a href="<?= htmlspecialchars((string) ($childLink['href'] ?? '#')) ?>"
                 class="sidebar-link sidebar-link--sub <?= $cActive ? 'active' : '' ?>"
                 <?= $cActive ? 'aria-current="page"' : '' ?>>
                <span class="sidebar-icon"><?= qb_icon((string) ($childLink['icon'] ?? 'dot'), 'qb-icon', 16) ?></span>
                <span><?= htmlspecialchars((string) ($childLink['label'] ?? 'Item')) ?></span>
              </a>
              <?php endforeach; ?>
            </div>
          </details>
          <?php else:
                $path = parse_url((string) ($link['href'] ?? ''), PHP_URL_PATH) ?: '';
                $file = $path !== '' ? basename($path) : '';
                $currentFile = $activePage !== '' ? basename($activePage) : basename(parse_url($uri, PHP_URL_PATH) ?: '');
                $isActive = ($file !== '' && $currentFile === $file);
          ?>
          <a href="<?= htmlspecialchars((string) ($link['href'] ?? '#')) ?>"
             class="sidebar-link <?= $isActive ? 'active' : '' ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon"><?= qb_icon((string) ($link['icon'] ?? 'dot'), 'qb-icon', 18) ?></span>
            <span><?= htmlspecialchars((string) ($link['label'] ?? 'Item')) ?></span>
          </a>
          <?php endif; endforeach; ?>
        </div>
        <?php
            endforeach;
        } else {
            foreach ($links as $link):
                $path = parse_url($link['href'], PHP_URL_PATH) ?: '';
                $file = $path !== '' ? basename($path) : '';
                $isActive = ($file !== '' && strpos($uri, $file) !== false)
                    || ($activePage !== '' && basename($activePage) === $file);
        ?>
          <a href="<?= htmlspecialchars($link['href']) ?>"
             class="sidebar-link <?= $isActive ? 'active' : '' ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <span class="sidebar-icon"><?= qb_icon($link['icon'], 'qb-icon', 18) ?></span>
            <span><?= htmlspecialchars($link['label']) ?></span>
          </a>
        <?php
            endforeach;
        }
        ?>
      </div>
      </nav>
      <?php if ($portal !== 'organizer' && $portal !== 'buyer' && $portal !== 'admin' && $portal !== 'super_admin'): ?>
      <div class="sidebar-footer">
        <?php
        $u = currentUser();
        $name = $u['display_name'] ?? 'Guest';
        $initial = strtoupper(mb_substr($name, 0, 1));
        $sideAvatar = $u ? qb_avatar_url($u) : null;
        ?>
        <div class="sidebar-user-card">
          <div class="sidebar-user-avatar-wrap">
            <div class="sidebar-user-avatar">
              <?php if ($sideAvatar): ?>
                <img class="qb-avatar-photo" src="<?= htmlspecialchars($sideAvatar) ?>" alt="" width="38" height="38"/>
              <?php else: ?>
                <?= $initial ?>
              <?php endif; ?>
            </div>
            <span class="sidebar-user-status" aria-hidden="true"></span>
          </div>
          <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?= htmlspecialchars($name) ?></div>
            <div class="sidebar-user-role"><?= htmlspecialchars($portal === 'super_admin' ? 'admin' : $portal) ?></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </aside>
    <?php
}

/**
 * Full page open — outputs <!DOCTYPE>, <html>, <head>, navbar, sidebar
 * Call qb_page_end() to close
 */
function qb_page_start(string $portal, string $title, string $activePage = '', bool $hasCharts = false): void {
    if (function_exists('qb_sync_event_statuses')) {
        qb_sync_event_statuses();
    }
    $GLOBALS['qb_layout_portal'] = $portal;
    $GLOBALS['qb_layout_active_page'] = $activePage;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8"/>
      <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
      <meta name="color-scheme" content="light dark"/>
      <?php if ($portal === 'seller'): ?>
      <meta name="theme-color" content="#2A3582"/>
      <meta name="apple-mobile-web-app-capable" content="yes"/>
      <?php elseif ($portal === 'organizer'): ?>
      <meta name="theme-color" content="#2A3582"/>
      <?php elseif ($portal === 'buyer'): ?>
      <meta name="theme-color" content="#2A3582"/>
      <?php elseif ($portal === 'gatekeeper'): ?>
      <meta name="theme-color" content="#2A3582"/>
      <meta name="apple-mobile-web-app-capable" content="yes"/>
      <?php endif; ?>
      <script>
      (function(){try{document.documentElement.setAttribute('data-theme','light');localStorage.setItem('qb-theme','light');}catch(e){document.documentElement.setAttribute('data-theme','light');}})();
      </script>
      <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
      <link rel="preconnect" href="https://fonts.googleapis.com"/>
      <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
      <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
      <link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,opsz,wght@0,6..12,400;0,6..12,500;0,6..12,600;0,6..12,700;0,6..12,800&display=swap" rel="stylesheet"/>
      <?php if (in_array($portal, ['seller', 'admin', 'organizer', 'buyer', 'gatekeeper'], true)): ?>
      <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
      <?php endif; ?>
      <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"/>
      <?php if (in_array($portal, ['seller', 'buyer', 'gatekeeper', 'admin', 'organizer'], true)): ?>
      <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/mobile.css"/>
      <?php endif; ?>
      <?php if ($portal === 'seller'): ?>
      <link rel="manifest" href="<?= APP_URL ?>/seller/manifest.json"/>
      <?php endif; ?>
      <?php if ($hasCharts): ?>
      <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
      <?php endif; ?>
    </head>
    <body class="portal-<?= htmlspecialchars($portal) ?>">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <?php qb_navbar($portal, $activePage); ?>
    <div class="app-layout">
      <?php qb_sidebar($portal, $activePage); ?>
      <main id="main-content" class="main-content" tabindex="-1">
      <?php if (function_exists('qb_is_disabled_session') && qb_is_disabled_session()): ?>
        <div class="alert alert-warning mb-2" role="status">
          <?= qb_icon('alert', 'qb-icon', 16) ?>
          Read-only account: you can view pages, but buying/selling and other save actions are disabled.
        </div>
      <?php endif; ?>
    <?php
}

function qb_page_end(): void {
    $p = $GLOBALS['qb_layout_portal'] ?? '';
    if (in_array($p, ['buyer', 'seller', 'organizer', 'gatekeeper'], true)) {
        qb_render_portal_marquee($p);
    }
    if (in_array($p, ['buyer', 'seller', 'organizer', 'gatekeeper'], true)) {
        echo '<div class="status-bar online qb-global-online-pill" aria-live="polite"><span class="status-dot"></span> Online</div>';
    }
    echo '</main></div>';
    if ($p === 'seller') {
        qb_seller_mobile_nav((string) ($GLOBALS['qb_layout_active_page'] ?? ''));
    } elseif ($p === 'buyer') {
        qb_buyer_mobile_nav((string) ($GLOBALS['qb_layout_active_page'] ?? ''));
    } elseif ($p === 'gatekeeper') {
        qb_gatekeeper_mobile_nav((string) ($GLOBALS['qb_layout_active_page'] ?? ''));
    } elseif ($p === 'organizer') {
        qb_organizer_mobile_nav((string) ($GLOBALS['qb_layout_active_page'] ?? ''));
    } elseif ($p === 'admin') {
        qb_admin_mobile_nav((string) ($GLOBALS['qb_layout_active_page'] ?? ''));
    }
    echo '<script>document.querySelector(".skip-link")?.addEventListener("click",function(){var m=document.getElementById("main-content");if(m){m.focus({preventScroll:false});}});</script>';
    echo <<<'HTML'
<script>
(function () {
  try {
    if ("scrollRestoration" in history) {
      history.scrollRestoration = "manual";
    }
    var body = document.body;
    var bodyClass = body ? (body.className || "") : "";
    var portalMatch = bodyClass.match(/\bportal-[a-z_]+\b/);
    var portalKey = portalMatch ? portalMatch[0] : "portal-global";
    var pageKey = "qb-scroll:" + location.pathname + location.search;
    var pathKey = "qb-scroll:path:" + location.pathname;
    var main = document.getElementById("main-content");
    var side = document.querySelector(".sidebar");
    var sideTarget = side;
    var keyMain = pageKey + ":main";
    var keyMainPath = pathKey + ":main";
    var keyWin = pageKey + ":win";
    var keyWinPath = pathKey + ":win";
    var keySidePage = pageKey + ":side";
    var keySidePortal = "qb-scroll:" + portalKey + ":side";
    var keySidePortalLocal = "qb-scroll-local:" + portalKey + ":side";

    function readNum(key) {
      var raw = sessionStorage.getItem(key);
      if (raw === null) return null;
      var n = parseInt(raw, 10);
      return Number.isFinite(n) && n >= 0 ? n : 0;
    }

    function save() {
      if (main) {
        var mTop = main.scrollTop || 0;
        sessionStorage.setItem(keyMain, String(mTop));
        sessionStorage.setItem(keyMainPath, String(mTop));
      }
      var wTop = window.scrollY || window.pageYOffset || 0;
      sessionStorage.setItem(keyWin, String(wTop));
      sessionStorage.setItem(keyWinPath, String(wTop));
      if (sideTarget) {
        var sideTop = sideTarget.scrollTop || 0;
        sessionStorage.setItem(keySidePage, String(sideTop));
        sessionStorage.setItem(keySidePortal, String(sideTop));
        try {
          localStorage.setItem(keySidePortalLocal, String(sideTop));
        } catch (e) {}
      }
    }

    function restore() {
      var m = readNum(keyMain);
      if (m === null) {
        m = readNum(keyMainPath);
      }
      if (main && m !== null) {
        main.scrollTop = m;
      }
      var w = readNum(keyWin);
      if (w === null) {
        w = readNum(keyWinPath);
      }
      if (w !== null) {
        window.scrollTo(0, w);
      }
      var s = readNum(keySidePage);
      if (s === null) {
        s = readNum(keySidePortal);
      }
      if (s === null) {
        try {
          var ls = localStorage.getItem(keySidePortalLocal);
          if (ls !== null) {
            var lsn = parseInt(ls, 10);
            if (Number.isFinite(lsn) && lsn >= 0) s = lsn;
          }
        } catch (e) {}
      }
      if (sideTarget && s !== null) {
        sideTarget.scrollTop = s;
      }
    }

    function ensureActiveSidebarLinkVisible() {
      if (!sideTarget) return;
      var active = sideTarget.querySelector(".sidebar-link.active, .sidebar-link--sub.active");
      if (!active || !active.scrollIntoView) return;
      active.scrollIntoView({ block: "nearest", inline: "nearest" });
    }

    window.addEventListener("beforeunload", save, { passive: true });
    window.addEventListener("pagehide", save, { passive: true });
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "hidden") save();
    }, { passive: true });
    if (main) main.addEventListener("scroll", save, { passive: true });
    if (sideTarget) sideTarget.addEventListener("scroll", save, { passive: true });
    window.addEventListener("pageshow", function () {
      restore();
      requestAnimationFrame(restore);
      setTimeout(ensureActiveSidebarLinkVisible, 60);
    }, { passive: true });
    document.addEventListener("click", function (e) {
      var t = e.target;
      var nav = t && t.closest ? t.closest("a,button,[role='button']") : null;
      if (nav) save();
    }, { capture: true, passive: true });
    document.addEventListener("submit", save, { capture: true, passive: true });

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", function () {
        restore();
        requestAnimationFrame(restore);
        setTimeout(restore, 120);
        setTimeout(restore, 360);
        setTimeout(ensureActiveSidebarLinkVisible, 80);
      });
    } else {
      restore();
      requestAnimationFrame(restore);
      setTimeout(restore, 120);
      setTimeout(restore, 360);
      setTimeout(ensureActiveSidebarLinkVisible, 80);
    }
  } catch (e) {}
})();
</script>
HTML;
    echo '<script>(function(){document.addEventListener("contextmenu",function(e){e.preventDefault();},{capture:true});})();</script>';
    echo '<script>(function(){function initFloatingAlerts(){var nodes=document.querySelectorAll(".alert.alert-success, .alert.alert-warning, .alert.alert-info, .alert.alert-danger");if(!nodes||!nodes.length){return;}var idx=0;nodes.forEach(function(el){if(!el||el.classList.contains("qb-alert-floating")||el.closest(".qb-toast")){return;}if(document.body&&el.parentNode!==document.body){document.body.appendChild(el);}el.classList.add("qb-alert-floating");el.style.setProperty("--qb-alert-index",String(idx));idx+=1;setTimeout(function(){el.classList.add("qb-alert-floating--done");if(el&&el.parentNode){el.parentNode.removeChild(el);}},5000);});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",initFloatingAlerts,{once:true});}else{initFloatingAlerts();}})();</script>';
    $app = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
    echo '<script>(function(){var AM=["እሑድ","ሰኞ","ማክሰኞ","ረቡዕ","ሐሙስ","ዓርብ","ቅዳሜ"];var EN=["Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"];var ed=document.getElementById("qb-ethio-date"),et=document.getElementById("qb-ethio-time");if(!ed||!et)return;function tick(){try{var n=new Date();var wd=new Intl.DateTimeFormat("en-US",{timeZone:"Africa/Addis_Ababa",weekday:"long"}).format(n);var ix=EN.indexOf(wd);ed.textContent=ix>=0?AM[ix]:wd;et.textContent=new Intl.DateTimeFormat("en-GB",{timeZone:"Africa/Addis_Ababa",hour:"2-digit",minute:"2-digit",second:"2-digit",hour12:false}).format(n);}catch(e){}}tick();setInterval(tick,1000);setInterval(function(){fetch("' . $app . '/api/ethiopian_time.php",{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){if(j&&j.day_am)ed.textContent=j.day_am;if(j&&j.time_hms)et.textContent=j.time_hms;else if(j&&j.time_hm)et.textContent=j.time_hm+":00";}).catch(function(){});},60000);})();</script>';
    if (is_readable(__DIR__ . '/cursor_assist.php')) {
        require_once __DIR__ . '/cursor_assist.php';
        qb_cursor_assist_render((string) ($p ?? ''));
    }
    echo '</body></html>';
}
