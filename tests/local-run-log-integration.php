<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Creates and removes one empty local test run. No JEM content is imported.
 * Usage: php tests/local-run-log-integration.php <test-site-root>
 */

declare(strict_types=1);

$site = realpath((string) ($argv[1] ?? ''));

if ($site === false || !is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Provide a local Joomla test site root.\n");
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
$db = $container->get('DatabaseDriver');

foreach ([
    '/components/com_jem/factory.php',
    '/components/com_jem/helpers/helper.php',
    '/administrator/components/com_jem/helpers/importsecurity.php',
] as $file) {
    require_once $site . $file;
}

require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/BatchNormaliser.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/LocalImageSource.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/ImageTransfer.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/VenueIdentity.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/ImportService.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/BatchFailure.php';

$service = new \Joomla\Plugin\Jem\Import\Service\ImportService($db, 0);
$batch = \Joomla\Plugin\Jem\Import\Service\BatchNormaliser::normalise([
    'format_version' => 2,
    'source' => 'jemmigrator_test',
    'label' => 'Temporary console integration test',
    'dry_run' => false,
    'last_batch' => true,
    'options' => [
        'category_mode' => 'tree',
        'parent_catid' => 1,
        'single_catid' => 0,
        'category_map' => [],
        'create_venues' => 0,
        'copy_images' => 0,
    ],
    'categories' => [],
    'events' => [],
]);
$runId = 0;
$runIds = [];

try {
    $result = $service->processBatch($batch);
    $runId = (int) $result['run_id'];
    $runIds[] = $runId;
    $log = $service->listLog($runId, -1, 100);

    if ((int) $log['total'] < 3 || count($log['rows']) < 3) {
        throw new RuntimeException('Expected start, batch and finish log lines.');
    }

    echo 'PASS empty run #' . $runId . ' wrote ' . $log['total'] . " console lines.\n";

    $badBatch = $batch;
    $badBatch['events'] = \Joomla\Plugin\Jem\Import\Service\BatchNormaliser::normalise([
        'format_version' => 2,
        'source' => 'jemmigrator_test',
        'options' => $batch['options'],
        'events' => [[
            'ref' => 'invalid-local-date',
            'title' => 'Invalid local pilot event',
            'dates' => 'not-a-date',
            'category_refs' => [],
        ]],
    ])['events'];

    try {
        $unexpected = $service->processBatch($badBatch);
        $runIds[] = (int) $unexpected['run_id'];
        throw new RuntimeException('An invalid event was imported instead of stopping.');
    } catch (\Joomla\Plugin\Jem\Import\Service\BatchFailure $failure) {
        $failedRunId = $failure->getRunId();
        $runIds[] = $failedRunId;
        $failedLog = $service->listLog($failedRunId, -1, 100);
        $levels = array_column($failedLog['rows'], 'level');

        if (!in_array('error', $levels, true)) {
            throw new RuntimeException('The stopped run did not log an error.');
        }

        echo 'PASS invalid event stopped run #' . $failedRunId . ' with an error line.' . PHP_EOL;
    }
} catch (\Joomla\Plugin\Jem\Import\Service\BatchFailure $failure) {
    $runId = $failure->getRunId();
    $runIds[] = $runId;
    throw $failure;
} finally {
    foreach (array_unique($runIds) as $runId) {
        $count = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__jem_import_runs'))
                ->where($db->quoteName('id') . ' = ' . $runId)
                ->where($db->quoteName('source') . ' = ' . $db->quote('jemmigrator_test'))
        )->loadResult();

        if ($count !== 1) {
            throw new RuntimeException('Refusing cleanup: test run identity did not match.');
        }

        foreach (['#__jem_import_records', '#__jem_import_images'] as $table) {
            $owned = (int) $db->setQuery(
                $db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($db->quoteName($table))
                    ->where($db->quoteName('run_id') . ' = ' . $runId)
            )->loadResult();

            if ($owned !== 0) {
                throw new RuntimeException('Refusing cleanup: test run owns imported data.');
            }
        }

        foreach (['#__jem_import_log', '#__jem_import_runs'] as $table) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName($table === '#__jem_import_runs' ? 'id' : 'run_id') . ' = ' . $runId)
            )->execute();
        }

        echo 'PASS temporary run and console lines removed.' . PHP_EOL;
    }
}
