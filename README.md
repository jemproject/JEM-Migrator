# JEM Migrator

Migrate events, categories and venues from other Joomla event components into [JEM](https://www.joomlaeventmanager.net) (Joomla Event Manager).

> **Status:** the first version (0.1.0) is being reviewed. The code will be published here soon.

## What it will do

- Migrate from **JEvents**, **DPCalendar / DPCalendar Lite** and **iCagenda**, with more components to follow.
- Show a **preview** before anything is saved.
- Import in **batches**, so large calendars work on any hosting.
- Let you **import again** later (only new dates are added) and **undo** an import.
- Read the other component only; it is never changed.

## How it relates to JEM

JEM Migrator is a separate extension, so one-time migration code does not have to be part of JEM. It reads the source component and sends the data to the **JEM import plugin**, which is part of JEM and is the only part that saves data in JEM, using JEM's own checks and permissions.

## Background

The proposal and the first migration adapters come from [JEM issue #2371](https://github.com/jemproject/JEM-Project/issues/2371), based on the work of [@JKoelman](https://github.com/JKoelman).

## License

GNU General Public License version 3 or later.
