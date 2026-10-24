<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class Pkg_JemmigratorInstallerScript
{
    private const MIN_PHP = '8.1';
    private const MIN_JOOMLA = '5.0';

    public function preflight($type, $parent)
    {
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<') || version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_JEMMIGRATOR_REQUIREMENTS', self::MIN_JOOMLA, self::MIN_PHP), 'error');

            return false;
        }

        return true;
    }

    public function postflight($type, $parent)
    {
        if ($type !== 'install') {
            return true;
        }

        // Plugins installed by a package are disabled by default.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('jemmigrator'))
                ->where($db->quoteName('element') . ' IN (' . implode(',', array_map([$db, 'quote'], ['jevents', 'dpcalendar', 'icagenda'])) . ')')
        )->execute();

        $import = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('jem'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('import'))
        )->loadResult();

        if ((string) $import !== '1') {
            Factory::getApplication()->enqueueMessage(Text::_('PKG_JEMMIGRATOR_IMPORT_PLUGIN_NEEDED'), 'warning');
        }

        return true;
    }
}
