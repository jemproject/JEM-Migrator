# JEM Migrator

Migrate events, categories and venues from other Joomla event components into [JEM](https://www.joomlaeventmanager.net).

> **Status: prototype (0.1.3).** Based on the working example in [JEM issue #2371](https://github.com/jemproject/JEM-Project/issues/2371). A controlled JEvents 3.7.0 fixture was migrated on local Joomla 6, but production data, other source components, the installed-package UI route and image-bearing source records still need pilots. Use it only on a copy of your site.

> **Development note:** the checked-in `dist/` ZIPs are still version 0.1.3. Source-tree improvements described below are not in those archives until a new package is built and installed.

## How it works

JEM Migrator has two parts:

```
[Source component]  →  JEM Migrator          →  JEM import format  →  JEM import plugin   →  JEM
 (read only)           reads and converts,      (version 2)            validates, checks       tables
                       preview, batches,                               permissions, saves,
                       progress, log                                   undo per run
```

| Part | Folder | Belongs to | Does |
|---|---|---|---|
| **JEM import plugin** (`plg_jem_import`) | `src/plg_jem_import` | JEM (to be moved into the JEM repository) | The only part that writes to JEM tables. Validates every row with JEM's own rules, checks permissions and the form token, saves in batches, records each step and supports undo. |
| **JEM Migrator** (`com_jemmigrator`) | `src/com_jemmigrator` | This repository | Admin component: detection, options, preview, import in batches with progress, list of runs with undo. Never writes to JEM tables. |
| **Adapters** (`plg_jemmigrator_*`) | `src/plg_jemmigrator_*` | This repository | One plugin per source component. Reads the source tables only and returns normalised data. |

The bundled adapters use a read-only database facade that accepts `SELECT`, `SHOW` and `DESCRIBE` and does not expose write methods. This is an API safeguard, not a sandbox for arbitrary third-party PHP plugins.

## Requirements

- Joomla 5 or 6, PHP 8.1 or later.
- JEM 5.1 or later.

## Installation

1. Install `plg_jem_import_v0.1.3.zip` (the JEM import plugin). It is enabled on install.
2. Install `pkg_jemmigrator_v0.1.3.zip` (JEM Migrator and the adapters). The adapters are enabled on install.
3. Open **Components → JEM Migrator**.

The user needs `core.manage` on JEM Migrator and the JEM permission **Manage tools** (`jem.tools.manage`), the same permission as the JEM import page.

## Using it

1. **Source tabs**: JEvents, DPCalendar and iCagenda are always listed. A workspace opens only when that source component is detected and its adapter plugin is enabled. Undetected sources stay disabled; a detected source with an unavailable adapter has a read-only link to its checks. The first available source opens by default. Its workspace summarises events, categories and venues, then offers source-specific options (calendars where supported, automatic category tree, manual category mapping or one JEM category, venues, local images, past dates). Click **Preview**; nothing is written.
2. **Preview** checks the source, JEM destinations and all dates without writing data. The **Import runs** tab then shows source/JEM/plugin status and the plan for events, categories and venues. Errors block the start button; warnings remain visible for review. The source tab keeps the first 200 detailed preview rows.
3. On **Import runs**, click **Start migration** explicitly. Opening the tab never starts it. The plan is revalidated before the first write, and the source is fingerprinted again before every batch. On an error, execution stops; it never silently continues to the next event or batch.
4. The progress page and **Import runs** show a colour-coded, paginated console of recorded categories, venues, events, series, warnings and batch steps. The last tab lists runs for the selected source. The most recent run across all sources can be undone where its records are still safe to remove. Undo is not a substitute for a database backup.

Importing again later only adds new dates: already imported rows are skipped.

## Testing a migration

This is a prototype. Test only on a staging copy with a restorable backup of both files and database. The source component and JEM must be installed in the same Joomla database. A successful installation does not prove that a complete migration works for your source version or data.

1. Install the two ZIPs in the order above and confirm that the JEM import plugin and the relevant source adapter are enabled.
2. Open the source tab and check the detected component version and the counts for events, categories and venues.
3. Choose the categories, venues and image options, then run **Preview**. Check the proposed category tree and event destinations; correct every blocking error before proceeding.
4. Only on the staging copy, start the migration from **Import runs**. Check the console, the imported JEM records and any skipped or warning rows. Keep the backup even if Undo is available.
5. Report the Joomla, JEM, source-component and Migrator versions, the selected options, the expected and actual counts, and the exact error or console message. Remove personal data from any shared logs or examples.

Without Joomla, run `php tests/smoke.php` for the focused checks. On a local Joomla staging copy, `php tests/local-source-schema.php <site-root>` checks required source tables and columns without writing data. After a migration, `php tests/local-run-audit.php <site-root> <run-id>` checks its ledger, JEM rows and category links without changing them. The other local integration scripts under `tests/` require a configured local Joomla installation and must not be run on a production site.

## Compatibility

| Source | Adapter basis | Verified in this repository | Notes |
|---|---|---|---|
| JEvents | Local installation: 3.7.0 | Four local fixture events and two new JEM categories imported after correcting one inconsistent source category map; run ledger, fields, publication, category links and repeat-preview checked | Locations add-on, event images, attachments and real-world sites untested or unsupported |
| DPCalendar Lite | Original example: 10.12.3 | Adapter schema and migration not verified locally | Paid edition, recurrence and bookings untested |
| iCagenda | Original example: 4.0.16 | Adapter schema and migration not verified locally | Registrations/custom fields not imported |

Other components, including EventList and paid products, are not included in this package. They need an adapter and representative test data.

## What is imported

| Source data | JEM |
|---|---|
| Every date (repetition, instance, single date) | One JEM event |
| Dates of one repeating event | A custom series (`#__jem_event_series`) |
| Categories | Automatic tree: categories with events and their parents. Manual map: all source categories, each optionally connected to an existing JEM category; unconnected children inherit their resolved parent and are created in JEM. Single mode: all events in one JEM category. |
| Location | Venue; an existing same-name venue is reused only when its location fields match. Distinct addresses can become separate venues, while an ambiguous match blocks migration. Country is converted to the ISO code JEM stores. |
| Published / unpublished / archived, access level, author, created date | Kept (author only when the user exists) |
| Local image files in Joomla `images/` or `media/` | Separately selectable for events, categories and venues when the source adapter exposes them. They are copied and normalised with JEM's image profiles into its configured folders; reused JEM records keep their images. |

JEM resolves an event image's custom subfolder after destination categories have been mapped: `{category_alias}` means the first assigned **JEM** category, not the original source category. Category and venue images use their own JEM folder patterns. These patterns select subfolders within JEM's event/category/venue image bases, not arbitrary external paths. Imported images use each active profile's crop/pad ratio and JEM's global maximum image dimension; the per-upload default resolution shown in editing forms is not currently an import target. A local copy test verified all three image profiles, but it did not verify adapter-specific image discovery in an end-to-end import.

Image URLs, including same-site URLs, are never downloaded, copied or assigned. An item with only an image URL has no imported image. The JEvents adapter currently exposes category images but not event or venue image fields. Event attachments are **not yet migrated**: JEM has attachment records, but none of the current source adapters has a verified event-attachment mapping. The UI shows this capability as unavailable rather than silently dropping files. Not imported: bookings, registrations, tickets, prices, custom fields and contact data.

The source-tree UI now offers event and category scope independently, with venues linked to selected events. Category-only imports can create the source category tree. Events without category creation require one existing JEM category. Standalone venue import needs a source adapter and payload format that expose source venue records; it is not implemented yet.

Joomla viewing access-level IDs are carried with event and category records, and with venues when the adapter exposes a venue access level. A same-name venue with incompatible access is not reused. This does **not** yet copy or prove parity of action-level ACL rules between source and JEM; source component and JEM component inheritance may differ. Do not assume action permissions survived migration without a separate ACL comparison.

In manual category mode, select a source category in the left tree and click an existing category in the right JEM tree to connect them. A source parent connected to an existing JEM category is **reused**, not copied; its unconnected descendants are proposed as new categories below it. A child can have its own connection, overriding that inheritance. The diagram shows proposed new categories in green with dashed lines and existing JEM categories in blue with solid lines. Press **Preview** after changes to validate both category assignments and each event's destination before importing.

The source preflight also checks the columns required by its adapter. This guards against unsupported table shapes; it does not by itself prove that a source version has been fully tested. If a same-name venue has incomplete or conflicting location data, review it in the source and JEM before starting the import. The Migrator never guesses which place was intended.

The JEvents adapter also blocks a preview if an event's primary category disagrees with its JEvents category-map table. Correct the source event in JEvents first; the Migrator does not rewrite source data or guess which category is right.

## Security model

- JEM Migrator does not write to JEM content tables directly. The bundled adapters use a read-only facade; installed PHP plugins are trusted code and are not isolated by it.
- The JEM import plugin does not trust its caller. On every write it checks: administrator client, the JEM permission `jem.tools.manage`, no pending JEM ACL migration, and the form token.
- Only the fields defined by the import format are accepted; everything else is dropped. Image references are resolved only to existing local files inside Joomla's `images/` or `media/` directories; no URL is fetched. Image request fields and uploads are explicitly rejected because JEM table stores also inspect the HTTP request.
- Every row goes through `JemImportSecurityHelper` and `JemTableEvent::check()`; unsafe HTML or invalid data becomes a blocking preflight error. A newly encountered write error stops the run.
- Failed batches attempt compensating cleanup because nested-set writes can implicitly commit. The run stops for inspection; it is not automatically retried. Always retain a full backup.

## JEM import format, version 2

The JEM import plugin listens to six events. Each sets the event argument `result`.

| Event | Arguments | Token | Result |
|---|---|---|---|
| `onJemImportInfo` | — | no | `format_versions`, `jem_version`, `ready`, `message` |
| `onJemImportBatch` | `batch` | yes | see below |
| `onJemImportUndo` | `run_id` | yes | `counts` (events, series, venues, categories, kept), `message` |
| `onJemImportStop` | `run_id` | yes | `ok`, `run_id`; marks a running run stopped without deleting its data |
| `onJemImportRuns` | `source` (optional) | no | list of runs (`id`, `source`, `label`, `state`, `counts`, `message`, `created`, `finished`, `author`, `can_undo`) |
| `onJemImportLog` | `run_id`, `offset`, `limit` | no | paginated, persistent console lines for one run |

**Batch**

| Field | Type | Notes |
|---|---|---|
| `format_version` | int | `2`; the import plugin also accepts legacy version `1` without images |
| `source` | string | `[a-z0-9_]{2,50}`, e.g. `jevents` |
| `label` | string | Name shown in the runs list |
| `run_id` | int | `0` starts a new run; the id returned by the first batch continues it |
| `dry_run` | bool | Validate and plan only; nothing is written |
| `last_batch` | bool | Marks the run as finished |
| `options` | object | `category_mode` (`tree`/`manual`/`single`), `parent_catid`, `single_catid`, `category_map` (source category ref to existing JEM category ID, manual mode only), `create_venues`, `copy_event_images`, `copy_category_images`, `copy_venue_images`; the older `copy_images` still enables all three when individual choices are absent |
| `categories` | list | Parents before children; send them with the first batch |
| `events` | list | At most 500 per batch |

**Category**: `ref`, `parent_ref`, `title`, `alias`, `description`, `access`, `published`, `color`, `language`, `image` (local file reference).

**Event**: `ref` (unique within the source), `series_ref` (empty for a single event), `title`, `description`, `url`, `dates` (`Y-m-d`), `times` (`H:i:s` or null), `enddates`, `endtimes` (site time), `published` (0, 1, 2), `access`, `created` (UTC, `Y-m-d H:i:s` or null), `created_by`, `category_refs` (list of category refs), `venue`, `notes` (list of strings), `image_intro`, `image_full` (local file references).

**Venue**: `name`, `street`, `postal_code`, `city`, `state`, `country` (ISO code, alpha-3 or English name), `latitude`, `longitude`, `url`, `image` (local file reference).

**Batch result**: `run_id`, `dry_run`, `rows` (per event: `ref`, `status` = `new` (dry run) / `created` / `skipped` / `error`, `jem_id`, `notes`, plus the values shown in the preview), `categories` (`ref`, `title`, `level`, `status`, `jem_id`), `venues`, `counts`.

## Writing an adapter

1. Create a plugin in the group `jemmigrator` (copy `src/plg_jemmigrator_icagenda`).
2. Extend `JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter` and implement `getSource()`, `getLabel()`, `getElement()`, `isAvailable()`, `getSummary()`, `loadCategories()` and `loadOccurrences()`. The normalised fields are documented in `AbstractAdapter`.
3. Add the adapter to the `sources` argument of `onJemMigratorCollectSources`.

Guidelines:

- Test data obtained legally (GPL package, test licence or anonymised dump).
- An entry in the compatibility table with the tested versions.
- An automated test with sample data.
- A clear list of what is not imported.

## Building

```bash
php build/build.php
```

Creates `dist/plg_jem_import_v<version>.zip` and `dist/pkg_jemmigrator_v<version>.zip`.

A smoke test of the parts that do not need Joomla (format validation, read-only database, payload building) runs with:

```bash
php tests/smoke.php
```

## Credits

The adapters for JEvents, DPCalendar and iCagenda and the import logic are based on the work of [JKoelman](https://github.com/JKoelman) in [JEM issue #2371](https://github.com/jemproject/JEM-Project/issues/2371).

## License

GNU General Public License version 3 or later. See [LICENSE](LICENSE).

Please report suspected vulnerabilities privately as described in [SECURITY.md](SECURITY.md), not in a public issue.
