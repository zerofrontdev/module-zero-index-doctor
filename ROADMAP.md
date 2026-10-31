# Zero_IndexDoctor — status and what comes next

**Last updated:** 2026-10-31

## Done

- Findings: database settings, Magento's indexers (invalid, stuck, modes,
  changelog backlog, exporters, threads, price dimensions), cron, reindex
  times; admin page and `bin/magento zero:index:doctor [--repair] [--json]`.
- Reindex times recorded by `Plugin\RecordReindex` in
  `zero_index_doctor_run`, kept 90 days.
- ZERO-REVIEW wave 3 (2026-10-31):
  - A8-1 (owner decision D16): without `indexer/use_application_lock` a
    long "working" index is a WARN ("Reindexes possibly stuck") and
    `--repair` keeps it; `--repair --force` releases it. With the lock, a
    locked index is never released, forced or not.
  - A8-L1: a full reindex Magento skips (already "working",
    `WorkingStateProvider`) is not recorded.
  - A8-L2: `History::summary()` reads counts and sums from the database
    and only the last 6 full runs per indexer (`History::assemble()`).
  - A8-L4: `rebuildInvalid()` goes on after a failing index; the command
    prints "Failed X: …" and exits 1.
  - A8-L3: unit tests `Model/RepairTest`, `Model/Check/IndexerCheckTest`
    (`stuck()` through reflection), `Plugin/RecordReindexTest`,
    `Model/HistoryTest`, `Console/Command/DoctorCommandTest` (`--force`
    without `--repair` is INVALID, kept-row text, a failed rebuild exits 1).

- CatalogRuleCheck (2026-10-31): FAIL when `catalogrule_product` holds a
  configurable product (a rule index built outside the adminhtml/crontab
  areas, so variants have no discount). Unit test `CatalogRuleCheckTest`.
- `--repair` in the adminhtml area (2026-10-31): `DoctorCommand` sets the
  adminhtml area (config scope), like `indexer:reindex` (which also loads
  the adminhtml ObjectManager config; no difference for catalogrule),
  before releasing and rebuilding (an area set already is kept, with a
  warning unless it is adminhtml or crontab). `DoctorCommandTest` covers it;
  proved on the local DB: catalogrule_rule rebuilt by `Repair` in the
  global area gave 11 configurables + 2 simples, by
  `zero:index:doctor --repair` 136 simples and no configurables.

## Next, in order

1. [ ] Run `bin/magento zero:index:doctor` (report only) and
       `zero:index:doctor --force` (expect exit 2, INVALID) on a server once
       deployed; `index-doctor-admin` e2e.

## Decisions

- **D16:** without the application lock the doctor reports, it never
  releases on its own; the operator forces it.
- Settings (the database's, `env.php`) are never changed by `--repair`.

## Traps

- `indexer_state.updated` is written once per status change, not while a
  reindex runs: its age says nothing about whether it is still running.
- `--repair` (and any CLI command that sets no area) rebuilds the catalog
  rule index in the global area, without Magento_CatalogRuleConfigurable's
  plugins: only the configurable parents get rows. `DoctorCommand` now sets
  the adminhtml area; anything else that calls `Repair::rebuildInvalid()`
  must set adminhtml or crontab first. `State::emulateAreaCode()` is NOT
  enough: it leaves the config scope (and so the plugin list) unchanged.
- Do not run `--repair` against the local database in tests: it rebuilds
  every invalid index in-process.
