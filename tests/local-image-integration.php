<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Local-only image integration check. Usage:
 * php tests/local-image-integration.php <site-root> <event-image> <category-image> <venue-image>
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

$jemSite = $site . '/components/com_jem';
require_once $jemSite . '/factory.php';
require_once $jemSite . '/helpers/helper.php';
require_once $jemSite . '/classes/config.class.php';
require_once $jemSite . '/classes/image.class.php';
require_once $jemSite . '/classes/eventimagepath.class.php';
require_once $jemSite . '/classes/categoryimagepath.class.php';
require_once $jemSite . '/classes/venueimagepath.class.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/LocalImageSource.php';
require_once dirname(__DIR__) . '/src/plg_jem_import/src/Service/ImageTransfer.php';

$transfer = new \Joomla\Plugin\Jem\Import\Service\ImageTransfer();
$cases = [
    ['event', (string) ($argv[2] ?? ''), \JemImageProfilePolicy::EVENT_INTRO],
    ['category', (string) ($argv[3] ?? ''), \JemImageProfilePolicy::CATEGORY],
    ['venue', (string) ($argv[4] ?? ''), \JemImageProfilePolicy::VENUE],
];

foreach ($cases as [$entity, $reference, $profile]) {
    $source = $transfer->inspect($reference, $profile);

    if ($source['status'] !== 'local') {
        throw new RuntimeException($entity . ' image: ' . $source['status']);
    }

    $before = hash_file('sha256', $source['path']);
    $folder = 'migrator-integration-' . bin2hex(random_bytes(5));
    $result = null;

    try {
        $result = $transfer->copy($source['path'], $entity, $folder, $profile);

        if (!is_file($result['path']) || hash_file('sha256', $source['path']) !== $before) {
            throw new RuntimeException($entity . ' copy failed or source changed');
        }

        echo $entity . ': normalised copy and unchanged source verified' . PHP_EOL;
    } finally {
        if ($result !== null) {
            foreach ([$result['path'], $result['thumbnail']] as $path) {
                if (is_file($path)) {
                    \Joomla\Filesystem\File::delete($path);
                }

                $parent = dirname($path);

                if (is_dir($parent)) {
                    rmdir($parent);
                }
            }
        }
    }
}
