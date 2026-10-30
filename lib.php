<?php
declare(strict_types=1);

/**
 * What the site's status view needs outside the site: src/view.php is public_html/app/Views/pages/status.php copied
 * as is by tools/status-publish.sh, with its texts (src/translations.json) and assets (src/assets/).
 */

namespace App\Core {
    final class Language
    {
        public static string $locale = 'fr';
        /** @var array<string, array<string, string>> */
        public static array $strings = [];

        public static function getLocale(): string
        {
            return self::$locale;
        }

        public static function translate(string $key, array $vars = []): string
        {
            $message = self::$strings[self::$locale][$key] ?? self::$strings['fr'][$key] ?? $key;
            foreach ($vars as $name => $value) {
                $message = str_replace('{' . $name . '}', (string) $value, $message);
            }
            return $message;
        }
    }
}

namespace {
    const LOCALES = ['fr', 'en', 'de'];
    const SITE = 'https://orneos.eu';
    const STATUS_URL = 'https://status.orneos.eu/';
    // Public services and the probes behind each one. Port 25 is not probed: GitHub's runners cannot reach it.
    const SERVICES = [
        'site'  => ['site'],
        'cloud' => ['cloud'],
        'mail'  => ['mail_smtps', 'mail_imaps'],
    ];

    /** Relative path from the page being built to the site root ('' at the root, '../' under /en/). */
    $GLOBALS['statusBase'] = '';

    function translate(string $key, array $vars = []): string
    {
        return App\Core\Language::translate($key, $vars);
    }

    function asset_v(string $path): string
    {
        $file = __DIR__ . '/src/assets/' . $path;
        return $GLOBALS['statusBase'] . 'assets/' . $path . '?v=' . (is_file($file) ? substr((string) md5_file($file), 0, 8) : '0');
    }

    function icon(string $name, string $class = ''): string
    {
        $sprite = asset_v('img/icons.svg');
        return '<svg class="icon' . ($class !== '' ? ' ' . $class : '') . '" aria-hidden="true" focusable="false"><use href="'
            . $sprite . '#' . htmlspecialchars($name) . '"/></svg>';
    }

    /** @return array<mixed> */
    function read_json(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : [];
    }

    function write_json(string $file, array $data): void
    {
        file_put_contents($file . '.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        rename($file . '.tmp', $file);
    }
}
