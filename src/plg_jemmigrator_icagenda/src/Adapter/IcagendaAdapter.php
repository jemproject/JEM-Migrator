<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Plugin\JemMigrator\Icagenda\Adapter;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use Joomla\CMS\Language\Text;

/**
 * iCagenda (https://www.icagenda.com).
 *
 * An iCagenda event has a list of single dates (start time only) and/or a
 * period. A period without week days is one continuous event; with week
 * days it is one date per selected day, each from the start time to the end
 * time. Dates are stored as site wall-clock time. Every date becomes a JEM
 * event; the dates of one iCagenda event form a JEM series. Categories are
 * flat; the location is stored on the event itself.
 *
 * Based on the iCagenda adapter by JKoelman (JEM issue #2371).
 */
final class IcagendaAdapter extends AbstractAdapter
{
    public function getSource(): string
    {
        return 'icagenda';
    }

    public function getLabel(): string
    {
        return 'iCagenda';
    }

    public function getElement(): string
    {
        return 'com_icagenda';
    }

    public function getDescription(): string
    {
        return Text::_('COM_JEMMIGRATOR_ICAGENDA_DESC');
    }

    public function isAvailable(): bool
    {
        return $this->tableExists('icagenda_events') && $this->tableExists('icagenda_category');
    }

    public function getSummary(): array
    {
        $db = $this->db;
        $events = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__icagenda_events') . ' WHERE state IN (0, 1)')->loadResult();
        $cats = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__icagenda_category'))->loadResult();
        $occurrences = \count($this->loadOccurrences());
        $lines = [
            Text::sprintf('COM_JEMMIGRATOR_FOUND', 'iCagenda', $this->getVersion(), $events, $occurrences, $cats),
            Text::sprintf('COM_JEMMIGRATOR_TIMEZONES', Text::_('COM_JEMMIGRATOR_SITE_TIME'), $this->getSiteTimezone()->getName()),
        ];

        $registrations = $this->tableExists('icagenda_registration')
            ? (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__icagenda_registration'))->loadResult()
            : 0;

        if ($registrations) {
            $lines[] = Text::sprintf('COM_JEMMIGRATOR_BOOKINGS_NOT_IMPORTED', $registrations);
        }

        return ['lines' => $lines, 'calendars' => [], 'occurrences' => $occurrences];
    }

    public function loadCategories(): array
    {
        $db = $this->db;
        $categories = [];

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__icagenda_category') . ' WHERE state IN (0, 1) ORDER BY ordering, id')->loadObjectList() as $row) {
            $categories['ic' . (int) $row->id] = [
                'src_id'      => 'ic' . (int) $row->id,
                'src_parent'  => '',
                'level'       => 1,
                'catname'     => (string) $row->title,
                'alias'       => (string) $row->alias,
                'description' => (string) $row->desc,
                'access'      => 1,
                'published'   => (int) $row->state,
                'color'       => (string) $row->color,
                'language'    => (string) ($row->language ?? '') ?: '*',
                'image'       => (string) ($row->image ?? ''),
            ];
        }

        return $categories;
    }

