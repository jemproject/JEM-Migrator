<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class PlgJemImportInstallerScript
{
    public function preflight($type, $parent)
    {
        if (version_compare(PHP_VERSION, '8.1', '<') || version_compare(JVERSION, '5.0', '<')) {
            Factory::getApplication()->enqueueMessage('The JEM import plugin needs Joomla 5.0 or later and PHP 8.1 or later.', 'error');

            return false;
        }

        return true;
    }

    public function postflight($type, $parent)
    {
        // The image ledger is also needed when upgrading a 0.1.1 installation.
        $db = Factory::getContainer()->get('DatabaseDriver');
        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS `#__jem_import_images` ('
            . '`id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
            . '`run_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`batch` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`entity` varchar(20) NOT NULL DEFAULT \'\','
            . '`jem_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`relative_path` varchar(512) NOT NULL DEFAULT \'\','
            . 'PRIMARY KEY (`id`), KEY `idx_run` (`run_id`, `batch`),'
            . 'KEY `idx_owner` (`entity`, `jem_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        )->execute();

        $db->setQuery(
            'CREATE TABLE IF NOT EXISTS `#__jem_import_log` ('
            . '`id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
            . '`run_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`batch` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`level` varchar(10) NOT NULL DEFAULT \'info\','
            . '`entity` varchar(20) NOT NULL DEFAULT \'\','
            . '`jem_id` int(11) unsigned NOT NULL DEFAULT 0,'
            . '`message` varchar(1024) NOT NULL DEFAULT \'\','
            . '`created` datetime NOT NULL,'
            . 'PRIMARY KEY (`id`), KEY `idx_run` (`run_id`, `id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        )->execute();

        if ($type !== 'install') {
            return true;
        }

        // Every event of this plugin checks the JEM permission and the form token itself.
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('jem'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('import'))
        )->execute();

        return true;
    }
}
