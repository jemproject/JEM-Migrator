<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Controller;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use JemProject\Component\JemMigrator\Administrator\Service\ImportClient;
use JemProject\Component\JemMigrator\Administrator\Service\PayloadBuilder;
use JemProject\Component\JemMigrator\Administrator\Service\SourceRegistry;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Plugin\Jem\Import\Service\BatchFailure;

/**
 * Preview, import in batches, cancel and undo. Every task needs the form
 * token; the JEM import plugin checks the JEM permissions again.
 */
class MigrateController extends BaseController
{
    public const PREVIEW_STATE = 'com_jemmigrator.preview';
    public const PROGRESS_STATE = 'com_jemmigrator.progress';
    private const PREVIEW_ROWS = 200;
    private const DRY_RUN_CHUNK = 500;

    private static bool $loggerAdded = false;

    public function preview(): void
    {
        $this->checkToken();

        $source = $this->input->post->getCmd('source', '');
        $adapter = SourceRegistry::get($source);

        if (!$adapter || empty(SourceRegistry::tabs()[$source]['available'])) {
            $this->redirectTo('source', Text::_('COM_JEMMIGRATOR_ERROR_SOURCE_NOT_FOUND'), 'warning');
            return;
        }

        try {
            $options = $this->getOptions($source);

            if (!$options['migrate_events'] && !$options['migrate_categories']) {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_EMPTY_SCOPE'));
            }

            if (!$options['migrate_categories'] && $options['category_mode'] !== 'single') {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_CATEGORY_SCOPE'));
            }

            if (!$options['migrate_events'] && $options['category_mode'] === 'single') {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_CATEGORY_ONLY_MODE'));
            }

            if ($options['category_mode'] === 'single' && $options['single_catid'] <= 1) {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_SELECT_CATEGORY'));
            }

            $payload = (new PayloadBuilder())->build($adapter, $options);
            $preview = $this->dryRun($adapter, $options, $payload);
            $preview['fingerprint'] = PayloadBuilder::fingerprint($payload);
        } catch (\Throwable $e) {
            $this->log($adapter->getLabel() . ' preview failed: ' . $e->getMessage(), Log::ERROR);
            $this->redirectTo('source', $e->getMessage(), 'error', $source);
            return;
        }

        $this->app->setUserState(self::PREVIEW_STATE, $preview);
        $this->app->setUserState(self::PROGRESS_STATE, null);

        $c = $preview['counts'];
        $this->log(sprintf(
            '%s preview: %d events (%d series), %d new categories, %d new venues, %d already imported, %d skipped, %d errors.',
            $adapter->getLabel(), $c['events'], $c['series'], $c['categories_new'], $c['venues_new'],
            $c['skipped_existing'], $c['skipped_past'] + $c['skipped_source'], $c['errors']
        ));
        $this->redirectTo(
            'runs',
            Text::sprintf('COM_JEMMIGRATOR_PREVIEW_READY', $adapter->getLabel(), $c['events'], $c['errors']),
            $c['errors'] ? 'warning' : 'message',
            $source
        );
    }

    public function start(): void
    {
        $this->checkToken();

        $preview = (array) $this->app->getUserState(self::PREVIEW_STATE, []);
        $selectedSource = $this->input->post->getCmd('source', '');

        if (empty($preview['source']) || $selectedSource !== $preview['source']
            || !SourceRegistry::get((string) $preview['source'])) {
            $this->redirectTo('source', Text::_('COM_JEMMIGRATOR_ERROR_NO_PREVIEW'), 'warning');
            return;
        }

        try {
            $adapter = SourceRegistry::get($selectedSource);
            $info = (new ImportClient())->info();

            if (empty(SourceRegistry::tabs()[$selectedSource]['available']) || !$info
                || empty($info['compatible']) || empty($info['ready'])) {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_PREFLIGHT'));
            }

            $payload = (new PayloadBuilder())->build($adapter, (array) $preview['options']);
            $checked = $this->dryRun($adapter, (array) $preview['options'], $payload);

            if (PayloadBuilder::fingerprint($payload) !== (string) ($preview['fingerprint'] ?? '')
                || $checked['plan_fingerprint'] !== (string) ($preview['plan_fingerprint'] ?? '')
                || (int) $checked['counts']['errors'] > 0
                || ((int) $checked['counts']['events'] < 1 && (int) $checked['counts']['categories_new'] < 1)) {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_PREFLIGHT'));
            }
        } catch (\Throwable $e) {
            $this->app->setUserState(self::PREVIEW_STATE, null);
            $this->redirectTo('source', $e->getMessage(), 'error', $selectedSource);
            return;
        }

        $this->app->setUserState(self::PROGRESS_STATE, [
            'source'  => (string) $preview['source'],
            'label'   => (string) $preview['label'],
            'options' => (array) $preview['options'],
            'run_id'  => 0,
            'offset'  => 0,
            'total'   => (int) $preview['to_send'],
            'fingerprint' => (string) ($preview['fingerprint'] ?? ''),
            'counts'  => [],
            'error'   => '',
            'retry_safe' => true,
        ]);

        $this->redirectTo('progress');
    }

