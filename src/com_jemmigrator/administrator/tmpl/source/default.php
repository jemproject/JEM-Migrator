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
use Joomla\CMS\Uri\Uri;

/** @var \JemProject\Component\JemMigrator\Administrator\View\Source\HtmlView $this */

$action = Route::_('index.php?option=com_jemmigrator');
$info = $this->info;
$ready = $info !== null && !empty($info['compatible']) && !empty($info['ready']);
$preview = $this->preview;
$statusClass = ['new' => 'bg-success', 'created' => 'bg-success', 'existing' => 'bg-primary', 'skipped' => 'bg-secondary', 'error' => 'bg-danger'];
$statusText = [
    'new'      => Text::_('COM_JEMMIGRATOR_STATUS_NEW'),
    'existing' => Text::_('COM_JEMMIGRATOR_STATUS_EXISTING'),
    'skipped'  => Text::_('COM_JEMMIGRATOR_STATUS_SKIPPED'),
    'error'    => Text::_('COM_JEMMIGRATOR_STATUS_ERROR'),
];
?>
<link rel="stylesheet" href="<?php echo $this->escape(Uri::root(true) . '/media/com_jemmigrator/css/category-map.css'); ?>">
<script src="<?php echo $this->escape(Uri::root(true) . '/media/com_jemmigrator/js/category-map.js'); ?>" defer></script>
<?php
$tabs = $this->tabs;
$activeSource = $this->activeSource;
$runsActive = false;
require dirname(__DIR__) . '/navigation.php';
?>

<?php if ($info === null) : ?>
    <div class="alert alert-danger"><?php echo Text::_('COM_JEMMIGRATOR_PLUGIN_MISSING'); ?></div>
<?php elseif (empty($info['compatible'])) : ?>
    <div class="alert alert-danger"><?php echo Text::sprintf('COM_JEMMIGRATOR_PLUGIN_FORMAT', $this->escape(implode(', ', (array) $info['format_versions']))); ?></div>
<?php elseif (empty($info['ready'])) : ?>
    <div class="alert alert-warning"><?php echo $this->escape($info['message']); ?></div>
<?php else : ?>
    <div class="alert alert-success"><?php echo Text::sprintf('COM_JEMMIGRATOR_PLUGIN_READY', $this->escape($info['jem_version'])); ?></div>
<?php endif; ?>

<p><?php echo Text::_('COM_JEMMIGRATOR_INTRO'); ?></p>

<?php if (!$this->sources) : ?>
    <div class="alert alert-info"><?php echo Text::_('COM_JEMMIGRATOR_NONE_FOUND'); ?></div>
<?php endif; ?>

<?php if ($this->sources) : ?>
    <section class="card mb-4">
        <div class="card-header"><h2 class="h5 mb-0"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_TITLE'); ?></h2></div>
        <div class="card-body row g-3">
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_EVENTS'); ?></h3>
                <p class="mb-0"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_EVENTS_DESC'); ?></p>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_CATEGORIES'); ?></h3>
                <p class="mb-0"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_CATEGORIES_DESC'); ?></p>
            </div>
            <div class="col-12 col-lg-4">
                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_VENUES'); ?></h3>
                <p class="mb-0"><?php echo Text::_('COM_JEMMIGRATOR_SCOPE_VENUES_DESC'); ?></p>
            </div>
        </div>
    </section>
<?php endif; ?>

