<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Plugin\JemMigrator\Jevents\Adapter;

\defined('_JEXEC') or die;

use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;

/**
 * JEvents (https://github.com/jevents/jevents).
 *
 * Every row of #__jevents_repetition becomes one JEM event, so changed and
 * deleted repeats are taken over exactly; repeats of one JEvents event form
 * a JEM series. Categories are Joomla categories of com_jevents (plus
 * #__jevents_catmap for multiple categories); the location is free text, or
 * a #__jev_locations id with the Locations add-on.
 *
 * Based on the JEvents adapter by JKoelman (JEM issue #2371).
 */
final class JeventsAdapter extends AbstractAdapter
{
    public function getSource(): string
    {
        return 'jevents';
    }

    public function getLabel(): string
    {
        return 'JEvents';
    }

    public function getElement(): string
    {
        return 'com_jevents';
    }

    public function getDescription(): string
    {
        return Text::_('COM_JEMMIGRATOR_JEVENTS_DESC');
    }

    public function getMediaCapabilities(): array
    {
        return [
            'event_images' => false,
            'category_images' => true,
            'venue_images' => false,
            'event_attachments' => false,
        ];
    }

    public function isAvailable(): bool
    {
        return $this->tableHasColumns('jevents_vevent', ['ev_id', 'uid', 'catid', 'state', 'access', 'created', 'created_by', 'icsid'])
            && $this->tableHasColumns('jevents_vevdetail', ['evdet_id', 'summary', 'description', 'location', 'geolat', 'geolon', 'url', 'dtstartraw', 'noendtime', 'contact'])
            && $this->tableHasColumns('jevents_repetition', ['rp_id', 'eventid', 'eventdetail_id', 'startrepeat', 'endrepeat'])
            && $this->optionalTableHasColumns('jevents_catmap', ['evid', 'catid', 'ordering'])
            && $this->optionalTableHasColumns('jevents_icsfile', ['ics_id', 'label'])
            && $this->optionalTableHasColumns('jev_locations', ['loc_id']);
    }

    public function getSummary(): array
    {
        $db = $this->db;
        $calendars = [];

        if ($this->tableExists('jevents_icsfile')) {
            foreach ($db->setQuery('SELECT f.ics_id, f.label, COUNT(v.ev_id) AS events FROM ' . $db->quoteName('#__jevents_icsfile', 'f')
                . ' LEFT JOIN ' . $db->quoteName('#__jevents_vevent', 'v') . ' ON v.icsid = f.ics_id GROUP BY f.ics_id, f.label ORDER BY f.ics_id')->loadObjectList() as $row) {
                $calendars[] = ['id' => (int) $row->ics_id, 'label' => (string) $row->label, 'events' => (int) $row->events];
            }
        }

        $events = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__jevents_vevent'))->loadResult();
        $repeats = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__jevents_repetition'))->loadResult();
        $cats = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__categories') . " WHERE extension = 'com_jevents'")->loadResult();

        return [
            'lines' => [
                Text::sprintf('COM_JEMMIGRATOR_FOUND', 'JEvents', $this->getVersion(), $events, $repeats, $cats),
                Text::sprintf('COM_JEMMIGRATOR_TIMEZONES', $this->getSourceTimezone()->getName(), $this->getSiteTimezone()->getName()),
            ],
            'calendars'   => $calendars,
            'occurrences' => $repeats,
        ];
    }

    public function loadCategories(): array
    {
        return $this->loadJoomlaCategories('com_jevents', 'catcolour');
    }

    public function loadOccurrences(): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select('r.rp_id, r.eventid AS ev_id, r.startrepeat, r.endrepeat')
            ->select('v.uid, v.catid, v.state, v.access, v.created, v.created_by')
            ->select('d.summary, d.description, d.location, d.geolat, d.geolon, d.url, d.dtstartraw, d.noendtime, d.contact')
            ->from($db->quoteName('#__jevents_repetition', 'r'))
            ->join('INNER', $db->quoteName('#__jevents_vevent', 'v') . ' ON v.ev_id = r.eventid')
            ->join('INNER', $db->quoteName('#__jevents_vevdetail', 'd') . ' ON d.evdet_id = r.eventdetail_id')
            ->where('v.state IN (0, 1)')
            ->order('r.eventid, r.startrepeat, r.rp_id');

        if (!empty($this->options['calendars'])) {
            $query->where('v.icsid IN (' . implode(',', array_map('intval', (array) $this->options['calendars'])) . ')');
        }

        $catMap = [];
        if ($this->tableExists('jevents_catmap')) {
            foreach ($db->setQuery('SELECT evid, catid FROM ' . $db->quoteName('#__jevents_catmap') . ' ORDER BY ordering')->loadObjectList() as $row) {
                $catMap[(int) $row->evid][] = (string) $row->catid;
            }
        }

