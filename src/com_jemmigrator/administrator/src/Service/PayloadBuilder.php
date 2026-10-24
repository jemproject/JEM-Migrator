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
use Joomla\CMS\Language\Text;

/**
 * Converts the normalised data of an adapter into the JEM import format,
 * version 2. Rows skipped on the source side (past dates, external
 * calendars) are returned separately and never sent to JEM.
 */
final class PayloadBuilder
{
    public const MAX_OCCURRENCES = 20000;

    /**
     * Identify the exact previewed source rows and order before each batch.
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', serialize([
            $payload['categories'],
            $payload['events'],
            $payload['skipped'],
        ]));
    }

    /**
     * @return array{categories: array, events: array, skipped: array, counts: array}
     */
    public function build(AbstractAdapter $adapter, array $options): array
    {
        $adapter->setOptions(['calendars' => $options['calendars'] ?? []]);
        $occurrences = $adapter->loadOccurrences();

        if (\count($occurrences) > self::MAX_OCCURRENCES) {
            throw new \RuntimeException(Text::sprintf('COM_JEMMIGRATOR_ERROR_TOO_MANY', \count($occurrences), self::MAX_OCCURRENCES));
        }

        $today = Factory::getDate('now', (string) Factory::getApplication()->get('offset', 'UTC'))->format('Y-m-d', true);
        $kept = [];
        $skipped = [];
        $counts = ['skipped_past' => 0, 'skipped_source' => 0];

        foreach ($occurrences as $o) {
            $notes = array_values(array_map('strval', (array) ($o['notes'] ?? [])));

            if (($o['status'] ?? 'ok') === 'skip') {
                $skipped[] = $this->previewRow($o, $notes);
                $counts['skipped_source']++;
                continue;
            }

            if (!empty($options['skip_past']) && (string) (($o['enddates'] ?? null) ?: $o['dates']) < $today) {
                $notes[] = Text::_('COM_JEMMIGRATOR_NOTE_PAST');
                $skipped[] = $this->previewRow($o, $notes);
                $counts['skipped_past']++;
                continue;
            }

            $o['notes'] = $notes;
            $kept[] = $o;
        }

        // Series are counted after filtering: one remaining date is a single event.
        $seriesCount = [];
        foreach ($kept as $o) {
            $series = (string) ($o['src_series'] ?? '');
            if ($series !== '') {
                $seriesCount[$series] = ($seriesCount[$series] ?? 0) + 1;
            }
        }

        $events = [];
        foreach ($kept as $o) {
            $series = (string) ($o['src_series'] ?? '');
            $events[] = [
                'ref'           => (string) $o['src_key'],
                'series_ref'    => $series !== '' && $seriesCount[$series] > 1 ? $series : '',
                'title'         => (string) ($o['title'] ?? ''),
                'description'   => (string) ($o['description'] ?? ''),
                'url'           => (string) ($o['url'] ?? ''),
                'dates'         => (string) ($o['dates'] ?? ''),
                'times'         => $o['times'] ?? null,
                'enddates'      => $o['enddates'] ?? null,
                'endtimes'      => $o['endtimes'] ?? null,
                'published'     => (int) ($o['published'] ?? 0),
                'access'        => (int) ($o['access'] ?? 1),
                'created'       => $o['created'] ?? null,
                'created_by'    => (int) ($o['created_by'] ?? 0),
                'category_refs' => array_values(array_map('strval', (array) ($o['categories'] ?? []))),
                'venue'         => $this->venue($o['venue'] ?? null),
                'image_intro'   => (string) ($o['image_intro'] ?? ''),
                'image_full'    => (string) ($o['image_full'] ?? ''),
                'notes'         => $o['notes'],
            ];
        }

        $categories = ($options['category_mode'] ?? 'tree') !== 'single'
            ? $this->categories($adapter->loadCategories(), $events, ($options['category_mode'] ?? 'tree') === 'manual')
            : [];

        return ['categories' => $categories, 'events' => $events, 'skipped' => $skipped, 'counts' => $counts];
    }

    /**
     * Automatic mode uses categories that hold events and their parents.
     * Manual mode includes empty source categories so the complete proposed
     * destination tree can be reviewed and created.
     */
    private function categories(array $categories, array $events, bool $includeAll = false): array
    {
        $used = $includeAll ? array_fill_keys(array_keys($categories), true) : [];
        foreach ($events as $event) {
            foreach ($event['category_refs'] as $id) {
                for (; $id !== '' && isset($categories[$id]) && !isset($used[$id]); $id = (string) $categories[$id]['src_parent']) {
                    $used[$id] = true;
                }
            }
        }

        $result = [];
        foreach ($categories as $id => $category) {
            if (!isset($used[(string) $id])) {
                continue;
            }

            $parent = (string) ($category['src_parent'] ?? '');
            $result[] = [
                'ref'         => (string) $id,
                'parent_ref'  => $parent !== '' && isset($used[$parent]) ? $parent : '',
                'title'       => (string) ($category['catname'] ?? ''),
                'alias'       => (string) ($category['alias'] ?? ''),
                'description' => (string) ($category['description'] ?? ''),
                'access'      => (int) ($category['access'] ?? 1),
                'published'   => (int) ($category['published'] ?? 1),
                'color'       => (string) ($category['color'] ?? ''),
                'language'    => (string) ($category['language'] ?? '*'),
                'image'       => (string) ($category['image'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * @param  mixed  $venue
     */
    private function venue($venue): ?array
    {
        if (!\is_array($venue) || trim((string) ($venue['venue'] ?? '')) === '') {
            return null;
        }

        return [
            'name'        => (string) $venue['venue'],
            'street'      => (string) ($venue['street'] ?? ''),
            'postal_code' => (string) ($venue['postalCode'] ?? ''),
            'city'        => (string) ($venue['city'] ?? ''),
            'state'       => (string) ($venue['state'] ?? ''),
            'country'     => (string) ($venue['country'] ?? ''),
            'latitude'    => $venue['latitude'] ?? null,
            'longitude'   => $venue['longitude'] ?? null,
            'url'         => (string) ($venue['url'] ?? ''),
            'image'       => (string) ($venue['image'] ?? ''),
        ];
    }

    private function previewRow(array $o, array $notes): array
    {
        return [
            'ref'        => (string) ($o['src_key'] ?? ''),
            'status'     => 'skipped',
            'title'      => (string) ($o['title'] ?? ''),
            'dates'      => (string) ($o['dates'] ?? ''),
            'times'      => (string) ($o['times'] ?? ''),
            'enddates'   => (string) ($o['enddates'] ?? ''),
            'endtimes'   => (string) ($o['endtimes'] ?? ''),
            'venue'      => (string) ($o['venue']['venue'] ?? ''),
            'series_ref' => '',
            'published'  => (int) ($o['published'] ?? 0),
            'access'     => (int) ($o['access'] ?? 1),
            'notes'      => $notes,
        ];
    }
}
