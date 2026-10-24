<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array $tabs Supported source tabs. */
/** @var string $activeSource Source selected for the current workspace or run list. */
/** @var bool $runsActive True on the import-run page. */
?>
<ul class="nav nav-tabs mb-3" aria-label="<?php echo Text::_('COM_JEMMIGRATOR_SOURCES'); ?>">
    <?php foreach ($tabs as $key => $tab) : ?>
        <li class="nav-item">
            <?php if ($tab['available']) : ?>
                <a class="nav-link <?php echo !$runsActive && $activeSource === $key ? 'active' : ''; ?>"
                    <?php echo !$runsActive && $activeSource === $key ? 'aria-current="page"' : ''; ?>
                    href="<?php echo Route::_('index.php?option=com_jemmigrator&view=source&source=' . rawurlencode($key)); ?>">
                    <?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php elseif ($tab['detected']) : ?>
                <a class="nav-link text-body-secondary" href="<?php echo Route::_('index.php?option=com_jemmigrator&view=runs&source=' . rawurlencode($key)); ?>"
                    title="<?php echo Text::_('COM_JEMMIGRATOR_SOURCE_READ_ONLY'); ?>">
                    <?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?>
                    <small>(<?php echo Text::_('COM_JEMMIGRATOR_READ_ONLY'); ?>)</small>
                </a>
            <?php else : ?>
                <span class="nav-link disabled text-body-secondary" aria-disabled="true"
                    title="<?php echo Text::_('COM_JEMMIGRATOR_SOURCE_UNAVAILABLE'); ?>">
                    <?php echo htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8'); ?>
                </span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $runsActive ? 'active' : ''; ?>"
            <?php echo $runsActive ? 'aria-current="page"' : ''; ?>
            href="<?php echo Route::_('index.php?option=com_jemmigrator&view=runs' . ($activeSource !== '' ? '&source=' . rawurlencode($activeSource) : '')); ?>">
            <?php echo Text::_('COM_JEMMIGRATOR_RUNS'); ?>
        </a>
    </li>
</ul>
