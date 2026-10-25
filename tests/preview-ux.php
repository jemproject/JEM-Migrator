<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Focused rendering regression for the import-run source and preview states.
 */

namespace Joomla\CMS\HTML {
    final class HTMLHelper
    {
        public static function _(string $key): string
        {
            return $key;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static array $strings = [];

        public static function _(string $key): string
        {
            return self::$strings[$key] ?? $key;
        }

        public static function sprintf(string $key, mixed ...$values): string
        {
            return sprintf(self::_($key), ...$values);
        }
    }
}

namespace Joomla\CMS\Router {
    final class Route
    {
        public static function _(string $url): string
        {
            return $url;
        }
    }
}

namespace {
    use Joomla\CMS\Language\Text;

    \define('_JEXEC', 1);
    Text::$strings = parse_ini_file(
        dirname(__DIR__) . '/src/com_jemmigrator/administrator/language/en-GB/com_jemmigrator.ini'
    );

    final class PreviewView
    {
        public array $tabs = [
            'jevents' => [
                'label' => 'JEvents', 'version' => '3.7.0', 'available' => true,
                'detected' => true, 'plugin_enabled' => true, 'schema_compatible' => true,
            ],
            'dpcalendar' => [
                'label' => 'DPCalendar', 'version' => '', 'available' => false,
                'detected' => false, 'plugin_enabled' => false, 'schema_compatible' => null,
            ],
            'icagenda' => [
                'label' => 'iCagenda', 'version' => '', 'available' => false,
                'detected' => false, 'plugin_enabled' => true, 'schema_compatible' => null,
            ],
        ];

        public string $activeSource = 'jevents';
        public array $preview = [];
        public array $jem = ['installed' => true, 'version' => '5.1.0beta5'];
        public array $importInfo = ['compatible' => true, 'ready' => true];
        public array $sourceSummary = ['lines' => ['JEvents 3.7.0 found']];
        public string $sourceError = '';
        public bool $canManagePlugins = false;
        public int $importPluginId = 0;
        public array $runLog = [];
        public array $runs = [];
        public string $error = '';

        public function escape(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        public function render(): string
        {
            ob_start();
            require dirname(__DIR__) . '/src/com_jemmigrator/administrator/tmpl/runs/default.php';

            return (string) ob_get_clean();
        }
    }

    function check(bool $condition, string $case): void
    {
        if (!$condition) {
            throw new \RuntimeException('FAIL ' . $case);
        }

        echo 'PASS ' . $case . PHP_EOL;
    }

    $view = new PreviewView();
    $view->preview = [
        'source' => 'jevents',
        'counts' => ['events' => 0, 'categories_new' => 0, 'skipped_existing' => 4, 'errors' => 0],
    ];
    $html = $view->render();
    check(str_contains($html, 'Migration checks: JEvents'), 'selected source survives navigation');
    check(str_contains($html, 'JEvents 3.7.0'), 'source card identifies JEvents');
    check(str_contains($html, 'alert-info') && str_contains($html, 'Already imported: 4'), 'all imported is informational');
    check(str_contains($html, 'disabled>Start migration</button>'), 'all imported disables start');

    $view->preview['counts'] = ['events' => 1, 'categories_new' => 0, 'skipped_existing' => 0, 'errors' => 0];
    $html = $view->render();
    check(str_contains($html, 'alert-success'), 'new event is ready');
    check(!str_contains($html, 'disabled>Start migration</button>'), 'new event enables start');

    $view->preview['counts'] = ['events' => 0, 'categories_new' => 1, 'skipped_existing' => 0, 'errors' => 0];
    $html = $view->render();
    check(str_contains($html, 'alert-success'), 'new category is ready');
    check(!str_contains($html, 'disabled>Start migration</button>'), 'new category enables start');

    $view->preview['counts'] = ['events' => 0, 'categories_new' => 0, 'skipped_existing' => 0, 'errors' => 1];
    $html = $view->render();
    check(str_contains($html, 'alert-danger'), 'preview error is blocked');
    check(str_contains($html, 'disabled>Start migration</button>'), 'preview error disables start');

    $view->preview['counts']['errors'] = 0;
    $view->tabs['jevents']['available'] = false;
    $html = $view->render();
    check(str_contains($html, 'alert-danger'), 'unavailable source is blocked');
    check(str_contains($html, 'disabled>Start migration</button>'), 'unavailable source disables start');
}
