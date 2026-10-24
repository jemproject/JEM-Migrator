<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Read-only source-tab check. Usage: php tests/local-source-tabs.php <site-root>
 */

declare(strict_types=1);

$site = realpath((string) ($argv[1] ?? ''));

if ($site === false || !is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Provide an installed Joomla site root.\n");
    exit(1);
}

const _JEXEC = 1;
define('JPATH_BASE', $site);
require_once $site . '/includes/defines.php';
require_once $site . '/includes/framework.php';

$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
\Joomla\CMS\Factory::$application = $container->get(\Joomla\Console\Application::class);

require_once dirname(__DIR__) . '/src/com_jemmigrator/administrator/src/Adapter/ReadOnlyDatabase.php';
require_once dirname(__DIR__) . '/src/com_jemmigrator/administrator/src/Adapter/AbstractAdapter.php';
require_once dirname(__DIR__) . '/src/com_jemmigrator/administrator/src/Service/SourceRegistry.php';

// The minimal console bootstrap does not load the administrator namespace map.
foreach (['Jevents', 'Dpcalendar', 'Icagenda'] as $name) {
    \JLoader::registerNamespace(
        'JemProject\\Plugin\\JemMigrator\\' . $name,
        JPATH_PLUGINS . '/jemmigrator/' . strtolower($name) . '/src'
    );
}

$tabs = \JemProject\Component\JemMigrator\Administrator\Service\SourceRegistry::tabs();

foreach ($tabs as $key => $tab) {
    printf(
        "%s: detected=%d, adapter_enabled=%d, available=%d, version=%s\n",
        $key,
        $tab['detected'] ? 1 : 0,
        $tab['plugin_enabled'] ? 1 : 0,
        $tab['available'] ? 1 : 0,
        $tab['version']
    );
}