<?php foreach ($this->sources as $key => $source) :
    $adapter = $source['adapter'];
    $summary = $source['summary'];
    $hasPreview = ($preview['source'] ?? '') === $key;
    $opt = $hasPreview ? (array) $preview['options'] : ['category_mode' => 'tree', 'parent_catid' => 1, 'single_catid' => 0, 'category_map' => [], 'create_venues' => 1, 'copy_images' => 1, 'skip_past' => 0, 'calendars' => []];
    $p = 'jm_' . $key . '_';
    $sourceTree = [];

    foreach ((array) $source['categories'] as $category) {
        $sourceTree[] = [
            'ref' => (string) ($category['src_id'] ?? ''),
            'parent_ref' => (string) ($category['src_parent'] ?? ''),
            'title' => (string) ($category['catname'] ?? ''),
        ];
    }

    $targetTree = [];

    foreach ($this->jemCategories as $category) {
        $targetTree[] = [
            'id' => (int) $category->id,
            'parent_id' => (int) $category->parent_id,
            'title' => (string) $category->catname,
        ];
    }

    $mapData = static function (array $value): string {
        return htmlspecialchars(
            (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );
    };
    ?>
    <div class="card mb-4">
        <div class="card-header">
            <h2 class="h4 mb-0"><?php echo $this->escape($adapter->getLabel()); ?> <small class="text-muted"><?php echo $this->escape($adapter->getVersion()); ?></small></h2>
        </div>
        <div class="card-body">
            <?php if ($adapter->getDescription() !== '') : ?>
                <p><?php echo $this->escape($adapter->getDescription()); ?></p>
            <?php endif; ?>
            <?php foreach ((array) $summary['lines'] as $line) : ?>
                <p class="mb-1"><?php echo $this->escape($line); ?></p>
            <?php endforeach; ?>
            <?php if ($source['error'] !== '') : ?>
                <div class="alert alert-danger"><?php echo $this->escape($source['error']); ?></div>
            <?php endif; ?>

            <form action="<?php echo $action; ?>" method="post" class="mt-3">
                <?php if (!empty($summary['calendars'])) : ?>
                    <fieldset class="mb-3">
                        <legend class="h6"><?php echo Text::_('COM_JEMMIGRATOR_CALENDARS'); ?></legend>
                        <?php foreach ($summary['calendars'] as $calendar) : ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="<?php echo $p; ?>calendars[]" id="<?php echo $p; ?>cal<?php echo (int) $calendar['id']; ?>" value="<?php echo (int) $calendar['id']; ?>"
                                    <?php echo !$opt['calendars'] || in_array((int) $calendar['id'], (array) $opt['calendars'], true) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="<?php echo $p; ?>cal<?php echo (int) $calendar['id']; ?>"><?php echo $this->escape($calendar['label']); ?> (<?php echo (int) $calendar['events']; ?>)</label>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>

                <fieldset class="mb-3">
                    <legend class="h6"><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_MODE'); ?></legend>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="<?php echo $p; ?>category_mode" id="<?php echo $p; ?>mode_tree" value="tree" <?php echo $opt['category_mode'] === 'tree' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="<?php echo $p; ?>mode_tree"><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_TREE'); ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="<?php echo $p; ?>category_mode" id="<?php echo $p; ?>mode_manual" value="manual" <?php echo $opt['category_mode'] === 'manual' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="<?php echo $p; ?>mode_manual"><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_MANUAL'); ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="<?php echo $p; ?>category_mode" id="<?php echo $p; ?>mode_single" value="single" <?php echo $opt['category_mode'] === 'single' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="<?php echo $p; ?>mode_single"><?php echo Text::_('COM_JEMMIGRATOR_CATEGORY_SINGLE'); ?></label>
                    </div>
                </fieldset>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="<?php echo $p; ?>parent_catid"><?php echo Text::_('COM_JEMMIGRATOR_PARENT_CATEGORY'); ?></label>
                        <select class="form-select" name="<?php echo $p; ?>parent_catid" id="<?php echo $p; ?>parent_catid">
                            <option value="1"><?php echo Text::_('COM_JEMMIGRATOR_TOP_LEVEL'); ?></option>
                            <?php foreach ($this->jemCategories as $category) : ?>
                                <?php if ((int) $category->id === 1) { continue; } ?>
                                <option value="<?php echo (int) $category->id; ?>" <?php echo (int) $opt['parent_catid'] === (int) $category->id ? 'selected' : ''; ?>>
                                    <?php echo str_repeat('- ', max(0, (int) $category->level - 1)) . $this->escape($category->catname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?php echo Text::_('COM_JEMMIGRATOR_PARENT_CATEGORY_DESC'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="<?php echo $p; ?>single_catid"><?php echo Text::_('COM_JEMMIGRATOR_SINGLE_CATEGORY'); ?></label>
                        <select class="form-select" name="<?php echo $p; ?>single_catid" id="<?php echo $p; ?>single_catid">
                            <option value="0"><?php echo Text::_('JSELECT'); ?></option>
                            <?php foreach ($this->jemCategories as $category) : ?>
                                <?php if ((int) $category->id === 1) { continue; } ?>
                                <option value="<?php echo (int) $category->id; ?>" <?php echo (int) $opt['single_catid'] === (int) $category->id ? 'selected' : ''; ?>>
                                    <?php echo str_repeat('- ', max(0, (int) $category->level - 1)) . $this->escape($category->catname); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <section class="jem-category-map mb-3" data-jem-category-map
                    data-source-tree="<?php echo $mapData($sourceTree); ?>"
                    data-target-tree="<?php echo $mapData($targetTree); ?>"
                    data-preview-categories="<?php echo $mapData($hasPreview ? (array) $preview['categories'] : []); ?>"
                    data-initial-map="<?php echo $mapData((array) ($opt['category_map'] ?? [])); ?>"
                    data-source-label="<?php echo $this->escape($adapter->getLabel()); ?>"
                    data-label-new="<?php echo $this->escape(Text::_('COM_JEMMIGRATOR_MAP_NEW')); ?>"
                    data-label-existing="<?php echo $this->escape(Text::_('COM_JEMMIGRATOR_MAP_EXISTING')); ?>"
                    data-label-inherited="<?php echo $this->escape(Text::_('COM_JEMMIGRATOR_MAP_INHERITED')); ?>"
                    data-label-selected="<?php echo $this->escape(Text::_('COM_JEMMIGRATOR_MAP_SELECTED')); ?>">
                    <input type="hidden" name="<?php echo $p; ?>category_map_json" value="<?php echo $mapData((array) ($opt['category_map'] ?? [])); ?>" data-map-input>
                    <p class="form-text mb-2"><?php echo Text::_('COM_JEMMIGRATOR_MAP_HELP'); ?></p>
                    <div class="jem-category-map__controls">
                        <label for="<?php echo $p; ?>map_target"><?php echo Text::_('COM_JEMMIGRATOR_MAP_TARGET'); ?></label>
                        <select id="<?php echo $p; ?>map_target" class="form-select form-select-sm" data-map-target>
                            <option value="0"><?php echo Text::_('COM_JEMMIGRATOR_MAP_CREATE'); ?></option>
                            <?php foreach ($this->jemCategories as $category) : ?>
                                <?php if ((int) $category->id === 1) { continue; } ?>
                                <option value="<?php echo (int) $category->id; ?>"><?php echo str_repeat('- ', max(0, (int) $category->level - 1)) . $this->escape($category->catname); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="jem-category-map__viewport" data-map-viewport>
                        <div class="jem-category-map__grid">
                            <div class="jem-category-map__panel">
                                <h3 class="h6"><?php echo $this->escape($adapter->getLabel()); ?> — <?php echo Text::_('COM_JEMMIGRATOR_MAP_SOURCE'); ?></h3>
                                <div data-map-source></div>
                            </div>
                            <div class="jem-category-map__middle" aria-hidden="true"><svg data-map-lines></svg></div>
                            <div class="jem-category-map__panel">
                                <h3 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_MAP_JEM_RESULT'); ?></h3>
                                <div data-map-result></div>
                            </div>
                        </div>
                    </div>
                    <p class="form-text mt-2 mb-0"><?php echo Text::_('COM_JEMMIGRATOR_MAP_PREVIEW_NOTICE'); ?></p>
                    <div class="alert alert-warning mt-2 d-none" data-map-dirty><?php echo Text::_('COM_JEMMIGRATOR_MAP_CHANGED'); ?></div>
                </section>

                <div class="form-check">
                    <input type="hidden" name="<?php echo $p; ?>create_venues" value="0">
                    <input class="form-check-input" type="checkbox" name="<?php echo $p; ?>create_venues" id="<?php echo $p; ?>create_venues" value="1" <?php echo !empty($opt['create_venues']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $p; ?>create_venues"><?php echo Text::_('COM_JEMMIGRATOR_CREATE_VENUES'); ?></label>
                </div>
                <div class="form-check mb-3">
                    <input type="hidden" name="<?php echo $p; ?>copy_images" value="0">
                    <input class="form-check-input" type="checkbox" name="<?php echo $p; ?>copy_images" id="<?php echo $p; ?>copy_images" value="1" <?php echo !empty($opt['copy_images']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $p; ?>copy_images"><?php echo Text::_('COM_JEMMIGRATOR_COPY_IMAGES'); ?></label>
                    <div class="form-text"><?php echo Text::_('COM_JEMMIGRATOR_COPY_IMAGES_DESC'); ?></div>
                </div>
                <div class="form-check mb-3">
                    <input type="hidden" name="<?php echo $p; ?>skip_past" value="0">
                    <input class="form-check-input" type="checkbox" name="<?php echo $p; ?>skip_past" id="<?php echo $p; ?>skip_past" value="1" <?php echo !empty($opt['skip_past']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $p; ?>skip_past"><?php echo Text::_('COM_JEMMIGRATOR_SKIP_PAST'); ?></label>
                </div>

                <input type="hidden" name="task" value="migrate.preview">
                <input type="hidden" name="source" value="<?php echo $this->escape($key); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <button type="submit" class="btn btn-primary" <?php echo $ready ? '' : 'disabled'; ?>><?php echo Text::_('COM_JEMMIGRATOR_PREVIEW'); ?></button>
            </form>

            <?php if ($hasPreview) :
                $c = $preview['counts'];
                ?>
                <hr>
                <h3 class="h5"><?php echo Text::_('COM_JEMMIGRATOR_PREVIEW'); ?></h3>
                <p><?php echo Text::sprintf(
                    'COM_JEMMIGRATOR_PREVIEW_SUMMARY',
                    (int) $c['events'], (int) $c['series'], (int) $c['categories_new'], (int) $c['categories_existing'],
                    (int) $c['venues_new'], (int) $c['venues_existing'], (int) $c['skipped_existing'],
                    (int) $c['skipped_past'], (int) $c['skipped_source'], (int) $c['errors']
                ); ?></p>

                <?php if (!empty($preview['categories'])) : ?>
                    <h4 class="h6"><?php echo Text::_('COM_JEMMIGRATOR_CATEGORIES'); ?></h4>
                    <ul class="list-unstyled">
                        <?php foreach ($preview['categories'] as $category) : ?>
                            <li style="padding-left: <?php echo max(0, (int) $category['level'] - 1) * 1.5; ?>rem">
                                <?php echo $this->escape($category['title']); ?>
                                <?php if (!empty($category['target_title']) && $category['target_title'] !== $category['title']) : ?>
                                    &rarr; <?php echo $this->escape($category['target_title']); ?>
                                <?php endif; ?>
                                <span class="badge <?php echo $statusClass[$category['status']] ?? 'bg-secondary'; ?>"><?php echo $this->escape($statusText[$category['status']] ?? $category['status']); ?></span>
                                <?php if (!empty($category['note'])) : ?>
                                    <small class="text-danger"><?php echo $this->escape($category['note']); ?></small>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ((int) $preview['total'] > count($preview['rows'])) : ?>
                    <p class="text-muted"><?php echo Text::sprintf('COM_JEMMIGRATOR_PREVIEW_LIMITED', count($preview['rows']), (int) $preview['total']); ?></p>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_STATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_START'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_END'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_VENUE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_JEM_CATEGORIES'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_SERIES'); ?></th>
                                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('JFIELD_ACCESS_LABEL'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_JEMMIGRATOR_COL_NOTES'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview['rows'] as $row) : ?>
                                <tr>
                                    <td><span class="badge <?php echo $statusClass[$row['status']] ?? 'bg-secondary'; ?>"><?php echo $this->escape($statusText[$row['status']] ?? $row['status']); ?></span></td>
                                    <td><?php echo $this->escape($row['title']); ?></td>
                                    <td class="text-nowrap"><?php echo $this->escape(trim($row['dates'] . ' ' . substr((string) $row['times'], 0, 5))); ?></td>
                                    <td class="text-nowrap"><?php echo $this->escape(trim($row['enddates'] . ' ' . substr((string) $row['endtimes'], 0, 5))); ?></td>
                                    <td><?php echo $this->escape($row['venue']); ?></td>
                                    <td><?php echo $this->escape(implode(', ', (array) ($row['category_targets'] ?? []))); ?></td>
                                    <td><?php echo $row['series_ref'] !== '' ? '&#10003;' : ''; ?></td>
                                    <td><?php echo Text::_([0 => 'JUNPUBLISHED', 1 => 'JPUBLISHED', 2 => 'JARCHIVED'][(int) $row['published']] ?? 'JUNPUBLISHED'); ?></td>
                                    <td><?php echo (int) $row['access']; ?></td>
                                    <td><?php echo $this->escape(implode(' ', array_unique((array) $row['notes']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex gap-2">
                    <a class="btn btn-primary" data-map-import-button href="<?php echo Route::_('index.php?option=com_jemmigrator&view=runs&source=' . rawurlencode($key)); ?>">
                        <?php echo Text::_('COM_JEMMIGRATOR_REVIEW_PLAN'); ?>
                    </a>
                    <form action="<?php echo $action; ?>" method="post">
                        <input type="hidden" name="task" value="migrate.clear">
                        <input type="hidden" name="source" value="<?php echo $this->escape($key); ?>">
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <button type="submit" class="btn btn-secondary"><?php echo Text::_('COM_JEMMIGRATOR_DISCARD'); ?></button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
