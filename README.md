# Zero Index Doctor

What slows Magento's indexers down or stops them, and how to fix each. It is
the check list behind a real case on Zero's 100 000-product stress test:
MariaDB 10.5 with its default optimizer settings rebuilt the category-products
index in **3.5 hours**. With two settings changed it took **12 seconds**.

```
bin/magento zero:index:doctor            # the findings, the worst first, each with its fix
bin/magento zero:index:doctor --json     # the same, for a monitor
bin/magento zero:index:doctor --repair   # release reindexes that died, rebuild the invalid indexes
```

The same list is in the admin under **System > Tools > Index Doctor** (ACL
`Zero_IndexDoctor::doctor`). The page is read-only: fixes run from the
command line, never inside a web request. The command exits with 1 when a
finding failed, so a deploy script or a monitor can act on it.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-index-doctor
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## What it checks

- **The database server.** Is its version one Magento 2.4.8/2.4.9 supports
  (MySQL 8.4, MariaDB 11.4 and later)?
- **MariaDB's optimizer settings.** `in_predicate_conversion_threshold` and
  `optimizer_use_condition_selectivity`:
  - on MariaDB 10.x, a warning with the two lines for `my.cnf`;
  - on 11.x, advice to try them on a copy: MariaDB 11 plans queries
    differently and was not measured.
- **The InnoDB buffer pool** against the size of the store's database.
  The server's 128 MB default on a large database fails.
- **In-memory temporary tables** (`tmp_table_size`, `max_heap_table_size`).
- **Indexes to rebuild,** and **reindexes stuck "working"** for over two
  hours with no lock held: a reindex that died, which the cron then never
  rebuilds.
- **`indexer/use_application_lock`.** Without it, a reindex killed half way
  stays "working" for good.
- **The catalogue indexes on "Update on Save"** with a large catalogue.
- **Changes waiting in the changelogs** of the scheduled indexes.
- **The cron.** Did it run the indexer jobs in the last 15 minutes?
- **Adobe Commerce Services data exporters,** reindexed for nothing when the
  services are not used.
- **Parallel reindexing** (`MAGE_INDEXER_THREADS_COUNT`), and the price
  index's dimensions mode on very large catalogues.
- **Reindex times.** A full rebuild over twice the usual time, and over a
  minute, is reported.

## Reindex times

Every full reindex (from the admin, the CLI or the cron) is timed, as is
every changelog run that took a second or more. The times are kept 90 days
in `zero_index_doctor_run`. The page shows, for each index:

- its last full rebuild;
- its usual time (the median of the five before);
- how many rebuilds there were.

Writing a time never gets in a reindex's way: a failure to write is logged
and dropped.

## What `--repair` does, and doesn't

- **It does:**
  - mark as invalid an index that has been "working" for over two hours and
    holds no lock (as `indexer:reset` would);
  - rebuild every invalid index in Magento's order, dependencies first.
- **It doesn't:** change a setting. The database's settings and `env.php`
  are the operator's call; the findings say what to change.

## Tests

Unit tests (`Test/Unit`) cover the database rules and the slowdown rule. The
e2e test `dev/tests/zero-e2e/tests/index-doctor-admin.spec.ts` (project
`index-doctor-admin`) checks the admin page: the findings come worst first,
each problem has its fix, and the times table is there.

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
