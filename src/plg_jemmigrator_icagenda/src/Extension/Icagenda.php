<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Plugin\JemMigrator\Icagenda\Extension;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use JemProject\Plugin\JemMigrator\Icagenda\Adapter\IcagendaAdapter;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * Registers the iCagenda adapter with JEM Migrator.
 */
final class Icagenda extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    public static function getSubscribedEvents(): array
    {
        return ['onJemMigratorCollectSources' => 'onJemMigratorCollectSources'];
    }

    public function onJemMigratorCollectSources(Event $event): void
    {
        // The adapter base class belongs to JEM Migrator; without it there is nothing to register.
        if (!class_exists(AbstractAdapter::class)) {
            return;
        }

        $sources = (array) $event->getArgument('sources', []);
        $sources[] = new IcagendaAdapter($this->getDatabase());
        $event->setArgument('sources', $sources);
    }
}
