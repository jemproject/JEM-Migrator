<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\OutputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\File;

/**
 * Plans, validates and writes import batches through the JEM tables, and
 * keeps a record of every row it creates so a run can be undone.
 *
 * Based on the component migration example by JKoelman (JEM issue #2371).
 */
final class ImportService
{
    private const RUNS = '#__jem_import_runs';
    private const RECORDS = '#__jem_import_records';
    private const IMAGES = '#__jem_import_images';
    private const LOG = '#__jem_import_log';

    private DatabaseInterface $db;
    private int $userId;
    private ?array $countries = null;
    private ?array $viewLevels = null;
    private array $users = [];
    private array $targetCategories = [];
    private ImageTransfer $imageTransfer;

    /** @var array<int, array{0: string, 1: int}> Rows created by the current batch */
    private array $written = [];

    /** @var string[] Files created by the current batch, including thumbnails. */
    private array $writtenFiles = [];

    public function __construct(DatabaseInterface $db, int $userId)
    {
        $this->db = $db;
        $this->userId = $userId;
        $this->imageTransfer = new ImageTransfer();
    }

    public function processBatch(array $batch): array
    {
        $this->assertTargetCategories($batch['options']);

        if ($batch['dry_run']) {
            return $this->runBatch($batch, 0, 0, false);
        }

        $runId = $batch['run_id'] > 0 ? $this->resumeRun($batch) : $this->startRun($batch);
        $batchNo = 0;
        $this->written = [];
        $this->writtenFiles = [];

        try {
            $batchNo = $this->nextBatchNumber($runId);
            if ($batchNo === 1) {
                $this->appendLog($runId, 0, 'info', 'run', 0, 'Import started.');
            }
            $this->db->transactionStart();
            $result = $this->runBatch($batch, $runId, $batchNo, true);
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            try {
                $this->db->transactionRollback();
            } catch (\Throwable $ignored) {
            }

            // A rollback alone is not enough: the nested-set tables (JEM
            // categories, Joomla assets) use LOCK TABLES, which ends the
            // transaction in MySQL/MariaDB.
            try {
                $cleanupFailures = $this->compensate($runId, $batchNo);
            } catch (\Throwable $cleanupError) {
                $cleanupFailures = [$cleanupError->getMessage()];
            }
            $message = $cleanupFailures
                ? Text::sprintf('PLG_JEM_IMPORT_ERROR_COMPENSATION', implode(', ', $cleanupFailures))
                : Text::sprintf('PLG_JEM_IMPORT_ERROR_BATCH_ROLLED_BACK', $e->getMessage());

            try {
                $this->setRunState($runId, 'failed', $message);
                $this->appendLog($runId, $batchNo, 'error', 'batch', 0, $message);
            } catch (\Throwable $stateError) {
                $cleanupFailures[] = $stateError->getMessage();
            }

            throw new BatchFailure($message, $runId, !$cleanupFailures, $e);
        }

        try {
            $this->appendBatchLog($runId, $batchNo, (string) $batch['source'], $result);
            $this->addRunCounts($runId, $result['counts']);

            if ($batch['last_batch']) {
                $this->setRunState($runId, 'finished', '');
                $this->appendLog($runId, $batchNo, 'info', 'run', 0, 'Import finished.');
            }
        } catch (\Throwable $e) {
            // JEM rows may already be committed. Never offer an automatic retry.
            try {
                $this->setRunState($runId, 'failed', $e->getMessage());
                $this->appendLog($runId, $batchNo, 'error', 'batch', 0, $e->getMessage());
            } catch (\Throwable $ignored) {
            }

            throw new BatchFailure($e->getMessage(), $runId, false, $e);
        }

        return $result;
    }

    public function undoRun(int $runId): array
    {
        $run = $this->loadRun($runId);

        if (!$run) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_NOT_FOUND'));
        }

        if ($run->state === 'undone') {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_UNDONE'));
        }

        if ($run->state === 'running') {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_RUNNING'));
        }

