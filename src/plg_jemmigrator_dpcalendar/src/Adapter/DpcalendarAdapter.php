<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Plugin\JemMigrator\Dpcalendar\Adapter;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use Joomla\CMS\Language\Text;

/**
 * DPCalendar and DPCalendar Lite (https://github.com/Digital-Peak/DPCalendar-Free).
 *
 * DPCalendar stores a recurring event as a master row (original_id = -1)
 * plus one row per instance (original_id = master id); edited instances
 * keep their own data. Instances and single events (original_id = 0) are
 * imported, masters are skipped. Dates are stored in UTC. Categories are
 * Joomla categories of com_dpcalendar; external calendars (non-numeric
 * catid) are not imported. Locations are linked through
 * #__dpcalendar_events_location; JEM has one venue per event, so the first
 * location is used.
 *
 * Based on the DPCalendar adapter by JKoelman (JEM issue #2371).
 */
final class DpcalendarAdapter extends AbstractAdapter
{
    public function getSource(): string
    {
        return 'dpcalendar';
    }

    public function getLabel(): string
    {
        // The Lite edition ships without the booking table class.
        return is_file(JPATH_ADMINISTRATOR . '/components/com_dpcalendar/src/Table/BookingTable.php') ? 'DPCalendar' : 'DPCalendar Lite';
    }

    public function getElement(): string
    {
        return 'com_dpcalendar';
    }

    public function getDescription(): string
    {
        return Text::_('COM_JEMMIGRATOR_DPCALENDAR_DESC');
    }

    public function isAvailable(): bool
    {
        return $this->tableExists('dpcalendar_events');
    }

    public function getSummary(): array
    {
        $db = $this->db;
        $events = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__dpcalendar_events') . ' WHERE original_id <= 0 AND state IN (0, 1, 2)')->loadResult();
        $instances = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__dpcalendar_events') . ' WHERE original_id >= 0 AND state IN (0, 1, 2)')->loadResult();
        $cats = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__categories') . " WHERE extension = 'com_dpcalendar'")->loadResult();
        $lines = [
            Text::sprintf('COM_JEMMIGRATOR_FOUND', $this->getLabel(), $this->getVersion(), $events, $instances, $cats),
            Text::sprintf('COM_JEMMIGRATOR_TIMEZONES', 'UTC', $this->getSiteTimezone()->getName()),
        ];

        $bookings = $this->tableExists('dpcalendar_bookings')
            ? (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__dpcalendar_bookings'))->loadResult()
            : 0;

        if ($bookings) {
            $lines[] = Text::sprintf('COM_JEMMIGRATOR_BOOKINGS_NOT_IMPORTED', $bookings);
        }

        return ['lines' => $lines, 'calendars' => [], 'occurrences' => $instances];
    }

    public function loadCategories(): array
    {
        return $this->loadJoomlaCategories('com_dpcalendar', 'color');
    }

    public function loadOccurrences(): array
    {
        $db = $this->db;
        $rows = $db->setQuery(
            'SELECT e.* FROM ' . $db->quoteName('#__dpcalendar_events', 'e')
            . ' WHERE e.original_id >= 0 AND e.state IN (0, 1, 2)'
            . ' ORDER BY CASE WHEN e.original_id > 0 THEN e.original_id ELSE e.id END, e.start_date, e.id'
        )->loadObjectList();

        // First location of every event, ordered as stored.
        $locations = [];

        if ($this->tableExists('dpcalendar_events_location') && $this->tableExists('dpcalendar_locations')) {
            $countries = $this->tableExists('dpcalendar_countries')
                ? $db->setQuery('SELECT id, short_code FROM ' . $db->quoteName('#__dpcalendar_countries'))->loadAssocList('id', 'short_code')
                : [];
            $query = 'SELECT r.event_id, l.* FROM ' . $db->quoteName('#__dpcalendar_events_location', 'r')
                . ' INNER JOIN ' . $db->quoteName('#__dpcalendar_locations', 'l') . ' ON l.id = r.location_id ORDER BY r.event_id, l.id';

            foreach ($db->setQuery($query)->loadObjectList() as $row) {
                $locations[(int) $row->event_id][] = [
                    'venue'      => (string) $row->title,
                    'street'     => trim((string) $row->street . ' ' . (string) $row->number),
                    'postalCode' => (string) $row->zip,
                    'city'       => (string) $row->city,
                    'state'      => (string) $row->province,
                    'country'    => (string) ($countries[(int) $row->country] ?? ''),
                    'latitude'   => $row->latitude !== null ? (float) $row->latitude : null,
                    'longitude'  => $row->longitude !== null ? (float) $row->longitude : null,
                    'url'        => (string) $row->url,
                    'image'      => (string) ($row->image ?? ''),
                ];
            }
        }

        $utc = new \DateTimeZone('UTC');
        $occurrences = [];

        foreach ($rows as $row) {
            $notes = [];
            $status = 'ok';
            $images = json_decode((string) ($row->images ?? ''), true) ?: [];

            if ((int) $row->all_day === 1) {
                // All-day dates are stored as dates (00:00, end = last day).
                $dates = substr((string) $row->start_date, 0, 10);
                $enddates = substr((string) $row->end_date, 0, 10);
                $times = $endtimes = null;
            } else {
                [$dates, $times] = $this->toSiteParts((string) $row->start_date, $utc);
                [$enddates, $endtimes] = $this->toSiteParts((string) $row->end_date, $utc);

                if ((int) $row->show_end_time === 0) {
                    $enddates = $dates;
                    $endtimes = null;
                }
            }

            // The instance or event's own location, otherwise the series'.
            $eventLocations = $locations[(int) $row->id] ?? ($locations[(int) $row->original_id] ?? []);

            if (\count($eventLocations) > 1) {
                $notes[] = Text::sprintf('COM_JEMMIGRATOR_NOTE_MORE_LOCATIONS', \count($eventLocations) - 1);
            }

            if (!ctype_digit((string) $row->catid)) {
                $status = 'skip';
                $notes[] = Text::_('COM_JEMMIGRATOR_NOTE_EXTERNAL_CALENDAR');
            }

            if ((int) ($row->capacity ?? 0) > 0 || !empty($row->prices)) {
                $notes[] = Text::sprintf('COM_JEMMIGRATOR_NOTE_NOT_IMPORTED', Text::_('COM_JEMMIGRATOR_FIELD_BOOKING'));
            }

            $occurrences[] = [
                'src_key'     => (string) $row->uid . '|' . (string) $row->start_date . '|' . (int) $row->id,
                'src_series'  => (int) $row->original_id > 0 ? 'dp' . (int) $row->original_id : '',
                'title'       => (string) $row->title,
                'description' => (string) $row->description,
                'url'         => (string) $row->url,
                'dates'       => $dates,
                'times'       => $times,
                'enddates'    => $enddates,
                'endtimes'    => $endtimes,
                // 0 unpublished, 1 published, 2 archived: the same values as JEM.
                'published'   => \in_array((int) $row->state, [0, 1, 2], true) ? (int) $row->state : 0,
                'access'      => (int) $row->access,
                'created'     => ((string) $row->created !== '' && strpos((string) $row->created, '0000') !== 0) ? (string) $row->created : null,
                'created_by'  => (int) $row->created_by,
                'categories'  => [(string) $row->catid],
                'venue'       => $eventLocations[0] ?? null,
                'image_intro' => (string) ($images['image_intro'] ?? ''),
                'image_full'  => (string) ($images['image_full'] ?? ''),
                'notes'       => $notes,
                'status'      => $status,
            ];
        }

        return $occurrences;
    }
}
