<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Read-only local JEvents-to-JEM preview. Usage:
 * php tests/local-mapping-preview.php <site-root> <source-parent-ref> <jem-parent-id> <source-child-ref> <jem-child-id>
 */

declare(strict_types=1);

$site = realpath((string) ($argv[1] ?? ''));

if ($site === false || !is_file($site . '/configuration.php') || count($argv) !== 6) {
    fwrite(STDERR, "Provide a Joomla site root and two source/JEM category pairs.\n");
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
\Joomla\CMS\Factory::getApplication()->getLanguage()->load('com_jemmigrator', $site . '/administrator');
$db = $container->get('DatabaseDriver');

$jemSite = $site . '/components/com_jem';
$jemAdmin = $site . '/administrator/components/com_jem';
foreach ([
    $jemSite . '/factory.php',
    $jemSite . '/helpers/helper.php',
    $jemSite . '/helpers/countries.php',
    $jemSite . '/classes/config.class.php',
    $jemSite . '/classes/image.class.php',
    $jemSite . '/classes/eventimagepath.class.php',
    $jemSite . '/classes/venueimagepath.class.php',
    $jemSite . '/classes/categoryimagepath.class.php',
    $jemSite . '/classes/imagepublicationpolicy.class.php',
    $jemSite . '/classes/output.class.php',
    $jemSite . '/classes/user.class.php',
    $jemSite . '/classes/attachment.class.php',
    $jemSite . '/classes/categories.class.php',
    $jemAdmin . '/classes/admin.class.php',
    $jemAdmin . '/helpers/helper.php',
    $jemAdmin . '/helpers/importsecurity.php',
] as $file) {
    require_once $file;
}

\Joomla\CMS\Table\Table::addIncludePath($jemAdmin . '/tables');
$root = dirname(__DIR__) . '/src';
foreach ([
    '/com_jemmigrator/administrator/src/Adapter/ReadOnlyDatabase.php',
    '/com_jemmigrator/administrator/src/Adapter/AbstractAdapter.php',
    '/com_jemmigrator/administrator/src/Service/PayloadBuilder.php',
    '/plg_jemmigrator_jevents/src/Adapter/JeventsAdapter.php',
    '/plg_jem_import/src/Service/BatchNormaliser.php',
    '/plg_jem_import/src/Service/LocalImageSource.php',
    '/plg_jem_import/src/Service/ImageTransfer.php',
    '/plg_jem_import/src/Service/ImportService.php',
] as $file) {
    require_once $root . $file;
}

$adapter = new \JemProject\Plugin\JemMigrator\Jevents\Adapter\JeventsAdapter($db);
$options = [
    'category_mode' => 'manual',
    'parent_catid' => 1,
    'single_catid' => 0,
    'category_map' => [(string) $argv[2] => (int) $argv[3], (string) $argv[4] => (int) $argv[5]],
    'create_venues' => 1,
    'copy_images' => 1,
    'skip_past' => 0,
    'calendars' => [],
];
try {
    $payload = (new \JemProject\Component\JemMigrator\Administrator\Service\PayloadBuilder())->build($adapter, $options);
} catch (\RuntimeException $exception) {
    fwrite(STDERR, 'Preflight blocked: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}
$batch = \Joomla\Plugin\Jem\Import\Service\BatchNormaliser::normalise([
    'format_version' => 2,
    'source' => 'jevents',
    'label' => 'JEvents local preview',
    'dry_run' => true,
    'options' => $options,
    'categories' => $payload['categories'],
    'events' => $payload['events'],
]);
$result = (new \Joomla\Plugin\Jem\Import\Service\ImportService($db, 0))->processBatch($batch);

foreach ($result['categories'] as $category) {
    echo $category['title'] . ' -> ' . $category['target_title'] . ' [' . $category['status'] . ']' . PHP_EOL;
}

foreach ($result['rows'] as $row) {
    echo $row['title'] . ' -> ' . implode(', ', $row['category_targets'])
        . ' [' . $row['status'] . ']' . PHP_EOL;

    if ($row['status'] === 'error') {
        echo '  ' . implode('; ', $row['notes']) . PHP_EOL;
    }
}
