<?php
declare(strict_types=1);

/**
 * Builds the static status page from the data of check.php:
 *   php build.php <data dir> <output dir>
 * same view and same figures as StatusController::show on the site, one page per language (/, /en/, /de/).
 */
require __DIR__ . '/lib.php';

const STALE_AFTER = 1800;   // GitHub may delay a scheduled run by several minutes

$dir = rtrim($argv[1] ?? 'data', '/');
$out = rtrim($argv[2] ?? 'site', '/');

App\Core\Language::$strings = read_json(__DIR__ . '/src/translations.json');
$links = read_json(__DIR__ . '/src/links.json');
$status = read_json("$dir/status.json");
$history = read_json("$dir/history.json");
$feed = read_json("$dir/feed.json");

$checkedAt = strtotime((string) ($status['checked_at'] ?? '')) ?: null;
$fresh = $checkedAt !== null && time() - $checkedAt < STALE_AFTER;
$maintenanceNow = $fresh && empty($status['checks']['cloud']['ok'])
    && str_contains((string) ($status['checks']['cloud']['detail'] ?? ''), 'maintenance mode');

$services = [];
$monitorEvents = [];
foreach (SERVICES as $key => $names) {
    $now = !$fresh ? null : !array_filter($names, static fn (string $n): bool => empty($status['checks'][$n]['ok']));
    $days = [];
    $checks = $fails = 0;
    for ($i = 89; $i >= 0; $i--) {
        $day = (new DateTimeImmutable('today -' . $i . ' day', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
        $cell = $history[$day][$key] ?? null;
        $days[] = ['day' => $day, 'uptime' => $cell && $cell[0] > 0 ? ($cell[0] - $cell[1]) / $cell[0] * 100 : null];
        $checks += $cell[0] ?? 0;
        $fails += $cell[1] ?? 0;
        foreach (($cell[2] ?? []) as $event) {
            if (is_array($event)) {
                $monitorEvents[] = $event + ['service' => $key];
            }
        }
    }
    $services[$key] = ['up' => $now, 'days' => $days, 'uptime' => $checks > 0 ? ($checks - $fails) / $checks * 100 : null];
}
usort($monitorEvents, static fn (array $a, array $b): int => strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? '')));
$notices = array_reverse(array_values(array_filter((array) ($feed['notices'] ?? []), 'is_array')));

$data = [
    'services'       => $services,
    'fresh'          => $fresh,
    'checkedAt'      => $checkedAt,
    'maintenanceNow' => $maintenanceNow,
    'monitorEvents'  => array_slice($monitorEvents, 0, 100),
    'notices'        => array_slice($notices, 0, 20),
];

/** Renders one language: the site's view inside layout.php. */
function render(string $locale, array $data, array $links): string
{
    App\Core\Language::$locale = $locale;
    $GLOBALS['statusBase'] = $locale === 'fr' ? '' : '../';
    $page = static function (array $vars): string {
        extract($vars, EXTR_SKIP);
        ob_start();
        require __DIR__ . '/src/view.php';
        return (string) ob_get_clean();
    };
    $pageContent = $page($data);
    $title = translate('status_title');
    $metaDescription = translate('status_meta');
    ob_start();
    require __DIR__ . '/layout.php';
    return (string) ob_get_clean();
}

foreach (LOCALES as $locale) {
    $path = $out . ($locale === 'fr' ? '' : '/' . $locale);
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    file_put_contents($path . '/index.html', render($locale, $data, $links));
}
// Assets next to the pages, and the custom domain for GitHub Pages.
exec('cp -R ' . escapeshellarg(__DIR__ . '/src/assets') . ' ' . escapeshellarg($out . '/'));
file_put_contents($out . '/CNAME', "status.orneos.eu\n");
file_put_contents($out . '/.nojekyll', '');
echo "built: $out (", $fresh ? 'fresh' : 'stale', ")\n";
