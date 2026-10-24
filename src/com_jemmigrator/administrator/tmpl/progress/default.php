<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

\defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \JemProject\Component\JemMigrator\Administrator\View\Progress\HtmlView $this */

$progress = $this->progress;
$total = max(0, (int) $progress['total']);
$offset = min($total, (int) $progress['offset']);
$percent = $total > 0 ? (int) floor($offset * 100 / $total) : 0;
$hasError = $progress['error'] !== '';
$c = (array) $progress['counts'] + ['events' => 0, 'series' => 0, 'skipped_existing' => 0, 'errors' => 0];
$action = Route::_('index.php?option=com_jemmigrator');

if (!$hasError) {
    // Each batch is a separate request, so hosting time limits are not reached.
    $this->getDocument()->getWebAssetManager()->addInlineScript(
        "window.addEventListener('load', function () { setTimeout(function () { document.getElementById('jm-step-form').submit(); }, 900); });"
    );
}
?>
<h2 class="h4"><?php echo Text::sprintf('COM_JEMMIGRATOR_PROGRESS_TITLE', $this->escape($progress['label'])); ?></h2>

<div class="progress mb-3" role="progressbar" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo Text::_('COM_JEMMIGRATOR_PROGRESS'); ?>">
    <div class="progress-bar" style="width: <?php echo $percent; ?>%"><?php echo $percent; ?>%</div>
</div>

<p><?php echo Text::sprintf('COM_JEMMIGRATOR_PROGRESS_STATUS', $offset, $total, (int) $c['events'], (int) $c['series'], (int) $c['skipped_existing'], (int) $c['errors']); ?></p>

<?php if ($hasError) : ?>
    <div class="alert alert-danger"><?php echo $this->escape($progress['error']); ?></div>
    <p><?php echo Text::_('COM_JEMMIGRATOR_PROGRESS_ERROR_HELP'); ?></p>
<?php endif; ?>

<h3 class="h5"><?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_TITLE'); ?></h3>
<?php if ($this->runLog) :
    $log = $this->runLog;
    $logRunId = (int) $progress['run_id'];
    $logSource = (string) $progress['source'];
    require dirname(__DIR__) . '/console.php';
else : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_EMPTY'); ?></div>
<?php endif; ?>

<div class="d-flex gap-2">
    <form action="<?php echo $action; ?>" method="post" id="jm-step-form">
        <input type="hidden" name="task" value="migrate.step">
        <?php echo HTMLHelper::_('form.token'); ?>
        <?php if (!$hasError) : ?>
            <noscript><button type="submit" class="btn btn-primary"><?php echo Text::_('COM_JEMMIGRATOR_CONTINUE'); ?></button></noscript>
        <?php endif; ?>
    </form>
    <form action="<?php echo $action; ?>" method="post">
        <input type="hidden" name="task" value="migrate.cancel">
        <?php echo HTMLHelper::_('form.token'); ?>
        <button type="submit" class="btn btn-secondary"><?php echo Text::_('COM_JEMMIGRATOR_STOP'); ?></button>
    </form>
</div>
