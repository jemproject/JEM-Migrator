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

/** @var \JemProject\Component\JemMigrator\Administrator\View\Runs\HtmlView $this */

$action = Route::_('index.php?option=com_jemmigrator');
$stateClass = ['running' => 'bg-warning text-dark', 'finished' => 'bg-success', 'failed' => 'bg-danger', 'stopped' => 'bg-warning text-dark', 'undone' => 'bg-secondary'];
$tab = $this->tabs[$this->activeSource] ?? null;
$preview = $this->preview;
$counts = (array) ($preview['counts'] ?? []);
$options = (array) ($preview['options'] ?? []);
$categoryModes = [
    'tree' => 'COM_JEMMIGRATOR_CATEGORY_TREE',
    'manual' => 'COM_JEMMIGRATOR_CATEGORY_MANUAL',
    'single' => 'COM_JEMMIGRATOR_CATEGORY_SINGLE',
];
$pluginReady = $this->importInfo !== null && !empty($this->importInfo['compatible']) && !empty($this->importInfo['ready']);
$preflightReady = $tab && $tab['available'] && $this->sourceError === '' && $this->jem['installed'] && $pluginReady
    && $preview && ((int) ($counts['events'] ?? 0) > 0 || (int) ($counts['categories_new'] ?? 0) > 0)
    && (int) ($counts['errors'] ?? 0) === 0;
?>
<?php
$tabs = $this->tabs;
$activeSource = $this->activeSource;
$runsActive = true;
require dirname(__DIR__) . '/navigation.php';
?>