        // Later runs may reuse categories or venues created by this one.
        if ($this->latestOpenRunId() !== $runId) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_UNDO_ORDER'));
        }

        $db = $this->db;
        $records = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'entity', 'jem_id']))
                ->from($db->quoteName(self::RECORDS))
                ->where($db->quoteName('run_id') . ' = ' . $runId)
                ->where($db->quoteName('created') . ' = 1')
                ->order($db->quoteName('id') . ' DESC')
        )->loadObjectList();

        $byEntity = ['event' => [], 'series' => [], 'venue' => [], 'category' => []];
        foreach ($records as $record) {
            if (isset($byEntity[$record->entity])) {
                $byEntity[$record->entity][] = $record;
            }
        }

        $counts = ['events' => 0, 'series' => 0, 'venues' => 0, 'categories' => 0, 'kept' => 0];
        $removed = [];

        foreach ($byEntity['event'] as $record) {
            $event = $db->setQuery(
                'SELECT id, modified FROM ' . $db->quoteName('#__jem_events') . ' WHERE id = ' . (int) $record->jem_id
            )->loadObject();

            if ($event && $this->wasEdited((string) $event->modified)) {
                $counts['kept']++;
                continue;
            }

            if ($event && $this->count('#__jem_register', 'event', (int) $record->jem_id) > 0) {
                $counts['kept']++;
                continue;
            }

            if ($event) {
                $this->deleteEntity('event', (int) $record->jem_id);
                $counts['events']++;
            }

            $this->deleteOwnedImages($runId, 'event', (int) $record->jem_id);

            $removed[] = (int) $record->id;
        }

        $checks = [
            'series'   => ['#__jem_events', 'series_id'],
            'venue'    => ['#__jem_events', 'locid'],
            'category' => ['#__jem_cats_event_relations', 'catid'],
        ];
        $countKeys = ['series' => 'series', 'venue' => 'venues', 'category' => 'categories'];

        foreach (['series', 'venue', 'category'] as $entity) {
            foreach ($byEntity[$entity] as $record) {
                $id = (int) $record->jem_id;
                $inUse = $this->count($checks[$entity][0], $checks[$entity][1], $id) > 0
                    || ($entity === 'category' && $this->count('#__jem_categories', 'parent_id', $id) > 0);

                if ($inUse) {
                    $counts['kept']++;
                    continue;
                }

                $this->deleteEntity($entity, $id);
                $this->deleteOwnedImages($runId, $entity, $id);
                $counts[$countKeys[$entity]]++;
                $removed[] = (int) $record->id;
            }
        }

        // Kept rows keep their record, so a new run does not import them twice.
        $query = $db->getQuery(true)
            ->delete($db->quoteName(self::RECORDS))
            ->where($db->quoteName('run_id') . ' = ' . $runId);
        $query->where($removed
            ? '(' . $db->quoteName('created') . ' = 0 OR ' . $db->quoteName('id') . ' IN (' . implode(',', $removed) . '))'
            : $db->quoteName('created') . ' = 0');
        $db->setQuery($query)->execute();

        $message = $counts['kept'] ? Text::sprintf('PLG_JEM_IMPORT_UNDO_KEPT', $counts['kept']) : '';
        $this->setRunState($runId, 'undone', $message);
        $this->appendLog($runId, 0, 'info', 'run', 0, 'Import run undone.');

        return ['ok' => true, 'run_id' => $runId, 'counts' => $counts, 'message' => $message];
    }

    public function stopRun(int $runId): array
    {
        $run = $this->loadRun($runId);

        if (!$run || !\in_array($run->state, ['running', 'failed'], true)) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_NOT_FOUND'));
        }

        if ($run->state === 'running') {
            $this->setRunState($runId, 'stopped', '');
            $this->appendLog($runId, 0, 'warning', 'run', 0, 'Import stopped by the administrator.');
        }

        return ['ok' => true, 'run_id' => $runId];
    }

    public function listRuns(string $source = ''): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['r.id', 'r.source', 'r.label', 'r.state', 'r.counts', 'r.message', 'r.created', 'r.finished']))
            ->select($db->quoteName('u.name', 'author'))
            ->from($db->quoteName(self::RUNS, 'r'))
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON u.id = r.created_by')
            ->order($db->quoteName('r.id') . ' DESC');

        if ($source !== '') {
            $query->where($db->quoteName('r.source') . ' = ' . $db->quote($source));
        }

        $latest = $this->latestOpenRunId();
        $runs = [];

        foreach ($db->setQuery($query, 0, 50)->loadObjectList() as $row) {
            $runs[] = [
                'id'       => (int) $row->id,
                'source'   => (string) $row->source,
                'label'    => (string) $row->label,
                'state'    => (string) $row->state,
                'counts'   => json_decode((string) $row->counts, true) ?: [],
                'message'  => (string) $row->message,
                'created'  => (string) $row->created,
                'finished' => (string) $row->finished,
                'author'   => (string) $row->author,
                'can_undo' => (int) $row->id === $latest && $row->state !== 'running',
            ];
        }

        return $runs;
    }

    public function listLog(int $runId, int $offset = 0, int $limit = 100): array
    {
        if ($runId < 1 || !$this->loadRun($runId)) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_NOT_FOUND'));
        }

        $limit = min(200, max(1, $limit));
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'batch', 'level', 'entity', 'jem_id', 'message', 'created']))
            ->from($db->quoteName(self::LOG))
            ->where($db->quoteName('run_id') . ' = ' . $runId)
            ->order($db->quoteName('id'));
        $total = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::LOG))
                ->where($db->quoteName('run_id') . ' = ' . $runId)
        )->loadResult();
        $offset = $offset < 0 ? max(0, $total - $limit) : min($offset, $total);

        return [
            'run_id' => $runId,
            'total' => $total,
            'offset' => $offset,
            'rows' => array_map(static fn ($row): array => (array) $row, $db->setQuery($query, $offset, $limit)->loadObjectList()),
        ];
    }

    /** Persist the result after its batch has committed, not before. */
    private function appendBatchLog(int $runId, int $batchNo, string $source, array $result): void
    {
        foreach ((array) $result['categories'] as $category) {
            $status = (string) $category['status'];
            $this->appendLog(
                $runId,
                $batchNo,
                $status === 'error' ? 'error' : 'info',
                'category',
                (int) $category['jem_id'],
                (string) $category['title'] . ' → ' . (string) $category['target_title'] . ' (' . $status . ')'
            );

            if (!empty($category['note'])) {
                $this->appendLog($runId, $batchNo, 'warning', 'category', (int) $category['jem_id'], (string) $category['note']);
            }
        }

        foreach ((array) $result['venues'] as $venue) {
            $this->appendLog(
                $runId,
                $batchNo,
                'info',
                'venue',
                (int) $venue['jem_id'],
                (string) $venue['name'] . ' (' . (string) $venue['status'] . ')'
            );
        }

        $loggedSeries = [];

        foreach ((array) $result['rows'] as $row) {
            $status = (string) $row['status'];
            $this->appendLog(
                $runId,
                $batchNo,
                $status === 'error' ? 'error' : 'info',
                'event',
                (int) $row['jem_id'],
                (string) $row['title'] . ' — ' . (string) $row['dates'] . ' (' . $status . ')'
            );

            if ($status !== 'skipped') {
                foreach ((array) $row['notes'] as $note) {
                    $this->appendLog($runId, $batchNo, 'warning', 'event', (int) $row['jem_id'], (string) $note);
                }
            }

            $seriesRef = (string) ($row['series_ref'] ?? '');

            if ($status === 'created' && $seriesRef !== '' && !isset($loggedSeries[$seriesRef])) {
                $loggedSeries[$seriesRef] = true;
                $seriesId = $this->findRecord($source, 'series', $seriesRef, '#__jem_event_series');
                $this->appendLog($runId, $batchNo, 'info', 'series', (int) $seriesId, 'Recurring series for ' . (string) $row['title']);
            }
        }

        $this->appendLog($runId, $batchNo, 'info', 'batch', 0, 'Batch ' . $batchNo . ' completed.');
    }

    private function appendLog(int $runId, int $batchNo, string $level, string $entity, int $jemId, string $message): void
    {
        $row = (object) [
            'run_id' => $runId,
            'batch' => max(0, $batchNo),
            'level' => $level,
            'entity' => $entity,
            'jem_id' => max(0, $jemId),
            'message' => mb_substr($message, 0, 1024),
            'created' => Factory::getDate()->toSql(),
        ];
        $this->db->insertObject(self::LOG, $row);
    }

    /* ------------------------------------------------------------------ */

    private function runBatch(array $batch, int $runId, int $batchNo, bool $write): array
    {
        $source = $batch['source'];
        $options = $batch['options'];
        $result = [
            'ok'         => true,
            'run_id'     => $runId,
            'dry_run'    => !$write,
            'rows'       => [],
            'categories' => [],
            'venues'     => [],
            'counts'     => self::emptyCounts(),
        ];

        $categoryMap = $options['category_mode'] !== 'single'
            ? $this->processCategories($batch['categories'], $source, $options, $runId, $batchNo, $write, $result)
            : [];
        $categoryLabels = array_column($result['categories'], 'target_title', 'ref');

        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_jem/tables');
        $venueIds = [];

        foreach ($batch['events'] as $e) {
            $row = [
                'ref'        => $e['ref'],
                'status'     => 'error',
                'jem_id'     => 0,
                'notes'      => $e['notes'],
                'title'      => $e['title'],
                'dates'      => (string) $e['dates'],
                'times'      => (string) $e['times'],
                'enddates'   => (string) $e['enddates'],
                'endtimes'   => (string) $e['endtimes'],
                'venue'      => $e['venue']['name'] ?? '',
                'series_ref' => $e['series_ref'],
                'published'  => $e['published'],
                'access'     => $e['access'],
                'category_targets' => [],
            ];

            $error = $e['errors'] ? implode(' ', $e['errors']) : null;

            if ($error === null && ($existing = $this->findRecord($source, 'event', $e['ref'], '#__jem_events'))) {
                $row['status'] = 'skipped';
                $row['jem_id'] = $existing;
                $row['notes'][] = Text::_('PLG_JEM_IMPORT_NOTE_ALREADY_IMPORTED');
                $result['counts']['skipped_existing']++;
                $result['rows'][] = $row;
                continue;
            }

            $record = [];
            if ($error === null) {
                [$record, $notes, $error] = $this->mapEvent($source, $e);
                $row['notes'] = array_merge($row['notes'], $notes);
                $row['title'] = $record['title'] ?? $row['title'];
            }

            $eventImages = [];
            if ($error === null && $options['copy_images']) {
                $eventImages[\JemImageProfilePolicy::EVENT_INTRO] = $this->planImage(
                    $e['image_intro'], \JemImageProfilePolicy::EVENT_INTRO, $row['notes']
                );
                $eventImages[\JemImageProfilePolicy::EVENT_FULL] = $this->planImage(
                    $e['image_full'], \JemImageProfilePolicy::EVENT_FULL, $row['notes']
                );
            }

            $categoryIds = [];
            if ($error === null) {
                if ($options['category_mode'] === 'single') {
                    $categoryIds[] = $options['single_catid'];
                    $row['category_targets'][] = $this->categoryName($options['single_catid']);
                } else {
                    foreach ($e['category_refs'] as $ref) {
                        $id = \array_key_exists($ref, $categoryMap) ? $categoryMap[$ref] : $this->findRecord($source, 'category', $ref, '#__jem_categories');
                        if ($id !== null) {
                            $categoryIds[] = $id;
                            $row['category_targets'][] = $categoryLabels[$ref] ?? $this->categoryName($id);
                        }
                    }
                }

                if (!$categoryIds) {
                    $error = Text::_('PLG_JEM_IMPORT_ERROR_NO_CATEGORY');
                } elseif (!$this->viewLevelExists((int) $record['access'])) {
                    $error = Text::sprintf('PLG_JEM_IMPORT_ERROR_ACCESS', (int) $record['access']);
                }
            }

            if ($error === null) {
                $check = Table::getInstance('Event', 'JemTable');
                if (!$check->bind($record) || !$check->check()) {
                    $error = (string) $check->getError();
                } else {
                    $missing = \JemImagePublicationPolicy::missingForRecord('event', $check, \JemHelper::config());
                    $missing = $this->unplannedRequiredImages($missing, $eventImages);
                    if ($missing) {
                        $error = \JemImagePublicationPolicy::recordFailureMessage($record['title'], $missing);
                    }
                }
            }

            if ($error !== null) {
                if ($write) {
                    throw new \RuntimeException($row['title'] . ': ' . $error);
                }

                $row['notes'][] = $error;
                $result['counts']['errors']++;
                $result['rows'][] = $row;
                continue;
            }

            $record['locid'] = 0;
            $venue = null;
            $venueImage = '';
            if ($e['venue'] !== null && $options['create_venues']) {
                if ($options['copy_images']) {
                    $venueImage = $this->planImage(
                        $e['venue']['image'], \JemImageProfilePolicy::VENUE, $row['notes']
                    );
                }
                [$venue, $venueNotes] = $this->mapVenue($e['venue'], $venueImage !== '');
                $row['notes'] = array_merge($row['notes'], $venueNotes);
            }

            if ($venue !== null) {
                $key = mb_strtolower($venue['venue']);

                if (!isset($result['venues'][$key])) {
                    $id = $this->findVenueId($venue['venue']);
                    $created = false;

                    if (!$id && $write) {
                        $id = $this->storeVenue($venue, $venueImage, $runId, $batchNo);
                        $this->addRecord($runId, $batchNo, $source, 'venue', $key, $id, true);
                        $created = true;
                    }

                    $status = $id && !$created ? 'existing' : 'new';
                    $venueIds[$key] = $id;
                    $result['venues'][$key] = ['name' => $venue['venue'], 'status' => $status, 'jem_id' => $id];

                    // A venue created by an earlier batch of this source is not counted again.
                    if ($status === 'new' || $this->findRecord($source, 'venue', $key, '#__jem_venues') === null) {
                        $result['counts'][$status === 'new' ? 'venues_new' : 'venues_existing']++;
                    }
                }

                $record['locid'] = (int) ($venueIds[$key] ?? 0);
            }

            $seriesId = null;
            if ($e['series_ref'] !== '') {
                $seriesId = $this->findRecord($source, 'series', $e['series_ref'], '#__jem_event_series');
                if ($seriesId !== null) {
                    $order = $this->nextSeriesOrder($seriesId);
                    $record['series_id'] = $seriesId;
                    $record['series_order'] = $order;
                    $record['alias'] .= '-series-' . $order;
                }
            }

            if ($write) {
                $row['jem_id'] = $this->storeEvent(
                    $record, array_values(array_unique($categoryIds)), $eventImages, $runId, $batchNo
                );
                $this->addRecord($runId, $batchNo, $source, 'event', $e['ref'], $row['jem_id'], true);

                if ($e['series_ref'] !== '' && $seriesId === null) {
                    $seriesId = $this->createSeries($row['jem_id'], $record['title']);
                    $this->addRecord($runId, $batchNo, $source, 'series', $e['series_ref'], $seriesId, true);
                    $result['counts']['series']++;
                }
            } elseif ($e['series_ref'] !== '' && $seriesId === null) {
                $result['counts']['series']++;
            }

            $row['status'] = $write ? 'created' : 'new';
            $result['counts']['events']++;
            $result['rows'][] = $row;
        }

        return $result;
    }

    /**
     * Categories arrive parents first. Returns source ref => JEM id (0 for a
     * category that a dry run would create).
     */
    private function processCategories(array $categories, string $source, array $options, int $runId, int $batchNo, bool $write, array &$result): array
    {
        $map = [];
        $levels = [];

        foreach ($categories as $c) {
            if ($c['ref'] === '' || $c['title'] === '') {
                continue;
            }

            $imageNotes = [];
            $categoryImage = $options['copy_images']
                ? $this->planImage($c['image'], \JemImageProfilePolicy::CATEGORY, $imageNotes)
                : '';

            $level = $c['parent_ref'] !== '' && isset($levels[$c['parent_ref']]) ? $levels[$c['parent_ref']] + 1 : 1;
            $levels[$c['ref']] = $level;
            $existing = $this->findRecord($source, 'category', $c['ref'], '#__jem_categories');
            $mapped = (int) ($options['category_map'][$c['ref']] ?? 0);

            if ($existing !== null) {
                if ($mapped > 0 && $mapped !== $existing) {
                    throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_CATEGORY_REMAP', $c['title']));
                }

                $map[$c['ref']] = $existing;
                $result['categories'][] = [
                    'ref' => $c['ref'], 'parent_ref' => $c['parent_ref'], 'title' => $c['title'],
                    'level' => $level, 'status' => 'existing', 'jem_id' => $existing,
                    'target_title' => $this->categoryName($existing),
                ];
                $result['counts']['categories_existing']++;
                continue;
            }

            if ($mapped > 0) {
                $map[$c['ref']] = $mapped;

                if ($write) {
                    $this->addRecord($runId, $batchNo, $source, 'category', $c['ref'], $mapped, false);
                }

                $result['categories'][] = [
                    'ref' => $c['ref'], 'parent_ref' => $c['parent_ref'], 'title' => $c['title'],
                    'level' => $level, 'status' => 'existing', 'jem_id' => $mapped,
                    'target_title' => $this->categoryName($mapped),
                ];
                $result['counts']['categories_existing']++;
                continue;
            }

            $parentId = $options['parent_catid'];
            if ($c['parent_ref'] !== '') {
                $parentId = \array_key_exists($c['parent_ref'], $map)
                    ? $map[$c['parent_ref']]
                    : ($this->findRecord($source, 'category', $c['parent_ref'], '#__jem_categories') ?? $options['parent_catid']);
            }

            $alias = OutputFilter::stringURLSafe($c['alias'] !== '' ? $c['alias'] : $c['title']) ?: $source . '-' . substr(hash('sha1', $c['ref']), 0, 8);
            $found = $parentId > 0 ? $this->findCategoryId($alias, $parentId) : 0;

            if (!$found) {
                try {
                    $this->categoryData($c, $alias, max(1, $parentId), $categoryImage !== '');
                } catch (\RuntimeException $exception) {
                    if ($write) {
                        throw $exception;
                    }

                    // Events of this category get "no category" unless they have another one.
                    $result['categories'][] = [
                        'ref' => $c['ref'], 'parent_ref' => $c['parent_ref'], 'title' => $c['title'],
                        'level' => $level, 'status' => 'error', 'jem_id' => 0,
                        'target_title' => $c['title'], 'note' => $exception->getMessage(),
                    ];
                    continue;
                }
            }

            if ($found) {
                $map[$c['ref']] = $found;
                if ($write) {
                    $this->addRecord($runId, $batchNo, $source, 'category', $c['ref'], $found, false);
                }
                $status = 'existing';
                $result['counts']['categories_existing']++;
            } else {
                $map[$c['ref']] = $write
                    ? $this->storeCategory($c, $alias, $parentId, $categoryImage, $runId, $batchNo)
                    : 0;
                if ($write) {
                    $this->addRecord($runId, $batchNo, $source, 'category', $c['ref'], $map[$c['ref']], true);
                }
                $status = 'new';
                $result['counts']['categories_new']++;
            }

            $result['categories'][] = [
                'ref' => $c['ref'], 'parent_ref' => $c['parent_ref'], 'title' => $c['title'],
                'level' => $level, 'status' => $status, 'jem_id' => $map[$c['ref']],
                'target_title' => $status === 'existing' ? $this->categoryName($map[$c['ref']]) : $c['title'],
                'note' => implode(' ', $imageNotes),
            ];
        }

        return $map;
    }

    /**
     * @return array{0: array, 1: string[], 2: ?string}  JEM record, notes, error
     */
    private function mapEvent(string $source, array $e): array
    {
        $notes = [];
        $title = trim(strip_tags(html_entity_decode($e['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($title === '') {
            return [[], $notes, Text::_('PLG_JEM_IMPORT_ERROR_TITLE')];
        }

        if (mb_strlen($title) > 100) {
            $title = rtrim(mb_substr($title, 0, 99)) . '…';
            $notes[] = Text::_('PLG_JEM_IMPORT_NOTE_TITLE_SHORTENED');
        }

        $description = $e['description'];
        $url = $this->extractUrl($e['url']);
        if ($url !== '' && strpos($description, $url) === false) {
            $link = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $description .= ($description !== '' ? "\n" : '') . '<p><a href="' . $link . '">' . $link . '</a></p>';
        }

        $validUser = $this->userExists($e['created_by']);
        if (!$validUser) {
            $notes[] = Text::_('PLG_JEM_IMPORT_NOTE_AUTHOR');
        }

        $enddates = $e['enddates'] === $e['dates'] ? null : $e['enddates'];
        $record = [
            'id'                => 0,
            'title'             => $title,
            'alias'             => OutputFilter::stringURLSafe($title) ?: $source . '-' . substr(hash('sha1', $e['ref']), 0, 8),
            'dates'             => $e['dates'],
            'enddates'          => $enddates,
            'times'             => $e['times'],
            'endtimes'          => $e['endtimes'],
            'timezone_mode'     => 'joomla',
            'timezone'          => '',
            'introtext'         => $description,
            'fulltext'          => '',
            'created_by_alias'  => '',
            'published'         => $e['published'],
            'access'            => $e['access'],
            'created'           => $e['created'],
            'publish_up'        => $e['created'] ?? Factory::getDate()->toSql(),
            'created_by'        => $validUser ? $e['created_by'] : 0,
            'language'          => '*',
            'attribs'           => '{}',
            'metadata'          => json_encode([
                'robots'            => '',
                'author'            => '',
                'jem_import_source' => $source,
                'jem_import_key'    => hash('sha256', $e['ref']),
            ]),
            'recurrence_type'   => 0,
            'recurrence_number' => 0,
        ];

        try {
            $record = \JemImportSecurityHelper::sanitiseRecord($record, 'jem_events', $e['ref']);
        } catch (\RuntimeException $exception) {
            return [$record, $notes, $exception->getMessage()];
        }

        return [$record, $notes, null];
    }

    /**
     * Validated in the preview as well, so an invalid venue never stops an
     * import: the event is imported without it, with a note.
     *
     * @return array{0: ?array, 1: string[]}  JEM venue fields (null = not imported), notes
     */
    private function mapVenue(array $v, bool $imagePlanned = false): array
    {
        $notes = [];
        $country = $this->countryCode($v['country']);

        if ($v['country'] !== '' && $country === '') {
            $notes[] = Text::sprintf('PLG_JEM_IMPORT_NOTE_COUNTRY', $v['country']);
        }

        $venue = [
            'venue'      => $v['name'],
            'street'     => $v['street'],
            'postalCode' => $v['postal_code'],
            'city'       => $v['city'],
            'state'      => $v['state'],
            'country'    => $country,
            'latitude'   => $v['latitude'],
            'longitude'  => $v['longitude'],
            'url'        => $v['url'],
        ];

        try {
            $data = \JemImportSecurityHelper::sanitiseRecord($venue + ['published' => 1], 'jem_venues');
            $missing = \JemImagePublicationPolicy::missingForRecord('venue', $data, \JemHelper::config());
            $missing = $this->unplannedRequiredImages(
                $missing, $imagePlanned ? [\JemImageProfilePolicy::VENUE => true] : []
            );
            if ($missing) {
                throw new \RuntimeException(\JemImagePublicationPolicy::recordFailureMessage($v['name'], $missing));
            }
        } catch (\RuntimeException $exception) {
            $notes[] = Text::sprintf('PLG_JEM_IMPORT_NOTE_VENUE_SKIPPED', $v['name'], $exception->getMessage());

            return [null, $notes];
        }

        return [$venue, $notes];
    }

    /**
     * @param string[] $notes
     */
    private function planImage(string $reference, string $profile, array &$notes): string
    {
        if ($reference === '') {
            return '';
        }

        $source = $this->imageTransfer->inspect($reference, $profile);

        if ($source['status'] === 'local') {
            return $source['path'];
        }

        $messages = [
            'external' => 'PLG_JEM_IMPORT_NOTE_IMAGE_EXTERNAL',
            'missing' => 'PLG_JEM_IMPORT_NOTE_IMAGE_MISSING',
            'unsupported' => 'PLG_JEM_IMPORT_NOTE_IMAGE_UNSUPPORTED',
            'invalid' => 'PLG_JEM_IMPORT_ERROR_IMAGE_COPY',
        ];
        $notes[] = Text::_($messages[$source['status']] ?? 'PLG_JEM_IMPORT_NOTE_IMAGE_UNSUPPORTED');

        return '';
    }

    /**
     * @param string[] $missing
     * @param array<string, string|bool> $planned
     * @return string[]
     */
    private function unplannedRequiredImages(array $missing, array $planned): array
    {
        return array_values(array_filter($missing, static function (string $profile) use ($planned): bool {
            return empty($planned[$profile]);
        }));
    }

    /* ---- writes ------------------------------------------------------- */

    private function categoryData(array $category, string $alias, int $parentId, bool $imagePlanned = false): array
    {
        if (!$this->viewLevelExists($category['access'])) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_ACCESS', $category['access']));
        }

        $data = \JemImportSecurityHelper::sanitiseRecord([
            'id'          => 0,
            'parent_id'   => $parentId,
            'catname'     => $category['title'],
            'title'       => $category['title'],
            'alias'       => $alias,
            'description' => $category['description'],
            'access'      => $category['access'],
            'published'   => $category['published'],
            'color'       => $category['color'],
            'language'    => $category['language'],
        ], 'jem_categories');

        $missing = \JemImagePublicationPolicy::missingForRecord('category', $data, \JemHelper::config());
        $missing = $this->unplannedRequiredImages(
            $missing, $imagePlanned ? [\JemImageProfilePolicy::CATEGORY => true] : []
        );
        if ($missing) {
            throw new \RuntimeException(\JemImagePublicationPolicy::recordFailureMessage($category['title'], $missing));
        }

        return $data;
    }

    private function storeCategory(array $category, string $alias, int $parentId, string $imageSource, int $runId, int $batchNo): int
    {
        $table = Table::getInstance('Category', 'JemTable');
        $table->setLocation($parentId, 'last-child');
        $data = $this->categoryData($category, $alias, $parentId, $imageSource !== '');
        $published = $data['published'];

        if ($imageSource !== '') {
            $data['published'] = 0;
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_CATEGORY', $category['title'], $table->getError()));
        }

        $this->written[] = ['category', (int) $table->id];

        if ($imageSource !== '') {
            $folder = \JemCategoryImagePath::configuredFolderFromCategory($table);
            $table->image = $this->copyPlannedImage(
                $imageSource, 'category', $folder, \JemImageProfilePolicy::CATEGORY,
                $runId, $batchNo, (int) $table->id
            );
            $table->image_path = $folder;
            $table->published = $published;

            if (!$table->check() || !$table->store()) {
                throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_CATEGORY', $category['title'], $table->getError()));
            }
        }

        return (int) $table->id;
    }

    private function storeVenue(array $venue, string $imageSource, int $runId, int $batchNo): int
    {
        $table = Table::getInstance('Venue', 'JemTable');
        $data = \JemImportSecurityHelper::sanitiseRecord($venue + [
            'id'        => 0,
            'published' => 1,
            'map'       => 0,
            'attribs'   => '{}',
        ], 'jem_venues');
        $published = $data['published'];

        if ($imageSource !== '') {
            $data['published'] = 0;
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_VENUE', $venue['venue'], $table->getError()));
        }

        $this->written[] = ['venue', (int) $table->id];

        if ($imageSource !== '') {
            $folder = \JemVenueImagePath::configuredFolderFromVenue($table);
            $table->locimage = $this->copyPlannedImage(
                $imageSource, 'venue', $folder, \JemImageProfilePolicy::VENUE,
                $runId, $batchNo, (int) $table->id
            );
            $table->image_path = $folder;
            $table->published = $published;

            if (!$table->check() || !$table->store()) {
                throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_VENUE', $venue['venue'], $table->getError()));
            }
        }

        return (int) $table->id;
    }

    private function storeEvent(array $record, array $categoryIds, array $images, int $runId, int $batchNo): int
    {
        $table = Table::getInstance('Event', 'JemTable');
        $published = $record['published'];
        $hasImages = !empty($images[\JemImageProfilePolicy::EVENT_INTRO])
            || !empty($images[\JemImageProfilePolicy::EVENT_FULL]);

        if ($hasImages) {
            $record['published'] = 0;
        }

        if (!$table->bind($record) || !$table->check() || !$table->store()) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_EVENT', $record['title'], $record['dates'], $table->getError()));
        }

        $this->written[] = ['event', (int) $table->id];

        foreach ($categoryIds as $ordering => $categoryId) {
            $relation = (object) ['catid' => (int) $categoryId, 'itemid' => (int) $table->id, 'ordering' => $ordering];
            $this->db->insertObject('#__jem_cats_event_relations', $relation);
        }

        if ($hasImages) {
            $pathRecord = (object) array_merge($record, [
                'id' => (int) $table->id,
                'cats' => $categoryIds,
                'import_source' => (string) ((json_decode((string) $record['metadata'], true) ?: [])['jem_import_source'] ?? ''),
                'import_profile' => 'migrator',
            ]);
            $folder = \JemEventImagePath::configuredFolderFromEvent($pathRecord);
            $intro = (string) ($images[\JemImageProfilePolicy::EVENT_INTRO] ?? '');
            $full = (string) ($images[\JemImageProfilePolicy::EVENT_FULL] ?? '');

            if ($intro !== '') {
                $table->datimage = $this->copyPlannedImage(
                    $intro, 'event', $folder, \JemImageProfilePolicy::EVENT_INTRO,
                    $runId, $batchNo, (int) $table->id
                );
            }

            if ($full !== '') {
                $table->fullimage = $this->copyPlannedImage(
                    $full, 'event', $folder, \JemImageProfilePolicy::EVENT_FULL,
                    $runId, $batchNo, (int) $table->id
                );
            }

            $table->image_path = $folder;
            $table->published = $published;

            if (!$table->check() || !$table->store()) {
                throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_EVENT', $record['title'], $record['dates'], $table->getError()));
            }

            // This second store is part of the import, not an editor change.
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__jem_events'))
                    ->set($this->db->quoteName('modified') . ' = NULL')
                    ->where($this->db->quoteName('id') . ' = ' . (int) $table->id)
            )->execute();
        }

        return (int) $table->id;
    }

    private function copyPlannedImage(
        string $source,
        string $entity,
        string $folder,
        string $profile,
        int $runId,
        int $batchNo,
        int $ownerId
    ): string {
        $copied = $this->imageTransfer->copy($source, $entity, $folder, $profile);

        foreach ([$copied['path'], $copied['thumbnail']] as $path) {
            if (!is_file($path)) {
                continue;
            }

            // Keep an in-memory list even if the SQL transaction is rolled
            // back and therefore discards the persistent image ledger.
            $this->writtenFiles[] = $path;
            $relative = $this->relativeJemImagePath($path);
            $entry = (object) [
                'run_id' => $runId,
                'batch' => $batchNo,
                'entity' => $entity,
                'jem_id' => $ownerId,
                'relative_path' => $relative,
            ];
            $this->db->insertObject(self::IMAGES, $entry);
        }

        return $copied['filename'];
    }

    private function relativeJemImagePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', realpath(JPATH_ROOT) ?: JPATH_ROOT), '/') . '/';
        $path = str_replace('\\', '/', $path);

        if (strncmp($path, $root, strlen($root)) !== 0) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        $relative = substr($path, strlen($root));

        if (!preg_match('~^images/jem/(?:events|venues|categories)/(?:small/)?[a-zA-Z0-9_./-]+$~', $relative)
            || strpos($relative, '..') !== false
            || strlen($relative) > 512) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        return $relative;
    }

    private function deleteOwnedImages(int $runId, string $entity, int $ownerId): void
    {
        $db = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'relative_path']))
                ->from($db->quoteName(self::IMAGES))
                ->where($db->quoteName('run_id') . ' = ' . $runId)
                ->where($db->quoteName('entity') . ' = ' . $db->quote($entity))
                ->where($db->quoteName('jem_id') . ' = ' . $ownerId)
        )->loadObjectList();

        foreach ($rows as $row) {
            $this->deleteImportFile((string) $row->relative_path);
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::IMAGES))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->id)
            )->execute();
        }
    }

    private function deleteImportFile(string $relative): void
    {
        if (!preg_match('~^images/jem/(events|venues|categories)/(small/)?([a-zA-Z0-9_./-]+)$~', $relative, $matches)
            || strpos($relative, '..') !== false) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        $remainder = $matches[3];
        $filename = basename($remainder);
        $folder = dirname($remainder);
        $folder = $folder === '.' ? '' : str_replace('\\', '/', $folder);
        $fields = [
            'events' => ['#__jem_events', ['datimage', 'fullimage']],
            'venues' => ['#__jem_venues', ['locimage']],
            'categories' => ['#__jem_categories', ['image']],
        ];
        [$table, $columns] = $fields[$matches[1]];
        $db = $this->db;
        $imageConditions = [];

        foreach ($columns as $column) {
            $imageConditions[] = $db->quoteName($column) . ' = ' . $db->quote($filename);
        }

        $referenced = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName($table))
                ->where('(' . implode(' OR ', $imageConditions) . ')')
                ->where($db->quoteName('image_path') . ' = ' . $db->quote($folder))
        )->loadResult();

        if ($referenced > 0) {
            return;
        }

        $root = realpath(JPATH_ROOT . '/images/jem/' . $matches[1]);
        $path = realpath(JPATH_ROOT . '/' . $relative);

        if ($path === false) {
            return;
        }

        if ($root === false || strncmp(
            str_replace('\\', '/', $path),
            rtrim(str_replace('\\', '/', $root), '/') . '/',
            strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1
        ) !== 0 || !File::delete($path)) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }
    }

    private function createSeries(int $rootEventId, string $title): int
    {
        $db = $this->db;
        $series = (object) [
            'root_event_id' => $rootEventId,
            'title'         => $title,
            'series_type'   => 'custom',
            'created'       => Factory::getDate()->toSql(),
            'created_by'    => $this->userId,
            'published'     => 1,
        ];
        $db->insertObject('#__jem_event_series', $series, 'id');
        $this->written[] = ['series', (int) $series->id];

        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__jem_events'))
                ->set($db->quoteName('series_id') . ' = ' . (int) $series->id)
                ->set($db->quoteName('series_order') . ' = 1')
                ->where($db->quoteName('id') . ' = ' . $rootEventId)
        )->execute();

        return (int) $series->id;
    }

    private function deleteEntity(string $entity, int $id): void
    {
        $deleted = true;

        switch ($entity) {
            case 'event':
                $deleted = Table::getInstance('Event', 'JemTable')->delete($id);
                break;

            case 'series':
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__jem_event_series') . ' WHERE id = ' . $id)->execute();
                break;

            case 'venue':
                $deleted = Table::getInstance('Venue', 'JemTable')->delete($id);
                break;

            case 'category':
                // Nested::delete() derives the asset name from the loaded row.
                $category = Table::getInstance('Category', 'JemTable');
                if ($category->load($id)) {
                    $deleted = $category->delete($id);
                }
                break;
        }

        if (!$deleted) {
            throw new \RuntimeException(Text::sprintf('PLG_JEM_IMPORT_ERROR_DELETE', $entity, $id));
        }
    }

    /**
     * Remove what a failed batch created, newest first.
     *
     * @return string[] Rows that still need manual inspection.
     */
    private function compensate(int $runId, int $batchNo): array
    {
        Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_jem/tables');
        $failures = [];

        foreach (array_reverse($this->written) as [$entity, $id]) {
            try {
                if ($this->entityExists($entity, $id)) {
                    $this->deleteEntity($entity, $id);
                }
            } catch (\Throwable $exception) {
                $failures[] = $entity . ' #' . $id;
            }
        }

        $this->written = [];

        foreach (array_reverse($this->writtenFiles) as $path) {
            try {
                $this->deleteImportFile($this->relativeJemImagePath($path));
            } catch (\Throwable $exception) {
                $failures[] = 'image file';
            }
        }

        $this->writtenFiles = [];

        if (!$failures) {
            $db = $this->db;
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::RECORDS))
                    ->where($db->quoteName('run_id') . ' = ' . $runId)
                    ->where($db->quoteName('batch') . ' = ' . $batchNo)
            )->execute();
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName(self::IMAGES))
                    ->where($db->quoteName('run_id') . ' = ' . $runId)
                    ->where($db->quoteName('batch') . ' = ' . $batchNo)
            )->execute();
        }

        return $failures;
    }

    private function entityExists(string $entity, int $id): bool
    {
        $tables = [
            'event' => '#__jem_events',
            'series' => '#__jem_event_series',
            'venue' => '#__jem_venues',
            'category' => '#__jem_categories',
        ];

        return isset($tables[$entity]) && $this->count($tables[$entity], 'id', $id) > 0;
    }

    /* ---- runs and records --------------------------------------------- */

    private function startRun(array $batch): int
    {
        $run = (object) [
            'source'         => $batch['source'],
            'label'          => $batch['label'],
            'format_version' => $batch['format_version'],
            'options'        => json_encode($batch['options']),
            'state'          => 'running',
            'counts'         => json_encode(self::emptyCounts()),
            'message'        => '',
            'created'        => Factory::getDate()->toSql(),
            'created_by'     => $this->userId,
        ];
        $this->db->insertObject(self::RUNS, $run, 'id');

        return (int) $run->id;
    }

    private function resumeRun(array $batch): int
    {
        $run = $this->loadRun($batch['run_id']);
        $storedOptions = json_decode((string) ($run->options ?? ''), true) ?: [];
        $storedOptions += ['category_map' => []];
        $currentOptions = $batch['options'];
        ksort($storedOptions);
        ksort($currentOptions);

        if (!$run || $run->source !== $batch['source'] || $run->state !== 'running'
            || (int) $run->format_version !== $batch['format_version']
            || $storedOptions !== $currentOptions) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_RUN_NOT_FOUND'));
        }

        return (int) $run->id;
    }

    private function loadRun(int $runId): ?object
    {
        return $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName(self::RUNS) . ' WHERE id = ' . $runId
        )->loadObject() ?: null;
    }

    private function latestOpenRunId(): int
    {
        return (int) $this->db->setQuery(
            'SELECT MAX(id) FROM ' . $this->db->quoteName(self::RUNS) . ' WHERE state <> ' . $this->db->quote('undone')
        )->loadResult();
    }

    private function nextBatchNumber(int $runId): int
    {
        return 1 + (int) $this->db->setQuery(
            'SELECT MAX(batch) FROM ' . $this->db->quoteName(self::RECORDS) . ' WHERE run_id = ' . $runId
        )->loadResult();
    }

    private function setRunState(int $runId, string $state, string $message): void
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->update($db->quoteName(self::RUNS))
            ->set($db->quoteName('state') . ' = ' . $db->quote($state))
            ->set($db->quoteName('message') . ' = ' . $db->quote(mb_substr($message, 0, 1024)))
            ->where($db->quoteName('id') . ' = ' . $runId);

        if ($state !== 'running') {
            $query->set($db->quoteName('finished') . ' = ' . $db->quote(Factory::getDate()->toSql()));
        }

        $db->setQuery($query)->execute();
    }

    private function addRunCounts(int $runId, array $counts): void
    {
        $run = $this->loadRun($runId);
        $total = json_decode((string) ($run->counts ?? ''), true) ?: self::emptyCounts();

        foreach ($counts as $key => $value) {
            $total[$key] = (int) ($total[$key] ?? 0) + (int) $value;
        }

        $db = $this->db;
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName(self::RUNS))
                ->set($db->quoteName('counts') . ' = ' . $db->quote(json_encode($total)))
                ->where($db->quoteName('id') . ' = ' . $runId)
        )->execute();
    }

    private function addRecord(int $runId, int $batchNo, string $source, string $entity, string $ref, int $jemId, bool $created): void
    {
        $record = (object) [
            'run_id'     => $runId,
            'batch'      => $batchNo,
            'source'     => $source,
            'entity'     => $entity,
            'source_ref' => hash('sha256', $ref),
            'jem_id'     => $jemId,
            'created'    => $created ? 1 : 0,
        ];
        $this->db->insertObject(self::RECORDS, $record);
    }

    /**
     * JEM id of an earlier imported record that still exists.
     */
    private function findRecord(string $source, string $entity, string $ref, string $table): ?int
    {
        $db = $this->db;
        $id = (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('r.jem_id'))
                ->from($db->quoteName(self::RECORDS, 'r'))
                ->join('INNER', $db->quoteName($table, 't') . ' ON t.id = r.jem_id')
                ->where($db->quoteName('r.source') . ' = ' . $db->quote($source))
                ->where($db->quoteName('r.entity') . ' = ' . $db->quote($entity))
                ->where($db->quoteName('r.source_ref') . ' = ' . $db->quote(hash('sha256', $ref)))
                ->order($db->quoteName('r.id') . ' DESC'),
            0,
            1
        )->loadResult();

        return $id > 0 ? $id : null;
    }

    /* ---- lookups ------------------------------------------------------ */

    private function assertTargetCategories(array $options): void
    {
        if ($options['category_mode'] === 'single') {
            if ($options['single_catid'] <= 1 || !$this->targetCategory($options['single_catid'])) {
                throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_SINGLE_CATEGORY'));
            }

            return;
        }

        if (($options['parent_catid'] === 1 && !$this->count('#__jem_categories', 'id', 1))
            || ($options['parent_catid'] !== 1 && !$this->targetCategory($options['parent_catid']))) {
            throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_PARENT_CATEGORY'));
        }

        foreach ($options['category_map'] as $targetId) {
            if (!$this->targetCategory((int) $targetId)) {
                throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_CATEGORY_MAP'));
            }
        }
    }

    private function targetCategory(int $id): ?object
    {
        if ($id <= 1) {
            return null;
        }

        if (!\array_key_exists($id, $this->targetCategories)) {
            $db = $this->db;
            $this->targetCategories[$id] = $db->setQuery(
                $db->getQuery(true)
                    ->select($db->quoteName(['id', 'catname', 'published']))
                    ->from($db->quoteName('#__jem_categories'))
                    ->where($db->quoteName('id') . ' = ' . $id)
                    ->where($db->quoteName('published') . ' IN (0, 1)')
            )->loadObject() ?: null;
        }

        return $this->targetCategories[$id];
    }

    private function categoryName(int $id): string
    {
        return (string) ($this->targetCategory($id)->catname ?? '');
    }

    private function findCategoryId(string $alias, int $parentId): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__jem_categories'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote($alias))
                ->where($db->quoteName('parent_id') . ' = ' . $parentId),
            0,
            1
        )->loadResult();
    }

    private function findVenueId(string $name): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__jem_venues'))
                ->where('LOWER(' . $db->quoteName('venue') . ') = ' . $db->quote(mb_strtolower($name)))
                ->order($db->quoteName('id')),
            0,
            1
        )->loadResult();
    }

    private function nextSeriesOrder(int $seriesId): int
    {
        return 1 + (int) $this->db->setQuery(
            'SELECT MAX(series_order) FROM ' . $this->db->quoteName('#__jem_events') . ' WHERE series_id = ' . $seriesId
        )->loadResult();
    }

    private function count(string $table, string $column, int $value): int
    {
        $db = $this->db;

        return (int) $db->setQuery(
            'SELECT COUNT(*) FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName($column) . ' = ' . $value
        )->loadResult();
    }

    private function userExists(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!isset($this->users[$userId])) {
            $this->users[$userId] = $this->count('#__users', 'id', $userId) > 0;
        }

        return $this->users[$userId];
    }

    private function viewLevelExists(int $id): bool
    {
        if ($this->viewLevels === null) {
            $this->viewLevels = array_map('intval', $this->db->setQuery(
                'SELECT id FROM ' . $this->db->quoteName('#__viewlevels')
            )->loadColumn());
        }

        return \in_array($id, $this->viewLevels, true);
    }

    /**
     * JEM stores the ISO 3166 alpha-2 code. Accepts that code, the alpha-3
     * code or the English country name (as in #__jem_countries).
     */
    private function countryCode(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if ($this->countries === null) {
            $this->countries = [];
            foreach ($this->db->setQuery('SELECT iso2, iso3, name FROM ' . $this->db->quoteName('#__jem_countries'))->loadObjectList() as $row) {
                $iso2 = strtoupper((string) $row->iso2);
                $this->countries[$iso2] = $iso2;
                $this->countries[strtoupper((string) $row->iso3)] = $iso2;
                $this->countries[mb_strtoupper((string) $row->name)] = $iso2;
            }
        }

        return $this->countries[mb_strtoupper($value)] ?? '';
    }

    private function extractUrl(string $value): string
    {
        if (preg_match('#href="([^"]+)"#i', $value, $m)) {
            $value = $m[1];
        }

        $value = trim(strip_tags($value));

        return preg_match('#^https?://#i', $value) ? $value : '';
    }

    private function wasEdited(string $modified): bool
    {
        return $modified !== '' && strpos($modified, '0000') !== 0;
    }

    private static function emptyCounts(): array
    {
        return [
            'events'              => 0,
            'series'              => 0,
            'skipped_existing'    => 0,
            'errors'              => 0,
            'categories_new'      => 0,
            'categories_existing' => 0,
            'venues_new'          => 0,
            'venues_existing'     => 0,
        ];
    }
}