        $source = $this->getSourceTimezone();
        $siteName = $this->getSiteTimezone()->getName();
        $occurrences = [];

        foreach ($db->setQuery($query)->loadObjectList() as $row) {
            $notes = [];
            $mappedCategories = $catMap[(int) $row->ev_id] ?? [];
            $primaryCategory = (string) $row->catid;

            if ($mappedCategories && !\in_array($primaryCategory, $mappedCategories, true)) {
                $sourceCategories = $this->loadCategories();
                $categoryLabel = static function (string $id) use ($sourceCategories): string {
                    $name = trim((string) ($sourceCategories[$id]['catname'] ?? ''));

                    return $name === '' ? '#' . $id : $name . ' (#' . $id . ')';
                };

                throw new \RuntimeException(Text::sprintf(
                    'COM_JEMMIGRATOR_JEVENTS_CATEGORY_CONFLICT',
                    (int) $row->ev_id,
                    (string) $row->summary,
                    $categoryLabel($primaryCategory),
                    implode(', ', array_map($categoryLabel, $mappedCategories))
                ));
            }

            $start = new \DateTimeImmutable((string) $row->startrepeat, $source);
            $end = new \DateTimeImmutable((string) $row->endrepeat, $source);
            $allDay = \strlen((string) $row->dtstartraw) === 8
                || ($start->format('H:i:s') === '00:00:00' && $end->format('H:i:s') === '23:59:59');

            if ($allDay) {
                // Dates only, no timezone conversion.
                $dates = $start->format('Y-m-d');
                $enddates = $end->format('Y-m-d');
                $times = $endtimes = null;
            } else {
                [$dates, $times] = $this->toSiteParts((string) $row->startrepeat, $source);
                [$enddates, $endtimes] = $this->toSiteParts((string) $row->endrepeat, $source);

                if ((int) $row->noendtime === 1) {
                    $enddates = $dates;
                    $endtimes = null;
                }

                if ($source->getName() !== $siteName) {
                    $notes[] = Text::sprintf('COM_JEMMIGRATOR_NOTE_TIMEZONE', $source->getName(), $siteName);
                }
            }

            if (trim((string) $row->contact) !== '') {
                $notes[] = Text::sprintf('COM_JEMMIGRATOR_NOTE_NOT_IMPORTED', Text::_('COM_JEMMIGRATOR_FIELD_CONTACT'));
            }

            $occurrences[] = [
                'src_key'     => (string) $row->uid . '|' . (string) $row->startrepeat . '|' . (int) $row->rp_id,
                'src_series'  => 'jev' . (int) $row->ev_id,
                'title'       => (string) $row->summary,
                'description' => (string) $row->description,
                'url'         => (string) $row->url,
                'dates'       => $dates,
                'times'       => $times,
                'enddates'    => $enddates,
                'endtimes'    => $endtimes,
                'published'   => (int) $row->state === 1 ? 1 : 0,
                'access'      => (int) $row->access,
                // JEvents writes "created" as wall-clock time in its own timezone.
                'created'     => $this->toUtc((string) $row->created, $source),
                'created_by'  => (int) $row->created_by,
                'categories'  => $mappedCategories ?: [$primaryCategory],
                'venue'       => $this->mapLocation((string) $row->location, (float) $row->geolat, (float) $row->geolon),
                'notes'       => $notes,
                'status'      => 'ok',
            ];
        }

        return $occurrences;
    }

    private function mapLocation(string $location, float $lat, float $lon): ?array
    {
        $location = trim($location);

        if ($location !== '' && ctype_digit($location) && $this->tableExists('jev_locations')) {
            // JEvents Locations add-on (commercial; field names not verified)
            $row = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__jev_locations') . ' WHERE loc_id = ' . (int) $location)->loadObject();

            if ($row) {
                return [
                    'venue'      => (string) ($row->title ?? ''),
                    'street'     => (string) ($row->street ?? ''),
                    'postalCode' => (string) ($row->postcode ?? ''),
                    'city'       => (string) ($row->city ?? ''),
                    'latitude'   => !empty($row->geolat) ? (float) $row->geolat : null,
                    'longitude'  => !empty($row->geolon) ? (float) $row->geolon : null,
                ];
            }
        }

        $venue = $this->parseLocationText($location);

        if ($venue && ($lat != 0.0 || $lon != 0.0)) {
            $venue['latitude'] = $lat;
            $venue['longitude'] = $lon;
        }

        return $venue;
    }

    /**
     * JEvents stores repetitions as wall-clock time in its own timezone
     * setting ("icaltimezonelive"); without it, in the PHP default timezone.
     */
    private function getSourceTimezone(): \DateTimeZone
    {
        $name = '';

        try {
            $name = (string) ComponentHelper::getParams('com_jevents')->get('icaltimezonelive', '');
        } catch (\Throwable $e) {
        }

        try {
            return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }
}
