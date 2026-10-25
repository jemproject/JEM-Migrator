<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Service;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\Event;

/**
 * Adapters are plugins of the group "jemmigrator". Each one adds its adapter
 * to the "sources" argument of the event onJemMigratorCollectSources.
 */
final class SourceRegistry
{
    private const SUPPORTED = [
        'jevents' => 'JEvents',
        'dpcalendar' => 'DPCalendar',
        'icagenda' => 'iCagenda',
    ];

    /** @var AbstractAdapter[]|null */
    private static ?array $sources = null;

    /**
     * @return AbstractAdapter[] keyed by source key
     */
    public static function all(): array
    {
        if (self::$sources !== null) {
            return self::$sources;
        }

        PluginHelper::importPlugin('jemmigrator');
        $event = new Event('onJemMigratorCollectSources', ['sources' => []]);
        Factory::getApplication()->getDispatcher()->dispatch('onJemMigratorCollectSources', $event);

        self::$sources = [];
        foreach ((array) $event->getArgument('sources', []) as $adapter) {
            if ($adapter instanceof AbstractAdapter && preg_match('/^[a-z0-9_]{2,50}$/', $adapter->getSource())) {
                self::$sources[$adapter->getSource()] = $adapter;
            }
        }

        ksort(self::$sources);

        return self::$sources;
    }

    public static function get(string $source): ?AbstractAdapter
    {
        return self::all()[$source] ?? null;
    }

    /** Show supported sources even when their adapter or component is unavailable. */
    public static function tabs(): array
    {
        $registered = self::all();
        $labels = self::SUPPORTED;
        $db = Factory::getContainer()->get('DatabaseDriver');
        $extensions = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['extension_id', 'type', 'folder', 'element', 'enabled', 'manifest_cache']))
                ->from($db->quoteName('#__extensions'))
                ->where('(' . $db->quoteName('type') . ' = ' . $db->quote('component')
                    . ' AND ' . $db->quoteName('element') . ' IN ('
                    . implode(', ', array_map(static fn ($key) => $db->quote('com_' . $key), array_keys(self::SUPPORTED))) . '))'
                    . ' OR (' . $db->quoteName('type') . ' = ' . $db->quote('plugin')
                    . ' AND ' . $db->quoteName('folder') . ' = ' . $db->quote('jemmigrator') . ')')
        )->loadObjectList();
        $installed = [];

        foreach ($extensions as $extension) {
            $key = $extension->type === 'component'
                ? substr((string) $extension->element, 4)
                : (string) $extension->element;
            $installed[$key][$extension->type] = $extension;
        }

        foreach ($registered as $key => $adapter) {
            $labels[$key] = $adapter->getLabel();
        }

        $tabs = [];

        foreach ($labels as $key => $label) {
            $available = false;
            $schemaCompatible = null;

            try {
                if (isset($registered[$key])) {
                    $schemaCompatible = $registered[$key]->isAvailable();
                    $available = $schemaCompatible;
                }
            } catch (\Throwable $e) {
                // A broken source adapter must not hide the other sources.
            }

            $component = $installed[$key]['component'] ?? null;
            $plugin = $installed[$key]['plugin'] ?? null;
            $manifest = $component ? json_decode((string) $component->manifest_cache, true) : null;
            $detected = $component !== null && (int) $component->enabled === 1;
            $pluginEnabled = $plugin !== null && (int) $plugin->enabled === 1;
            $tabs[$key] = [
                'label' => $label,
                'available' => $available && $detected && $pluginEnabled,
                'schema_compatible' => $schemaCompatible,
                'detected' => $detected,
                'version' => (string) ($manifest['version'] ?? ''),
                'plugin_installed' => $plugin !== null,
                'plugin_enabled' => $pluginEnabled,
                'plugin_id' => $plugin ? (int) $plugin->extension_id : 0,
            ];
        }

        return $tabs;
    }

    public static function firstAvailable(): string
    {
        foreach (self::tabs() as $key => $tab) {
            if ($tab['available']) {
                return $key;
            }
        }

        return '';
    }

    public static function firstDetected(): string
    {
        foreach (self::tabs() as $key => $tab) {
            if ($tab['detected']) {
                return $key;
            }
        }

        return '';
    }
}
