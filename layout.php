<?php
declare(strict_types=1);

/**
 * Static twin of public_html/app/Views/status_layout.php: same markup, but every link works without our server
 * (languages are /, /en/, /de/; help and legal links come from src/links.json).
 */
$locale      = App\Core\Language::getLocale();
$base        = $GLOBALS['statusBase'];
$pageTitle   = htmlspecialchars($title);
$pageDesc    = htmlspecialchars($metaDescription);
$localeNames = ['fr' => 'Français', 'en' => 'English', 'de' => 'Deutsch'];
$pageUrl     = static fn (string $l): string => STATUS_URL . ($l === 'fr' ? '' : $l . '/');
?><!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $pageTitle ?> · Orneos</title>
    <meta name="description" content="<?= $pageDesc ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= $pageUrl($locale) ?>">
<?php foreach (LOCALES as $l): ?>
    <link rel="alternate" hreflang="<?= $l ?>" href="<?= $pageUrl($l) ?>">
<?php endforeach; ?>
    <meta http-equiv="refresh" content="300">
    <script>
    // A language chosen here (?lang=, also on links from the site) is remembered; the French root page sends to
    // /en/ or /de/ by that choice, else by the browser's language.
    (function () {
        var p = new URLSearchParams(location.search).get('lang'), l = p;
        try { if (p) localStorage.setItem('orneos-status-lang', p); else l = localStorage.getItem('orneos-status-lang'); } catch (e) {}
        l = l || (navigator.language || '').slice(0, 2);
        if (<?= $locale === 'fr' ? 'true' : 'false' ?> && (l === 'en' || l === 'de')) location.replace(l + '/');
    })();
    </script>
    <link rel="preload" href="<?= $base ?>assets/fonts/schibsted-grotesk-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= asset_v('css/style.css') ?>">
    <link rel="icon" href="<?= asset_v('img/favicon.svg') ?>" type="image/svg+xml">
    <link rel="icon" href="<?= asset_v('img/favicon.png') ?>" type="image/png" sizes="any">
    <link rel="apple-touch-icon" href="<?= asset_v('img/appicon.png') ?>">
    <meta name="theme-color" content="#FFFFFF">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Orneos">
    <meta property="og:url" content="<?= $pageUrl($locale) ?>">
    <meta property="og:title" content="<?= $pageTitle ?> · Orneos">
    <meta property="og:description" content="<?= $pageDesc ?>">
</head>
<body class="status-host">
    <a class="skip" href="#main"><?= translate('skip_to_content') ?></a>
    <header class="status-top">
        <div class="container">
            <a class="brand" href="<?= SITE ?>/" aria-label="Orneos — <?= translate('home') ?>">
                <svg aria-hidden="true"><use href="<?= asset_v('img/icons.svg') ?>#mark"/></svg>Orneos
            </a>
            <details class="dropdown lang-menu">
                <summary aria-label="<?= translate('language') ?> : <?= $localeNames[$locale] ?>"><?= icon('globe') ?><span><?= strtoupper($locale) ?></span><span class="chev" aria-hidden="true"></span></summary>
                <div class="dropdown-panel" role="menu">
                    <?php foreach (LOCALES as $l): ?>
                        <a role="menuitem" href="<?= $base . ($l === 'fr' ? './' : $l . '/') ?>?lang=<?= $l ?>" lang="<?= $l ?>" hreflang="<?= $l ?>"<?= $l === $locale ? ' aria-current="true"' : '' ?>>
                            <span class="code"><?= strtoupper($l) ?></span><span><?= $localeNames[$l] ?></span><?= $l === $locale ? icon('check', 'tick') : '' ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    </header>
    <main id="main">
        <?= $pageContent ?>
    </main>
    <footer class="status-foot">
        <div class="container">
            <a href="<?= SITE ?>/<?= $locale ?>">orneos.eu</a>
            <a href="<?= htmlspecialchars((string) ($links['help'][$locale] ?? SITE)) ?>"><?= translate('help_title') ?></a>
            <a href="<?= htmlspecialchars((string) ($links['legal'][$locale] ?? SITE)) ?>"><?= translate('legal_link') ?></a>
            <a href="mailto:contact@orneos.eu">contact@orneos.eu</a>
            <span>© <?= date('Y') ?> Orneos</span>
        </div>
    </footer>
</body>
</html>
