<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\View\Progress;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Controller\MigrateController;
use JemProject\Component\JemMigrator\Administrator\Service\ImportClient;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    /** @var array */
    protected $progress = [];

    protected array $runLog = [];

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $this->progress = (array) $app->getUserState(MigrateController::PROGRESS_STATE, []);

        if (empty($this->progress['source'])) {
            $app->redirect(Route::_('index.php?option=com_jemmigrator&view=source', false));

            return;
        }

        if (!empty($this->progress['run_id'])) {
            try {
                $this->runLog = (new ImportClient())->log((int) $this->progress['run_id'], -1, 100);
            } catch (\Throwable $e) {
                $this->progress['error'] = $e->getMessage();
            }
        }

        ToolbarHelper::title(Text::_('COM_JEMMIGRATOR') . ': ' . Text::_('COM_JEMMIGRATOR_PROGRESS'), 'calendar');

        parent::display($tpl);
    }
}
