# Changelog: zerofront/module-zero-index-doctor

## 1.3.6

- New check: catalog price rules on configurable products. A configurable product in `catalogrule_product` means a rule reindex ran without Magento's configurable plugins and the variants lost the discount; the fix is to reindex `catalogrule_rule`.
- `--repair` now runs in the admin area, as `indexer:reindex` does, so the catalog rule index it rebuilds gives variants their discount.
- Without `indexer/use_application_lock`, an index stuck in "working" is reported as possibly stuck and kept; `--repair --force` releases it once you have checked nothing is reindexing. `--force` alone is refused.
- `--repair` reports an index that fails to rebuild and goes on with the others; the exit code is then 1.
- A full reindex that Magento skips (the index is already working) is no longer recorded as a run.

Earlier releases (1.3.5 and before): see the Git tags.
