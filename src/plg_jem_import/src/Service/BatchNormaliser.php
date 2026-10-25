<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Validates a batch in the JEM import format. Fields the format
 * does not define are dropped; malformed rows become error rows.
 *
 * Batch:    format_version, source, label, run_id, dry_run, last_batch,
 *           options, categories[], events[]
 * Category: ref, parent_ref, title, alias, description, access, published,
 *           color, language, image (v2)
 * Event:    ref, series_ref, title, description, url, dates, times, enddates,
 *           endtimes (site time), published, access, created (UTC),
 *           created_by, category_refs[], venue, notes[], image_intro,
 *           image_full (v2)
 * Venue:    name, street, postal_code, city, state, country, latitude,
 *           longitude, url, image (v2)
 */
final class BatchNormaliser
{
    public const FORMAT_VERSION = 2;
    public const MAX_EVENTS = 500;
    public const MAX_CATEGORIES = 5000;

    public static function normalise(array $batch): array
    {
        $version = (int) ($batch['format_version'] ?? 0);

        if (!\in_array($version, [1, self::FORMAT_VERSION], true)) {
            throw new \InvalidArgumentException(Text::sprintf('PLG_JEM_IMPORT_ERROR_FORMAT', $version, self::FORMAT_VERSION));
        }

        $source = (string) ($batch['source'] ?? '');

        if (!preg_match('/^[a-z0-9_]{2,50}$/', $source)) {
            throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_SOURCE'));
        }

        $categories = \is_array($batch['categories'] ?? null) ? $batch['categories'] : [];
        $events     = \is_array($batch['events'] ?? null) ? $batch['events'] : [];

        if (\count($events) > self::MAX_EVENTS || \count($categories) > self::MAX_CATEGORIES) {
            throw new \InvalidArgumentException(Text::sprintf('PLG_JEM_IMPORT_ERROR_BATCH_SIZE', self::MAX_EVENTS, self::MAX_CATEGORIES));
        }

        $options = \is_array($batch['options'] ?? null) ? $batch['options'] : [];
        $categoryMode = (string) ($options['category_mode'] ?? 'tree');
        $copyImages = $version >= 2 && !empty($options['copy_images']);
        $categoryMap = [];

        if ($categoryMode === 'manual') {
            if (!\is_array($options['category_map'] ?? [])) {
                throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_CATEGORY_MAP'));
            }

            if (\count($options['category_map']) > self::MAX_CATEGORIES) {
                throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_CATEGORY_MAP'));
            }

            foreach ($options['category_map'] as $ref => $targetId) {
                $ref = (string) $ref;
                $targetId = filter_var($targetId, FILTER_VALIDATE_INT);

                if ($ref === '' || mb_strlen($ref) > 255 || $targetId === false || $targetId <= 1) {
                    throw new \InvalidArgumentException(Text::_('PLG_JEM_IMPORT_ERROR_CATEGORY_MAP'));
                }

                $categoryMap[$ref] = $targetId;
            }
        }

        return [
            'format_version' => $version,
            'source'  => $source,
            'label'   => self::text($batch['label'] ?? $source, 100),
            'run_id'  => max(0, (int) ($batch['run_id'] ?? 0)),
            'dry_run' => !empty($batch['dry_run']),
            'last_batch' => !empty($batch['last_batch']),
            'options' => [
                'category_mode' => \in_array($categoryMode, ['tree', 'single', 'manual'], true) ? $categoryMode : 'tree',
                'parent_catid'  => max(1, (int) ($options['parent_catid'] ?? 1)),
                'single_catid'  => max(0, (int) ($options['single_catid'] ?? 0)),
                'category_map'  => $categoryMap,
                'create_venues' => !empty($options['create_venues']) ? 1 : 0,
                'copy_images'   => $copyImages ? 1 : 0,
                'copy_event_images' => $version >= 2 && !empty($options['copy_event_images'] ?? $copyImages) ? 1 : 0,
                'copy_category_images' => $version >= 2 && !empty($options['copy_category_images'] ?? $copyImages) ? 1 : 0,
                'copy_venue_images' => $version >= 2 && !empty($options['copy_venue_images'] ?? $copyImages) ? 1 : 0,
            ],
            'categories' => array_values(array_map(static function (array $category) use ($version): array {
                return self::category($category, $version);
            }, array_filter($categories, 'is_array'))),
            'events'     => array_values(array_map(static function ($event) use ($version): array {
                return self::event($event, $version);
            }, $events)),
        ];
    }

