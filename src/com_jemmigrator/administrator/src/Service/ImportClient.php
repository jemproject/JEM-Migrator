<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

/**
 * Talks to the JEM import plugin (plg_jem_import). JEM Migrator never writes
 * to JEM tables itself.
 */
final class ImportClient
{
    public const FORMAT_VERSION = 2;

    /**
     * Status of the JEM import plugin, or null when it is not installed or
     * not enabled.
     */
    public function info(): ?array
    {
        if (!PluginHelper::isEnabled('jem', 'import')) {
            return null;
        }

        $info = $this->dispatch('onJemImportInfo', []);

        if (!\is_array($info)) {
            return null;
        }

        $info['compatible'] = \in_array(self::FORMAT_VERSION, (array) ($info['format_versions'] ?? []), true);

        if ($info['compatible'] && (empty($info['features']['run_log']) || empty($info['features']['stop_on_error']))) {
            $info['ready'] = false;
            $info['message'] = Text::_('COM_JEMMIGRATOR_IMPORT_PLUGIN_UPDATE');
        }

        return $info;
    }

    public function batch(array $batch): array
    {
        $batch['format_version'] = self::FORMAT_VERSION;

        return $this->call('onJemImportBatch', ['batch' => $batch]);
    }

    public function undo(int $runId): array
    {
        return $this->call('onJemImportUndo', ['run_id' => $runId]);
    }

    public function stop(int $runId): array
    {
        return $this->call('onJemImportStop', ['run_id' => $runId]);
    }

    public function runs(string $source = ''): array
    {
        return $this->call('onJemImportRuns', ['source' => $source]);
    }

    public function log(int $runId, int $offset = 0, int $limit = 100): array
    {
        return $this->call('onJemImportLog', [
            'run_id' => $runId,
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    private function call(string $name, array $arguments): array
    {
        if (!PluginHelper::isEnabled('jem', 'import')) {
            throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_IMPORT_PLUGIN'));
        }

        $result = $this->dispatch($name, $arguments);

        if (!\is_array($result)) {
            throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_IMPORT_PLUGIN'));
        }

        return $result;
    }

    /**
     * @return mixed
     */
    private function dispatch(string $name, array $arguments)
    {
        PluginHelper::importPlugin('jem', 'import');
        $event = new Event($name, $arguments);
        Factory::getApplication()->getDispatcher()->dispatch($name, $event);

        return $event->getArgument('result');
    }
}