    public function step(): void
    {
        $this->checkToken();

        $progress = (array) $this->app->getUserState(self::PROGRESS_STATE, []);
        $adapter = !empty($progress['source']) ? SourceRegistry::get((string) $progress['source']) : null;

        if (!$adapter || empty(SourceRegistry::tabs()[$progress['source']]['available'])) {
            $this->app->setUserState(self::PROGRESS_STATE, null);
            $this->redirectTo('source', Text::_('COM_JEMMIGRATOR_ERROR_NO_PREVIEW'), 'warning');
            return;
        }

        $batchSize = min(500, max(10, (int) ComponentHelper::getParams('com_jemmigrator')->get('batch_size', 200)));

        try {
            // Refuse to continue if source rows changed after the preview.
            $payload = (new PayloadBuilder())->build($adapter, (array) $progress['options']);
            if (PayloadBuilder::fingerprint($payload) !== (string) ($progress['fingerprint'] ?? '')) {
                throw new \RuntimeException(Text::_('COM_JEMMIGRATOR_ERROR_SOURCE_CHANGED'));
            }

            $offset = (int) $progress['offset'];
            $chunk = \array_slice($payload['events'], $offset, $batchSize);
            $last = $offset + $batchSize >= \count($payload['events']);

            $result = (new ImportClient())->batch([
                'source'     => $adapter->getSource(),
                'label'      => $adapter->getLabel(),
                'run_id'     => (int) $progress['run_id'],
                'dry_run'    => false,
                'last_batch' => $last,
                'options'    => $this->importOptions((array) $progress['options']),
                'categories' => $offset === 0 ? $payload['categories'] : [],
                'events'     => $chunk,
            ]);
        } catch (\Throwable $e) {
            $progress['error'] = $e->getMessage();
            $progress['retry_safe'] = false;

            if ($e instanceof BatchFailure) {
                $progress['run_id'] = $e->getRunId();
                $progress['retry_safe'] = $e->isRetrySafe();
            }

            $this->app->setUserState(self::PROGRESS_STATE, $progress);
            $this->log($adapter->getLabel() . ' import batch failed at ' . (int) $progress['offset'] . ': ' . $e->getMessage(), Log::ERROR);
            $this->redirectTo('progress');
            return;
        }

        $progress['run_id'] = (int) ($result['run_id'] ?? $progress['run_id']);
        $progress['offset'] = $offset + \count($chunk);
        $progress['total'] = \count($payload['events']);
        $progress['error'] = '';
        $progress['retry_safe'] = true;

        foreach ((array) ($result['counts'] ?? []) as $key => $value) {
            $progress['counts'][$key] = (int) ($progress['counts'][$key] ?? 0) + (int) $value;
        }

        if (!$last) {
            $this->app->setUserState(self::PROGRESS_STATE, $progress);
            $this->redirectTo('progress');
            return;
        }

        $this->app->setUserState(self::PROGRESS_STATE, null);
        $this->app->setUserState(self::PREVIEW_STATE, null);

        $c = $progress['counts'] + ['events' => 0, 'series' => 0, 'categories_new' => 0, 'venues_new' => 0, 'skipped_existing' => 0, 'errors' => 0];
        $message = Text::sprintf(
            'COM_JEMMIGRATOR_IMPORT_DONE',
            $adapter->getLabel(), $c['events'], $c['series'], $c['categories_new'], $c['venues_new'], $c['skipped_existing'], $c['errors']
        );
        $this->log($message . ' (run ' . $progress['run_id'] . ')');
        $this->redirectTo('runs', $message, 'message', (string) $progress['source']);
    }