    public function loadOccurrences(): array
    {
        $db = $this->db;
        $occurrences = [];

        foreach ($db->setQuery('SELECT * FROM ' . $db->quoteName('#__icagenda_events') . ' WHERE state IN (0, 1) ORDER BY id')->loadObjectList() as $row) {
            $notes = [];
            $published = ((int) $row->state === 1 && (int) $row->approval === 0) ? 1 : 0;

            if ((int) $row->approval !== 0) {
                $notes[] = Text::_('COM_JEMMIGRATOR_NOTE_APPROVAL');
            }

            foreach (['email' => 'COM_JEMMIGRATOR_FIELD_EMAIL', 'phone' => 'COM_JEMMIGRATOR_FIELD_PHONE', 'file' => 'COM_JEMMIGRATOR_FIELD_FILE'] as $field => $label) {
                if (trim((string) ($row->$field ?? '')) !== '') {
                    $notes[] = Text::sprintf('COM_JEMMIGRATOR_NOTE_NOT_IMPORTED', Text::_($label));
                }
            }

            $showTime = (int) $row->displaytime === 1;
            $slots = [];

            // Single dates: start time only.
            foreach ($this->unserializeDates((string) $row->dates) as $date) {
                $slots[] = [substr($date, 0, 10), $showTime ? substr($date, 11, 5) . ':00' : null, null, null];
            }

            // Period.
            $hasPeriod = strpos((string) $row->startdate, '0000') !== 0 && strpos((string) $row->enddate, '0000') !== 0
                && (string) $row->startdate !== '' && (string) $row->enddate !== '';

            if ($hasPeriod) {
                $startTime = substr((string) $row->startdate, 11, 8);
                $endTime = substr((string) $row->enddate, 11, 8);

                if (trim((string) $row->weekdays) === '') {
                    $slots[] = [substr((string) $row->startdate, 0, 10), $showTime ? $startTime : null,
                        substr((string) $row->enddate, 0, 10), $showTime ? $endTime : null];
                } else {
                    foreach ($this->unserializeDates((string) $row->period) as $date) {
                        $slots[] = [substr($date, 0, 10), $showTime ? $startTime : null, null, $showTime ? $endTime : null];
                    }
                }
            }

            usort($slots, static function ($a, $b) {
                return strcmp($a[0] . $a[1], $b[0] . $b[1]);
            });

            $venue = $this->mapVenue($row);
            $description = trim((string) $row->desc) !== '' ? (string) $row->desc : (string) $row->shortdesc;

            if (trim((string) $row->shortdesc) !== '' && trim((string) $row->desc) !== '') {
                $description = '<p>' . htmlspecialchars((string) $row->shortdesc, ENT_QUOTES, 'UTF-8') . '</p>' . "\n" . $description;
            }

            foreach ($slots as $index => $slot) {
                [$dates, $times, $enddates, $endtimes] = $slot;
                $occurrences[] = [
                    'src_key'     => (int) $row->id . '|' . $dates . ' ' . (string) $times . '|' . $index,
                    'src_series'  => 'ic' . (int) $row->id,
                    'title'       => (string) $row->title,
                    'description' => $description,
                    'url'         => (string) $row->website,
                    'dates'       => $dates,
                    'times'       => $times,
                    'enddates'    => $enddates,
                    'endtimes'    => $endtimes,
                    'published'   => $published,
                    'access'      => (int) $row->access,
                    'created'     => strpos((string) $row->created, '0000') === 0 || (string) $row->created === '' ? null : (string) $row->created,
                    'created_by'  => (int) $row->created_by,
                    'categories'  => ['ic' . (int) $row->catid],
                    'venue'       => $venue,
                    'image_intro' => (string) ($row->image ?? ''),
                    'image_full'  => (string) ($row->image ?? ''),
                    'notes'       => $notes,
                    'status'      => 'ok',
                ];
            }
        }

        return $occurrences;
    }

    private function mapVenue(object $row): ?array
    {
        if (trim((string) $row->place) === '' && trim((string) $row->address) === '') {
            return null;
        }

        $street = trim((string) $row->address);
        $city = trim((string) $row->city);
        $postal = '';

        // The address field often holds "Street, Postcode City".
        if ($street !== '' && strpos($street, ',') !== false) {
            $parts = array_map('trim', explode(',', $street));
            $street = $parts[0];
            $last = (string) end($parts);

            if ($city === '') {
                $city = $last;
            } elseif (preg_match('/^(\d{4}\s?[A-Z]{2}|\d{4,5})\b/u', $last, $m)) {
                $postal = $m[1];
            }
        }

        return $this->splitPostalCode([
            'venue'      => trim((string) $row->place) !== '' ? (string) $row->place : $street,
            'street'     => $street,
            'postalCode' => $postal,
            'city'       => $city,
            'country'    => (string) $row->country,
            'latitude'   => (float) $row->lat != 0.0 ? (float) $row->lat : null,
            'longitude'  => (float) $row->lng != 0.0 ? (float) $row->lng : null,
        ]);
    }

    /**
     * iCagenda stores date lists as serialised arrays of "Y-m-d H:i".
     */
    private function unserializeDates(string $value): array
    {
        if ($value === '' || !preg_match('/^a:\d+:\{/', $value)) {
            return [];
        }

        $dates = @unserialize($value, ['allowed_classes' => false]);

        if (!\is_array($dates)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $dates), static function ($date) {
            return (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $date) && strpos($date, '0000') !== 0;
        }));
    }
}
