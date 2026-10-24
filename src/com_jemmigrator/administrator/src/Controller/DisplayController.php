<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    protected $default_view = 'source';

    public function display($cachable = false, $urlparams = [])
    {
        $view = $this->input->getCmd('view', $this->default_view);

        if ($view === 'sources') {
            $view = 'source';
            $this->input->set('view', $view);
        }

        if (!\in_array($view, ['source', 'progress', 'runs'], true)) {
            $this->input->set('view', $this->default_view);
        }

        return parent::display(false, $urlparams);
    }
}