    public function cancel(): void
    {
        $this->checkToken();

        $progress = (array) $this->app->getUserState(self::PROGRESS_STATE, []);

        if (!empty($progress['run_id'])) {
            try {
                (new ImportClient())->stop((int) $progress['run_id']);
            } catch (\Throwable $e) {
                $this->redirectTo('progress', $e->getMessage(), 'error');
                return;
            }

            $this->app->setUserState(self::PROGRESS_STATE, null);
            $this->log('Import run ' . (int) $progress['run_id'] . ' stopped by the user at ' . (int) $progress['offset'] . '.');
            $this->redirectTo('runs', Text::_('COM_JEMMIGRATOR_IMPORT_STOPPED'), 'warning', (string) $progress['source']);
            return;
        }

        $this->app->setUserState(self::PROGRESS_STATE, null);
        $this->redirectTo('source', '', 'message', (string) ($progress['source'] ?? ''));
    }

    public function clear(): void
    {
        $this->checkToken();

        $this->app->setUserState(self::PREVIEW_STATE, null);
        $this->redirectTo('source', '', 'message', $this->input->post->getCmd('source', ''));
    }

    public function undo(): void
    {
        $this->checkToken();

        $runId = $this->input->post->getInt('run_id', 0);

        try {
            $result = (new ImportClient())->undo($runId);
        } catch (\Throwable $e) {
            $this->log('Undo of run ' . $runId . ' failed: ' . $e->getMessage(), Log::ERROR);
            $this->redirectTo('runs', $e->getMessage(), 'error');
            return;
        }

        $c = (array) ($result['counts'] ?? []) + ['events' => 0, 'series' => 0, 'venues' => 0, 'categories' => 0, 'kept' => 0];
        $message = Text::sprintf('COM_JEMMIGRATOR_UNDO_DONE', $runId, $c['events'], $c['series'], $c['venues'], $c['categories'], $c['kept']);
        $this->log($message);
        $this->redirectTo('runs', $message, $c['kept'] ? 'warning' : 'message', $this->input->post->getCmd('source', ''));
    }

    /* ------------------------------------------------------------------ */

    private function dryRun(AbstractAdapter $adapter, array $options, array $payload): array
    {
        $client = new ImportClient();
        $counts = [
            'events' => 0, 'series' => 0, 'skipped_existing' => 0, 'errors' => 0,
            'warnings' => 0, 'categories_new' => 0, 'categories_existing' => 0,
            'venues_new' => 0, 'venues_existing' => 0,
        ] + $payload['counts'];
        $rows = [];
        $categories = [];
        $venues = [];
        $series = [];
        $planHash = hash_init('sha256');

        foreach (array_chunk($payload['events'], self::DRY_RUN_CHUNK) ?: [[]] as $index => $chunk) {
            $result = $client->batch([
                'source'     => $adapter->getSource(),
                'label'      => $adapter->getLabel(),
                'dry_run'    => true,
                'options'    => $this->importOptions($options),
                'categories' => $payload['categories'],
                'events'     => $chunk,
            ]);

            if ($index === 0) {
                $categories = (array) ($result['categories'] ?? []);
                hash_update($planHash, (string) json_encode($categories));
                $counts['categories_new'] = (int) ($result['counts']['categories_new'] ?? 0);
                $counts['categories_existing'] = (int) ($result['counts']['categories_existing'] ?? 0);
                $counts['errors'] += count(array_filter($categories, static fn ($category) => $category['status'] === 'error'));
                $counts['warnings'] += count(array_filter($categories, static fn ($category) => !empty($category['note'])));
            }

            foreach ((array) ($result['rows'] ?? []) as $row) {
                hash_update($planHash, (string) json_encode([
                    $row['ref'], $row['status'], $row['category_targets'], $row['venue'], $row['notes'],
                ]));
                if ($row['status'] === 'new') {
                    $counts['warnings'] += count((array) ($row['notes'] ?? []));
                }

                if ($row['status'] === 'new') {
                    $counts['events']++;
                    if ($row['series_ref'] !== '') {
                        $series[$row['series_ref']] = true;
                    }
                } elseif ($row['status'] === 'skipped') {
                    $counts['skipped_existing']++;
                } else {
                    $counts['errors']++;
                }

                if (\count($rows) < self::PREVIEW_ROWS) {
                    $rows[] = $row;
                }
            }

            $venues += (array) ($result['venues'] ?? []);
            hash_update($planHash, (string) json_encode((array) ($result['venues'] ?? [])));
        }

        foreach ($payload['skipped'] as $row) {
            if (\count($rows) < self::PREVIEW_ROWS) {
                $rows[] = $row;
            }
        }

        $counts['series'] = \count($series);
        foreach ($venues as $venue) {
            $counts[$venue['status'] === 'new' ? 'venues_new' : 'venues_existing']++;
        }

        return [
            'source'     => $adapter->getSource(),
            'label'      => $adapter->getLabel(),
            'options'    => $options,
            'counts'     => $counts,
            'categories' => $categories,
            'rows'       => $rows,
            'total'      => \count($payload['events']) + \count($payload['skipped']),
            'to_send'    => \count($payload['events']),
            'plan_fingerprint' => hash_final($planHash),
        ];
    }