    private static function category(array $c, int $version): array
    {
        $color = (string) ($c['color'] ?? '');
        $color = preg_match('/^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) ? '#' . ltrim($color, '#') : '';
        $language = (string) ($c['language'] ?? '*');

        return [
            'ref'         => self::text($c['ref'] ?? '', 255),
            'parent_ref'  => self::text($c['parent_ref'] ?? '', 255),
            'title'       => self::text($c['title'] ?? '', 100),
            'alias'       => self::text($c['alias'] ?? '', 100),
            'description' => self::text($c['description'] ?? '', 65535),
            'access'      => max(0, (int) ($c['access'] ?? 1)),
            'published'   => (int) ($c['published'] ?? 1) === 1 ? 1 : 0,
            'color'       => $color,
            'language'    => preg_match('/^([a-z]{2,3}-[A-Z]{2}|\*)$/', $language) ? $language : '*',
            'image'       => $version >= 2 ? self::text($c['image'] ?? '', 2048) : '',
        ];
    }

    /**
     * @param  mixed  $e
     */
    private static function event($e, int $version): array
    {
        if (!\is_array($e)) {
            return ['ref' => '', 'errors' => [Text::_('PLG_JEM_IMPORT_ERROR_ROW')]] + self::emptyEvent();
        }

        $errors = [];
        $ref = self::text($e['ref'] ?? '', 255);
        $dates = self::date($e['dates'] ?? null);
        $times = self::time($e['times'] ?? null);
        $enddates = self::date($e['enddates'] ?? null);
        $endtimes = self::time($e['endtimes'] ?? null);

        if ($ref === '') {
            $errors[] = Text::_('PLG_JEM_IMPORT_ERROR_REF');
        }

        if ($dates === null) {
            $errors[] = Text::_('PLG_JEM_IMPORT_ERROR_DATE');
        }

        // An omitted time or end date is valid; a supplied malformed value is not.
        if (self::provided($e, 'times') && $times === null) {
            $errors[] = Text::_('PLG_JEM_IMPORT_ERROR_TIME');
        }

        if (self::provided($e, 'enddates') && $enddates === null) {
            $errors[] = Text::_('PLG_JEM_IMPORT_ERROR_END_DATE');
        }

        if (self::provided($e, 'endtimes') && $endtimes === null) {
            $errors[] = Text::_('PLG_JEM_IMPORT_ERROR_END_TIME');
        }

        $categoryRefs = [];
        foreach ((array) ($e['category_refs'] ?? []) as $categoryRef) {
            $categoryRef = self::text($categoryRef, 255);
            if ($categoryRef !== '') {
                $categoryRefs[$categoryRef] = $categoryRef;
            }
        }

        $notes = [];
        foreach (\array_slice((array) ($e['notes'] ?? []), 0, 20) as $note) {
            $note = self::text($note, 255);
            if ($note !== '') {
                $notes[] = $note;
            }
        }

        return [
            'ref'           => $ref,
            'series_ref'    => self::text($e['series_ref'] ?? '', 255),
            'title'         => self::text($e['title'] ?? '', 1000),
            'description'   => self::text($e['description'] ?? '', 65535),
            'url'           => self::text($e['url'] ?? '', 2048),
            'dates'         => $dates,
            'times'         => $times,
            'enddates'      => $enddates,
            'endtimes'      => $endtimes,
            // 1 published, 0 unpublished, 2 archived (as in JEM).
            'published'     => \in_array((int) ($e['published'] ?? 0), [0, 1, 2], true) ? (int) ($e['published'] ?? 0) : 0,
            'access'        => max(0, (int) ($e['access'] ?? 1)),
            'created'       => self::datetime($e['created'] ?? null),
            'created_by'    => max(0, (int) ($e['created_by'] ?? 0)),
            'category_refs' => array_values($categoryRefs),
            'venue'         => \is_array($e['venue'] ?? null) ? self::venue($e['venue'], $version) : null,
            'image_intro'   => $version >= 2 ? self::text($e['image_intro'] ?? '', 2048) : '',
            'image_full'    => $version >= 2 ? self::text($e['image_full'] ?? '', 2048) : '',
            'notes'         => $notes,
            'errors'        => $errors,
        ];
    }

    private static function venue(array $v, int $version): ?array
    {
        $venue = [
            'name'        => self::text($v['name'] ?? '', 100),
            'street'      => self::text($v['street'] ?? '', 50),
            'postal_code' => self::text($v['postal_code'] ?? '', 20),
            'city'        => self::text($v['city'] ?? '', 50),
            'state'       => self::text($v['state'] ?? '', 50),
            'country'     => self::text($v['country'] ?? '', 100),
            'latitude'    => self::coordinate($v['latitude'] ?? null, 90),
            'longitude'   => self::coordinate($v['longitude'] ?? null, 180),
            'url'         => self::text($v['url'] ?? '', 200),
            'image'       => $version >= 2 ? self::text($v['image'] ?? '', 2048) : '',
            'access'      => max(1, (int) ($v['access'] ?? 1)),
        ];

        return $venue['name'] !== '' ? $venue : null;
    }

    private static function emptyEvent(): array
    {
        return [
            'series_ref' => '', 'title' => '', 'description' => '', 'url' => '', 'dates' => null, 'times' => null,
            'enddates' => null, 'endtimes' => null, 'published' => 0, 'access' => 1, 'created' => null,
            'created_by' => 0, 'category_refs' => [], 'venue' => null, 'notes' => [],
            'image_intro' => '', 'image_full' => '',
        ];
    }

    private static function provided(array $event, string $field): bool
    {
        return array_key_exists($field, $event) && $event[$field] !== null && $event[$field] !== '';
    }

    /**
     * @param  mixed  $value
     */
    private static function text($value, int $length): string
    {
        $value = \is_scalar($value) ? trim((string) $value) : '';

        return mb_substr($value, 0, $length);
    }

    /**
     * @param  mixed  $value
     */
    private static function date($value): ?string
    {
        if (!\is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    /**
     * @param  mixed  $value
     */
    private static function time($value): ?string
    {
        if (!\is_string($value) || !preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return null;
        }

        if ((int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            return null;
        }

        return sprintf('%02d:%02d:%02d', $m[1], $m[2], $m[3] ?? 0);
    }

    /**
     * @param  mixed  $value
     */
    private static function datetime($value): ?string
    {
        if (!\is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $value, $m)) {
            return null;
        }

        return self::date($m[1]) !== null && self::time($m[2]) !== null ? $value : null;
    }

    /**
     * @param  mixed  $value
     */
    private static function coordinate($value, int $limit): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return abs($value) <= $limit ? $value : null;
    }
}
