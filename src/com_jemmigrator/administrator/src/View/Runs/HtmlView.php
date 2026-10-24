<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\View\Runs;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Service\ImportClient;
use JemProject\Component\JemMigrator\Administrator\Service\SourceRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    /** @var array */
    protected $runs = [];

    /** @var string */
    protected $error = '';

    protected array $tabs = [];

    protected string $activeSource = '';

    protected array $preview = [];

    protected array $jem = [];

    protected ?array $importInfo = null;

    protected array $sourceSummary = [];

    protected string $sourceError = '';

    protected array $runLog = [];

    protected int $logRunId = 0;

    protected bool $canManagePlugins = false;

    protected int $importPluginId = 0;

    public function display($tpl = null)
    {
        $this->tabs = SourceRegistry::tabs();
        $this->canManagePlugins = Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_plugins');
        $requested = Factory::getApplication()->input->getCmd('source', '');
        $this->activeSource = $requested !== '' && isset($this->tabs[$requested])
            ? $requested
            : (SourceRegistry::firstDetected() ?: SourceRegistry::firstAvailable());

        $db = Factory::getContainer()->get('DatabaseDriver');
        $this->importPluginId = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('jem'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('import'))
        )->loadResult();
        $manifestCache = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_jem'))
        )->loadResult();
        $manifest = json_decode($manifestCache, true);
        $this->jem = [
            'installed' => $manifestCache !== '' && ComponentHelper::isEnabled('com_jem'),
            'version' => (string) ($manifest['version'] ?? ''),
        ];
        $client = new ImportClient();
        $this->importInfo = $client->info();
        $preview = (array) Factory::getApplication()->getUserState(\JemProject\Component\JemMigrator\Administrator\Controller\MigrateController::PREVIEW_STATE, []);
        $this->preview = ($preview['source'] ?? '') === $this->activeSource ? $preview : [];

        if (!empty($this->tabs[$this->activeSource]['available'])) {
            try {
                $this->sourceSummary = SourceRegistry::get($this->activeSource)->getSummary();
            } catch (\Throwable $e) {
                $this->sourceError = $e->getMessage();
            }
        }

        try {
            $this->runs = $client->runs($this->activeSource);
            $requestedRun = Factory::getApplication()->input->getInt('run_id', 0);

            if (!$requestedRun && $this->runs) {
                $requestedRun = (int) $this->runs[0]['id'];
            }

            foreach ($this->runs as $run) {
                if ((int) $run['id'] === $requestedRun) {
                    $this->logRunId = $requestedRun;
                    $offset = Factory::getApplication()->input->getInt('log_offset', -1);
                    $this->runLog = $client->log($requestedRun, $offset, 100);
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }

        ToolbarHelper::title(Text::_('COM_JEMMIGRATOR') . ': ' . Text::_('COM_JEMMIGRATOR_RUNS'), 'calendar');

        parent::display($tpl);
    }
}