    private function getOptions(string $source): array
    {
        $input = $this->input->post;
        $prefix = 'jm_' . $source . '_';
        $media = SourceRegistry::get($source)?->getMediaCapabilities() ?? [];
        $mode = $input->getCmd($prefix . 'category_mode', 'tree');
        $mapJson = (string) $input->get($prefix . 'category_map_json', '{}', 'raw');
        $categoryMap = json_decode($mapJson, true);

        if ($mode === 'manual' && (!\is_array($categoryMap) || \strlen($mapJson) > 1048576)) {
            throw new \InvalidArgumentException(Text::_('COM_JEMMIGRATOR_ERROR_CATEGORY_MAP'));
        }

        return [
            'category_mode' => \in_array($mode, ['tree', 'single', 'manual'], true) ? $mode : 'tree',
            'parent_catid'  => max(1, $input->getInt($prefix . 'parent_catid', 1)),
            'single_catid'  => $input->getInt($prefix . 'single_catid', 0),
            'category_map'  => $mode === 'manual' ? $categoryMap : [],
            'migrate_events' => $input->getInt($prefix . 'migrate_events', 0) ? 1 : 0,
            'migrate_categories' => $input->getInt($prefix . 'migrate_categories', 0) ? 1 : 0,
            'create_venues' => $input->getInt($prefix . 'migrate_events', 0)
                && $input->getInt($prefix . 'create_venues', 0) ? 1 : 0,
            'copy_event_images' => !empty($media['event_images']) && $input->getInt($prefix . 'migrate_events', 0)
                && $input->getInt($prefix . 'copy_event_images', 0) ? 1 : 0,
            'copy_category_images' => !empty($media['category_images']) && $input->getInt($prefix . 'migrate_categories', 0)
                && $input->getInt($prefix . 'copy_category_images', 0) ? 1 : 0,
            'copy_venue_images' => !empty($media['venue_images']) && $input->getInt($prefix . 'migrate_events', 0)
                && $input->getInt($prefix . 'create_venues', 0)
                && $input->getInt($prefix . 'copy_venue_images', 0) ? 1 : 0,
            'skip_past'     => $input->getInt($prefix . 'skip_past', 0) ? 1 : 0,
            'calendars'     => array_values(array_filter(array_map('intval', (array) $input->get($prefix . 'calendars', [], 'array')))),
        ];
    }

    private function importOptions(array $options): array
    {
        return [
            'category_mode' => $options['category_mode'] ?? 'tree',
            'parent_catid'  => (int) ($options['parent_catid'] ?? 1),
            'single_catid'  => (int) ($options['single_catid'] ?? 0),
            'category_map'  => (array) ($options['category_map'] ?? []),
            'create_venues' => (int) ($options['create_venues'] ?? 1),
            'copy_event_images' => (int) ($options['copy_event_images'] ?? 0),
            'copy_category_images' => (int) ($options['copy_category_images'] ?? 0),
            'copy_venue_images' => (int) ($options['copy_venue_images'] ?? 0),
        ];
    }

    private function redirectTo(string $view, string $message = '', string $type = 'message', string $source = ''): void
    {
        $url = 'index.php?option=com_jemmigrator&view=' . $view;

        if ($source !== '' && SourceRegistry::get($source)) {
            $url .= '&source=' . rawurlencode($source);
        }

        $this->setRedirect(Route::_($url, false), $message !== '' ? $message : null, $type);
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        if (!self::$loggerAdded) {
            Log::addLogger(['text_file' => 'jem-migrator.log.php'], Log::ALL, ['com_jemmigrator']);
            self::$loggerAdded = true;
        }

        Log::add($message, $priority, 'com_jemmigrator');
    }
}
