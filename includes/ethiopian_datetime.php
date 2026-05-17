<?php
/**
 * Ethiopia: Africa/Addis_Ababa (EAT) + Ethiopic date labels (server-rendered fallback).
 * Live updates in the browser use Intl with timeZone Africa/Addis_Ababa + ethiopic calendar
 * (not the visitor’s local timezone).
 */

/** Civil weekday in Addis Ababa → Amharic (short day name). */
function qb_weekday_amharic(DateTime $dtInAddis): string {
    $w = (int) $dtInAddis->format('w');
    $names = ['እሑድ', 'ሰኞ', 'ማክሰኞ', 'ረቡዕ', 'ሐሙስ', 'ዓርብ', 'ቅዳሜ'];

    return $names[$w] ?? '';
}

/** Navbar: Amharic weekday + HH:mm (Addis), no seconds. */
function qb_navbar_ethiopian_clock_strings(): array {
    $tz = new DateTimeZone('Africa/Addis_Ababa');
    $dt = new DateTime('now', $tz);

    return [
        'day_am'    => qb_weekday_amharic($dt),
        'time_hm'   => $dt->format('H:i'),
        'utc_ms'    => (int) round(microtime(true) * 1000),
        'iso_addis' => $dt->format('c'),
    ];
}

function qb_ethiopian_month_name_en(int $m): string {
    $names = [
        1 => 'Meskerem', 2 => 'Tikimt', 3 => 'Hidar', 4 => 'Tahsas', 5 => 'Tir',
        6 => 'Yekatit', 7 => 'Megabit', 8 => 'Miyazya', 9 => 'Ginbot', 10 => 'Sene',
        11 => 'Hamle', 12 => 'Nehase', 13 => 'Pagume',
    ];
    return $names[$m] ?? '';
}

function qb_gregorian_to_jdn(int $gy, int $gm, int $gd): int {
    if (function_exists('gregoriantojd')) {
        return (int) gregoriantojd($gm, $gd, $gy);
    }
    $a = intdiv(14 - $gm, 12);
    $y = $gy + 4800 - $a;
    $m = $gm + 12 * $a - 3;
    return $gd + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
}

/** @return array{0:int,1:int,2:int} Ethiopian year, month, day — JDN method (SamAsEnd gist). */
function qb_jdn_to_ethiopian(int $jdn): array {
    $r = ($jdn - 1723856) % 1461;
    if ($r < 0) {
        $r += 1461;
    }
    $n = ($r % 365) + 365 * intdiv($r, 1460);
    $year = 4 * intdiv($jdn - 1723856, 1461) + intdiv($r, 365) - intdiv($r, 1460);
    $month = intdiv($n, 30) + 1;
    $day = ($n % 30) + 1;
    return [(int) $year, (int) $month, (int) $day];
}

/**
 * @return array{time_hms: string, date_en: string, iso_addis: string, utc_ms: int}
 */
function qb_ethiopian_now_server(): array {
    $tz = new DateTimeZone('Africa/Addis_Ababa');
    $dt = new DateTime('now', $tz);
    $timeHms = $dt->format('H:i:s');
    $isoAddis = $dt->format('c');
    $utcMs = (int) round(microtime(true) * 1000);

    $dateEn = '';
    if (class_exists('IntlDateFormatter')) {
        try {
            $fmt = new IntlDateFormatter(
                'en@calendar=ethiopic',
                IntlDateFormatter::FULL,
                IntlDateFormatter::NONE,
                'Africa/Addis_Ababa',
                null,
                'EEEE, d MMMM y'
            );
            $out = $fmt->format($dt);
            if ($out !== false && $out !== '') {
                $dateEn = $out . ' (E.C.)';
            }
        } catch (Throwable $e) {
            $dateEn = '';
        }
    }

    if ($dateEn === '') {
        $gy = (int) $dt->format('Y');
        $gm = (int) $dt->format('n');
        $gd = (int) $dt->format('j');
        $jdn = qb_gregorian_to_jdn($gy, $gm, $gd);
        [$ey, $em, $ed] = qb_jdn_to_ethiopian($jdn);
        $wk = $dt->format('l');
        $dateEn = sprintf(
            '%s, %d %s %d (E.C.)',
            $wk,
            $ed,
            qb_ethiopian_month_name_en($em),
            $ey
        );
    }

    return [
        'time_hms' => $timeHms,
        'date_en'  => $dateEn,
        'iso_addis'=> $isoAddis,
        'utc_ms'   => $utcMs,
    ];
}

function qb_sidebar_ethiopian_clock(): void {
    $info = qb_ethiopian_now_server();
    ?>
    <div class="sidebar-ethio-clock" id="qb-ethio-clock" data-server-ms="<?= (int) $info['utc_ms'] ?>" data-iso-addis="<?= htmlspecialchars($info['iso_addis'], ENT_QUOTES, 'UTF-8') ?>">
      <div class="sidebar-ethio-clock__label">Ethiopia · EAT · Ethiopic</div>
      <div class="sidebar-ethio-clock__date" id="qb-ethio-date"><?= htmlspecialchars($info['date_en'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="sidebar-ethio-clock__time" id="qb-ethio-time"><?= htmlspecialchars($info['time_hms'], ENT_QUOTES, 'UTF-8') ?> EAT</div>
    </div>
    <?php
}

/** Navbar clock: Amharic weekday + Addis time (HH:mm). IDs preserved for layout tick script. */
function qb_navbar_ethiopian_clock(): void {
    $nav = qb_navbar_ethiopian_clock_strings();
    ?>
    <div class="nav-ethio-clock" id="qb-ethio-clock" data-server-ms="<?= (int) $nav['utc_ms'] ?>" data-iso-addis="<?= htmlspecialchars($nav['iso_addis'], ENT_QUOTES, 'UTF-8') ?>">
      <span class="nav-ethio-clock__date" id="qb-ethio-date"><?= htmlspecialchars($nav['day_am'], ENT_QUOTES, 'UTF-8') ?></span>
      <span class="nav-ethio-clock__sep" aria-hidden="true">·</span>
      <span class="nav-ethio-clock__time" id="qb-ethio-time"><?= htmlspecialchars($nav['time_hm'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php
}
