<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var array $log A page of persistent run log rows. */
/** @var int $logRunId */
/** @var string $logSource */

$offset = (int) ($log['offset'] ?? 0);
$total = (int) ($log['total'] ?? 0);
$pageSize = 100;
$base = 'index.php?option=com_jemmigrator&view=runs&source=' . rawurlencode($logSource) . '&run_id=' . $logRunId;
$levelClass = ['info' => 'jem-console-info', 'warning' => 'jem-console-warning', 'error' => 'jem-console-error'];
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(\Joomla\CMS\Uri\Uri::root(true) . '/media/com_jemmigrator/css/migration-console.css', ENT_QUOTES, 'UTF-8'); ?>">
<div class="jem-console mb-3" role="log" aria-label="<?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_TITLE'); ?>">
    <?php foreach ((array) ($log['rows'] ?? []) as $entry) : ?>
        <div class="jem-console__line <?php echo $levelClass[$entry['level']] ?? $levelClass['info']; ?>">
            <span class="jem-console__meta">#<?php echo (int) $entry['id']; ?> · <?php echo htmlspecialchars((string) $entry['entity'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) $entry['level'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span><?php echo htmlspecialchars((string) $entry['message'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($total > $pageSize) : ?>
    <nav class="d-flex align-items-center gap-3 mb-3" aria-label="<?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_PAGES'); ?>">
        <?php if ($offset > 0) : ?>
            <a href="<?php echo Route::_($base . '&log_offset=' . max(0, $offset - $pageSize)); ?>"><?php echo Text::_('JPREV'); ?></a>
        <?php endif; ?>
        <span><?php echo Text::sprintf('COM_JEMMIGRATOR_CONSOLE_RANGE', $offset + 1, min($total, $offset + $pageSize), $total); ?></span>
        <?php if ($offset + $pageSize < $total) : ?>
            <a href="<?php echo Route::_($base . '&log_offset=' . ($offset + $pageSize)); ?>"><?php echo Text::_('JNEXT'); ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>
