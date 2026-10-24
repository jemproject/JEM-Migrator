<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Jem\Import\Service\BatchNormaliser;
use Joomla\Plugin\Jem\Import\Service\ImportService;

/**
 * Import service of JEM. External tools (such as JEM Migrator) send data in
 * the JEM import format; only this plugin writes to the JEM tables.
 *
 * Events: onJemImportInfo, onJemImportBatch, onJemImportStop,
 * onJemImportUndo, onJemImportRuns and onJemImportLog.
 * Each sets the argument "result".
 */
final class Import extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    public const MIN_JEM_VERSION = '5.1.0beta1';

    private static bool $jemLoaded = false;

    public static function getSubscribedEvents(): array
    {
        return [
            'onJemImportInfo'  => 'onJemImportInfo',
            'onJemImportBatch' => 'onJemImportBatch',
            'onJemImportStop' => 'onJemImportStop',
            'onJemImportUndo'  => 'onJemImportUndo',
            'onJemImportRuns'  => 'onJemImportRuns',
            'onJemImportLog'   => 'onJemImportLog',
        ];
    }

    public function onJemImportInfo(Event $event): void
    {
        $this->loadLanguage();

        $info = [
            'format_versions' => [1, BatchNormaliser::FORMAT_VERSION],
            'features'        => ['run_log' => true, 'stop_on_error' => true],
            'jem_version'     => $this->getJemVersion(),
            'ready'           => false,
            'message'         => '',
        ];

        try {
            $this->assertAllowed(false);
            $info['ready'] = true;
        } catch (\Throwable $e) {
            $info['message'] = $e->getMessage();
        }

        $event->setArgument('result', $info);
    }

    public function onJemImportBatch(Event $event): void
    {
        $this->loadLanguage();
        $this->assertAllowed(true);
        $this->assertNoImageInput();

        $batch = BatchNormaliser::normalise((array) $event->getArgument('batch', []));
        $event->setArgument('result', $this->getService()->processBatch($batch));
    }

    public function onJemImportUndo(Event $event): void
    {
        $this->loadLanguage();
        $this->assertAllowed(true);

        $event->setArgument('result', $this->getService()->undoRun((int) $event->getArgument('run_id', 0)));
    }

    public function onJemImportStop(Event $event): void
    {
        $this->loadLanguage();
        $this->assertAllowed(true);

        $event->setArgument('result', $this->getService()->stopRun((int) $event->getArgument('run_id', 0)));
    }

    public function onJemImportRuns(Event $event): void
    {
        $this->loadLanguage();
        $this->assertAllowed(false);

        $source = (string) $event->getArgument('source', '');
        $source = preg_match('/^[a-z0-9_]{2,50}$/', $source) ? $source : '';
        $event->setArgument('result', $this->getService()->listRuns($source));
    }

    public function onJemImportLog(Event $event): void
    {
        $this->loadLanguage();
        $this->assertAllowed(false);

        $event->setArgument('result', $this->getService()->listLog(
            (int) $event->getArgument('run_id', 0),
            (int) $event->getArgument('offset', 0),
            (int) $event->getArgument('limit', 100)
        ));
    }

    private function getService(): ImportService
    {
        return new ImportService($this->getDatabase(), (int) $this->getApplication()->getIdentity()->id);
    }

    /**
     * JEM table stores also inspect the HTTP request for image uploads and
     * selections. Image imports use the validated v2 references, never
     * request fields, so reject these side channels before table methods run.
     */
    private function assertNoImageInput(): void
    {
        $input = $this->getApplication()->input;

        foreach (['userfile', 'fulluserfile', 'jform', 'datimage', 'fullimage', 'locimage', 'image', 'removeimage', 'removefullimage'] as $field) {
            if ($input->get($field, null, 'raw') !== null || $input->files->get($field, null, 'array') !== null) {
                throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_INPUT'));
            }
        }
    }

    /**
     * The caller is never trusted: every event checks the client, the JEM
     * permission, a pending ACL migration and (for writes) the form token.
     */
    private function assertAllowed(bool $requireToken): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') || $app->getIdentity()->guest) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $this->loadJem();

        if (!\JemHelperBackend::canManage('jem.tools.manage')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if (\JemAclMigrationStatus::pending()) {
            throw new \RuntimeException(Text::_('COM_JEM_ACL_MIGRATION_PENDING'), 503);
        }

        if ($requireToken && !Session::checkToken('post')) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
    }

    private function loadJem(): void
    {
        if (self::$jemLoaded) {
            return;
        }

        $site  = JPATH_SITE . '/components/com_jem';
        $admin = JPATH_ADMINISTRATOR . '/components/com_jem';

        if (!is_file($site . '/factory.php') || !ComponentHelper::isEnabled('com_jem')) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_JEM_MISSING'), 503);
        }

        $version = $this->getJemVersion();

        if ($version === '' || version_compare($version, self::MIN_JEM_VERSION, '<')) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_JEM_VERSION', $version, self::MIN_JEM_VERSION), 503);
        }

        // Same classes as the JEM backend entry point (administrator/components/com_jem/jem.php).
        require_once $site . '/factory.php';
        require_once $site . '/helpers/helper.php';
        require_once $site . '/helpers/countries.php';
        require_once $site . '/classes/config.class.php';
        require_once $site . '/classes/image.class.php';
        require_once $site . '/classes/eventimagepath.class.php';
        require_once $site . '/classes/venueimagepath.class.php';
        require_once $site . '/classes/categoryimagepath.class.php';
        require_once $site . '/classes/imagepublicationpolicy.class.php';
        require_once $site . '/classes/output.class.php';
        require_once $site . '/classes/user.class.php';
        require_once $site . '/classes/attachment.class.php';
        require_once $site . '/classes/categories.class.php';
        require_once $admin . '/classes/admin.class.php';
        require_once $admin . '/helpers/helper.php';
        require_once $admin . '/helpers/importsecurity.php';

        Table::addIncludePath($admin . '/tables');
        $this->getApplication()->getLanguage()->load('com_jem', $admin);

        self::$jemLoaded = true;
    }

    private function getJemVersion(): string
    {
        $db = $this->getDatabase();
        $cache = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_jem'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        )->loadResult();

        $data = json_decode($cache, true);

        return (string) ($data['version'] ?? '');
    }
}