<h2 class="h4"><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_TITLE'); ?></h2>
<p><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_DESC'); ?></p>
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_SOURCE'); ?></h3>
                <p class="mb-1"><?php echo $tab ? $this->escape($tab['label'] . ' ' . $tab['version']) : Text::_('COM_JEMMIGRATOR_NONE_FOUND'); ?></p>
                <?php foreach ((array) ($this->sourceSummary['lines'] ?? []) as $line) : ?>
                    <p class="small mb-1"><?php echo $this->escape($line); ?></p>
                <?php endforeach; ?>
                <?php if ($this->sourceError !== '') : ?>
                    <p class="text-danger mb-1"><?php echo $this->escape($this->sourceError); ?></p>
                <?php endif; ?>
                <span class="badge <?php echo $tab && $tab['detected'] ? 'bg-success' : 'bg-danger'; ?>">
                    <?php echo Text::_($tab && $tab['detected'] ? 'COM_JEMMIGRATOR_CHECK_OK' : 'COM_JEMMIGRATOR_CHECK_MISSING'); ?>
                </span>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_JEM'); ?></h3>
                <p class="mb-1"><?php echo $this->escape($this->jem['version'] ?: '—'); ?></p>
                <span class="badge <?php echo $this->jem['installed'] ? 'bg-success' : 'bg-danger'; ?>">
                    <?php echo Text::_($this->jem['installed'] ? 'COM_JEMMIGRATOR_CHECK_OK' : 'COM_JEMMIGRATOR_CHECK_MISSING'); ?>
                </span>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_ADAPTER'); ?></h3>
                <p class="mb-1">
                    <?php echo Text::_($tab && $tab['plugin_enabled'] ? 'COM_JEMMIGRATOR_CHECK_ENABLED' : 'COM_JEMMIGRATOR_CHECK_DISABLED'); ?>
                </p>
                <?php if ($tab && !$tab['plugin_enabled'] && $tab['plugin_id'] && $this->canManagePlugins) : ?>
                    <a href="<?php echo Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . (int) $tab['plugin_id']); ?>">
                        <?php echo Text::_('COM_JEMMIGRATOR_ENABLE_ADAPTER'); ?>
                    </a>
                <?php elseif ($tab && !$tab['plugin_enabled']) : ?>
                    <span class="text-body-secondary"><?php echo Text::_('COM_JEMMIGRATOR_ASK_ADMIN_ADAPTER'); ?></span>
                <?php elseif ($tab && $tab['detected'] && $tab['schema_compatible'] === false) : ?>
                    <span class="text-danger"><?php echo Text::_('COM_JEMMIGRATOR_SOURCE_SCHEMA_INCOMPATIBLE'); ?></span>
                <?php elseif ($tab && $tab['detected'] && !$tab['available']) : ?>
                    <span class="text-danger"><?php echo Text::_('COM_JEMMIGRATOR_ADAPTER_UNAVAILABLE'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <hr>
        <p class="mb-1"><?php echo Text::_('COM_JEMMIGRATOR_PREFLIGHT_IMPORT_PLUGIN'); ?>:
            <span class="badge <?php echo $pluginReady ? 'bg-success' : 'bg-danger'; ?>">
                <?php echo Text::_($pluginReady ? 'COM_JEMMIGRATOR_CHECK_OK' : 'COM_JEMMIGRATOR_CHECK_MISSING'); ?>
            </span>
        </p>
        <?php if (!$pluginReady && $this->importPluginId && $this->canManagePlugins) : ?>
            <a href="<?php echo Route::_('index.php?option=com_plugins&task=plugin.edit&extension_id=' . $this->importPluginId); ?>">
                <?php echo Text::_('COM_JEMMIGRATOR_ENABLE_IMPORT_PLUGIN'); ?>
            </a>
        <?php endif; ?>
        <?php if ($this->importInfo !== null && !$pluginReady && !empty($this->importInfo['message'])) : ?>
            <p class="text-danger mb-0"><?php echo $this->escape($this->importInfo['message']); ?></p>
        <?php endif; ?>
    </div>
</div>

<section class="card mb-4">
    <div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_JEMMIGRATOR_PLAN_TITLE'); ?></h2></div>
    <div class="card-body">
        <?php if ($preview) : ?>
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-4"><strong><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_EVENTS'); ?>:</strong> <?php echo (int) ($counts['events'] ?? 0); ?></div>
                <div class="col-12 col-md-4"><strong><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_CATEGORIES'); ?>:</strong> <?php echo (int) ($counts['categories_new'] ?? 0); ?> <?php echo Text::_('COM_JEMMIGRATOR_PLAN_NEW'); ?>, <?php echo (int) ($counts['categories_existing'] ?? 0); ?> <?php echo Text::_('COM_JEMMIGRATOR_PLAN_REUSED'); ?></div>
                <div class="col-12 col-md-4"><strong><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_VENUES'); ?>:</strong> <?php echo (int) ($counts['venues_new'] ?? 0); ?> <?php echo Text::_('COM_JEMMIGRATOR_PLAN_NEW'); ?>, <?php echo (int) ($counts['venues_existing'] ?? 0); ?> <?php echo Text::_('COM_JEMMIGRATOR_PLAN_REUSED'); ?></div>
            </div>
            <p class="mb-1"><strong><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_MODE'); ?>:</strong>
                <?php echo Text::_($categoryModes[$options['category_mode'] ?? 'tree'] ?? $categoryModes['tree']); ?>
            </p>
            <p class="mb-2">
                <strong><?php echo Text::_('COM_JEMMIGRATOR_CREATE_VENUES'); ?>:</strong> <?php echo Text::_(!empty($options['create_venues']) ? 'JYES' : 'JNO'); ?> ·
                <strong><?php echo Text::_('COM_JEMMIGRATOR_EVENT_IMAGES'); ?>:</strong> <?php echo Text::_(!empty($options['copy_event_images']) ? 'JYES' : 'JNO'); ?> ·
                <strong><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_IMAGES'); ?>:</strong> <?php echo Text::_(!empty($options['copy_category_images']) ? 'JYES' : 'JNO'); ?> ·
                <strong><?php echo Text::_('COM_JEMMIGRATOR_VENUE_IMAGES'); ?>:</strong> <?php echo Text::_(!empty($options['copy_venue_images']) ? 'JYES' : 'JNO'); ?> ·
                <strong><?php echo Text::_('COM_JEMMIGRATOR_SKIP_PAST'); ?>:</strong> <?php echo Text::_(!empty($options['skip_past']) ? 'JYES' : 'JNO'); ?>
            </p>
            <p class="mb-2"><?php echo Text::sprintf('COM_JEMMIGRATOR_PLAN_CHECKS',
                (int) ($counts['skipped_existing'] ?? 0), (int) ($counts['skipped_past'] ?? 0),
                (int) ($counts['skipped_source'] ?? 0), (int) ($counts['warnings'] ?? 0),
                (int) ($counts['errors'] ?? 0)
            ); ?></p>
            <div class="alert <?php echo $preflightReady ? 'alert-success' : 'alert-danger'; ?>">
                <?php echo Text::_($preflightReady ? 'COM_JEMMIGRATOR_PREFLIGHT_PASSED' : 'COM_JEMMIGRATOR_PREFLIGHT_FAILED'); ?>
            </div>
            <p class="text-warning-emphasis small mb-0"><?php echo Text::_('COM_JEMMIGRATOR_ACL_SCOPE_NOTICE'); ?></p>
        <?php else : ?>
            <p class="mb-2"><?php echo Text::_('COM_JEMMIGRATOR_PLAN_NOT_READY'); ?></p>
        <?php endif; ?>
        <?php if ($tab && $tab['available']) : ?>
            <a class="btn btn-outline-primary me-2" href="<?php echo Route::_('index.php?option=com_jemmigrator&view=source&source=' . rawurlencode($activeSource)); ?>">
                <?php echo Text::_('COM_JEMMIGRATOR_CONFIGURE_PREVIEW'); ?>
            </a>
        <?php endif; ?>
        <?php if ($preview) : ?>
            <form action="<?php echo $action; ?>" method="post" class="d-inline-block">
                <input type="hidden" name="task" value="migrate.start">
                <input type="hidden" name="source" value="<?php echo $this->escape($activeSource); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="btn btn-success" <?php echo $preflightReady ? '' : 'disabled'; ?>><?php echo Text::_('COM_JEMMIGRATOR_START_MIGRATION'); ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>

<h2 class="h4"><?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_TITLE'); ?></h2>
<?php if ($this->runLog) :
    $log = $this->runLog;
    $logRunId = $this->logRunId;
    $logSource = $activeSource;
    require dirname(__DIR__) . '/console.php';
else : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_JEMMIGRATOR_CONSOLE_EMPTY'); ?></div>
<?php endif; ?>

<h2 class="h4 mt-4"><?php echo Text::_('COM_JEMMIGRATOR_RUNS'); ?></h2>
<p><?php echo Text::_('COM_JEMMIGRATOR_RUNS_DESC'); ?></p>
<?php if ($activeSource !== '') : ?>
    <p class="text-body-secondary"><?php echo Text::sprintf('COM_JEMMIGRATOR_RUNS_FILTER', $this->escape($tabs[$activeSource]['label'])); ?></p>
<?php endif; ?>

<?php if ($this->error !== '') : ?>
    <div class="alert alert-danger"><?php echo $this->escape($this->error); ?></div>
<?php elseif (!$this->runs) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_JEMMIGRATOR_RUNS_NONE'); ?></div>
<?php else : ?>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_SOURCE'); ?></th>
                    <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_RESULT'); ?></th>
                    <th scope="col"><?php echo Text::_('JDATE'); ?></th>
                    <th scope="col"><?php echo Text::_('JAUTHOR'); ?></th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->runs as $run) :
                    $c = (array) $run['counts'] + ['events' => 0, 'series' => 0, 'categories_new' => 0, 'venues_new' => 0, 'skipped_existing' => 0, 'errors' => 0];
                    ?>
                    <tr>
                        <td><a href="<?php echo Route::_('index.php?option=com_jemmigrator&view=runs&source=' . rawurlencode($activeSource) . '&run_id=' . (int) $run['id']); ?>"><?php echo (int) $run['id']; ?></a></td>
                        <td><?php echo $this->escape($run['label']); ?></td>
                        <td><span class="badge <?php echo $stateClass[$run['state']] ?? 'bg-secondary'; ?>"><?php echo $this->escape(Text::_('COM_JEMMIGRATOR_RUN_STATE_' . strtoupper($run['state']))); ?></span></td>
                        <td>
                            <?php echo Text::sprintf('COM_JEMMIGRATOR_RUN_COUNTS', (int) $c['events'], (int) $c['series'], (int) $c['categories_new'], (int) $c['venues_new'], (int) $c['skipped_existing'], (int) $c['errors']); ?>
                            <?php if ($run['message'] !== '') : ?>
                                <br><small class="text-muted"><?php echo $this->escape($run['message']); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?php echo HTMLHelper::_('date', $run['created'], Text::_('DATE_FORMAT_LC5')); ?></td>
                        <td><?php echo $this->escape($run['author']); ?></td>
                        <td>
                            <?php if ($run['can_undo']) : ?>
                                <form action="<?php echo $action; ?>" method="post" onsubmit="return confirm(<?php echo $this->escape(json_encode(Text::_('COM_JEMMIGRATOR_UNDO_CONFIRM'))); ?>);">
                                    <input type="hidden" name="task" value="migrate.undo">
                                    <input type="hidden" name="run_id" value="<?php echo (int) $run['id']; ?>">
                                    <input type="hidden" name="source" value="<?php echo $this->escape($activeSource); ?>">
                                    <?php echo HTMLHelper::_('form.token'); ?>
                                    <button type="submit" class="btn btn-sm btn-danger"><?php echo Text::_('COM_JEMMIGRATOR_UNDO'); ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
