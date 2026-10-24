<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Adapter;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/**
 * Base class for source adapters. An adapter only reads the tables of its
 * source component and returns normalised categories and occurrences; JEM
 * Migrator converts them to the JEM import format and the JEM import plugin
 * validates and saves them.
 *
 * Normalised category:   src_id, src_parent, level, catname, alias,
 *                        description, access, published, color, language, image
 * Normalised occurrence: src_key (unique), src_series ('' = single event),
 *                        title, description, url, dates, times, enddates,
 *                        endtimes (site timezone), published, access,
 *                        created (UTC or null), created_by, categories
 *                        (source ids), venue (array or null), notes, status
 *                        ('ok' or 'skip'), image_intro, image_full
 * Venue:                 venue, street, postalCode, city, state, country,
 *                        latitude, longitude, url, image
 *
 * Based on the component migration example by JKoelman (JEM issue #2371).
 */
abstract class AbstractAdapter
{
    protected ReadOnlyDatabase $db;

    protected array $options = [
        'calendars' => [],
    ];

    public function __construct(DatabaseInterface $db)
    {
        $this->db = new ReadOnlyDatabase($db);
    }

    public function setOptions(array $options): void
    {
        $this->options = array_merge($this->options, $options);
    }

    /** Source key, e.g. "jevents" (lowercase letters, digits, underscore). */
    abstract public function getSource(): string;

    /** Name shown to the user. */
    abstract public function getLabel(): string;

    /** Element of the source component in #__extensions, e.g. "com_jevents". */
    abstract public function getElement(): string;

    /** True when the source tables exist. */
    abstract public function isAvailable(): bool;

    /**
     * Overview: ['lines' => string[], 'calendars' => [['id', 'label', 'events']], 'occurrences' => int].
     */
    abstract public function getSummary(): array;

    /** Normalised categories, parents before children, keyed by source id. */
    abstract public function loadCategories(): array;

    /** Normalised occurrences, in a stable order. */
    abstract public function loadOccurrences(): array;

    /** Short explanation of what is imported. */
    public function getDescription(): string
    {
        return '';
    }

    /** Installed version of the source component. */
    public function getVersion(): string
    {
        return $this->getExtensionVersion($this->getElement());
    }

    /* ---- helpers for adapters ------------------------------------------ */

    protected function tableExists(string $table): bool
    {
        return \in_array($this->db->getPrefix() . $table, $this->db->getTableList(), true);
    }

    protected function getSiteTimezone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * Split a timestamp in a source timezone into JEM fields in the site timezone.
     *
     * @return array{0: string, 1: string}  date, time
     */
    protected function toSiteParts(string $value, \DateTimeZone $from): array
    {
        $local = (new \DateTimeImmutable($value, $from))->setTimezone($this->getSiteTimezone());

        return [$local->format('Y-m-d'), $local->format('H:i:s')];
    }

    /** Convert a datetime in a source timezone to UTC SQL format. */
    protected function toUtc(?string $value, \DateTimeZone $from): ?string
    {
        if ($value === null || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }

        return (new \DateTimeImmutable($value, $from))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    protected function getExtensionVersion(string $element, string $type = 'component'): string
    {
        $db = $this->db;
        $cache = (string) $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element))
                ->where($db->quoteName('type') . ' = ' . $db->quote($type))
        )->loadResult();

        $data = json_decode($cache, true);

        return (string) ($data['version'] ?? '');
    }

    /**
     * Joomla core categories of an extension, normalised.
     */
    protected function loadJoomlaCategories(string $extension, string $colorParam = ''): array
    {
        $db = $this->db;
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select($db->quoteName(['id', 'parent_id', 'level', 'title', 'alias', 'description', 'access', 'published', 'params', 'language']))
                ->from($db->quoteName('#__categories'))
                ->where($db->quoteName('extension') . ' = ' . $db->quote($extension))
                ->where($db->quoteName('published') . ' IN (0, 1)')
                ->order($db->quoteName('lft'))
        )->loadObjectList();

        $categories = [];
        foreach ($rows as $row) {
            $params = json_decode((string) $row->params, true) ?: [];
            $color = $colorParam !== '' ? (string) ($params[$colorParam] ?? '') : '';
            if ($color !== '' && $color[0] !== '#') {
                $color = '#' . $color;
            }

            $categories[(string) $row->id] = [
                'src_id'      => (string) $row->id,
                'src_parent'  => isset($categories[(string) $row->parent_id]) ? (string) $row->parent_id : '',
                'level'       => (int) $row->level,
                'catname'     => (string) $row->title,
                'alias'       => (string) $row->alias,
                'description' => (string) $row->description,
                'access'      => (int) $row->access,
                'published'   => (int) $row->published,
                'color'       => $color,
                'language'    => (string) $row->language ?: '*',
                'image'       => (string) ($params['image'] ?? ''),
            ];
        }

        return $categories;
    }

    /**
     * "Name, Street, [postcode] City" free text into venue fields.
     */
    protected function parseLocationText(string $location): ?array
    {
        $location = trim(strip_tags(html_entity_decode($location, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($location === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location)), 'strlen'));
        $venue = ['venue' => $parts[0], 'street' => '', 'postalCode' => '', 'city' => ''];

        if (\count($parts) >= 3) {
            $venue['street'] = $parts[1];
            $venue['city'] = $parts[\count($parts) - 1];
        } elseif (\count($parts) === 2) {
            $venue['city'] = $parts[1];
        }

        return $this->splitPostalCode($venue);
    }

    protected function splitPostalCode(array $venue): array
    {
        if (empty($venue['postalCode']) && preg_match('/^(\d{4}\s?[A-Z]{2}|\d{4,5})\s+(.+)$/u', (string) ($venue['city'] ?? ''), $m)) {
            $venue['postalCode'] = $m[1];
            $venue['city'] = $m[2];
        }

        return $venue;
    }
}
