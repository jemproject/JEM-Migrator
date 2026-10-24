<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\View\Source;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Controller\MigrateController;
use JemProject\Component\JemMigrator\Administrator\Service\ImportClient;
use JemProject\Component\JemMigrator\Administrator\Service\SourceRegistry;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $info;

    protected array $tabs = [];

    protected string $activeSource = '';

    protected array $sources = [];

    protected array $preview = [];

    protected array $jemCategories = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $this->tabs = SourceRegistry::tabs();
        $key = $app->input->getCmd('source', '') ?: SourceRegistry::firstAvailable();
        $adapter = SourceRegistry::get($key);

        $this->info = (new ImportClient())->info();
        $this->preview = (array) $app->getUserState(MigrateController::PREVIEW_STATE, []);

        if ($key !== '' && empty($this->tabs[$key]['available'])) {
            throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_SOURCE_NOT_FOUND'), 404);
        }

        $this->activeSource = $key;

        if ($adapter) {
            try {
                $summary = $adapter->getSummary();
                $categories = $adapter->loadCategories();
                $error = '';
            } catch (\Throwable $e) {
                $summary = ['lines' => [], 'calendars' => [], 'occurrences' => 0];
                $categories = [];
                $error = $e->getMessage();
            }

            $this->sources[$key] = [
                'adapter' => $adapter,
                'summary' => $summary,
                'categories' => $categories,
                'error'   => $error,
            ];
        }

        $this->jemCategories = $this->loadJemCategories();

        ToolbarHelper::title(($adapter ? $adapter->getLabel() . ' - ' : '') . Text::_('COM_JEMMIGRATOR'), 'calendar');

        if ($app->getIdentity()->authorise('core.admin', 'com_jemmigrator')) {
            ToolbarHelper::preferences('com_jemmigrator');
        }

        parent::display($tpl);
    }

    /** JEM categories available as destinations (read only). */
    private function loadJemCategories(): array
    {
        $db = Factory::getContainer()->get('DatabaseDriver');

        if (!\in_array($db->getPrefix() . 'jem_categories', $db->getTableList(), true)) {
            return [];
        }

        return $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'parent_id', 'catname', 'level', 'published']))
                ->from($db->quoteName('#__jem_categories'))
                ->where($db->quoteName('id') . ' > 0')
                ->where($db->quoteName('published') . ' IN (0, 1)')
                ->order($db->quoteName('lft'))
        )->loadObjectList();
    }
}
