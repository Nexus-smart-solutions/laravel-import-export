# Nexus Enterprise Import Export — compatibility rename release

Current package identity:

- Composer: `nexus/enterprise-import-export`
- Namespace: `Nexus\\ImportExport`
- PHP constraint: `^8.3`
- Spreadsheet dependency: `openspout/openspout ^4.28`
- Laravel: 12+
- License: proprietary, Nexus Smart Solutions

The original pre-rename release baseline passed 81 tests / 333 assertions, PHPStan/Larastan level 5, Pint, and Composer strict validation. This renamed source tree has been PHP syntax-checked across 209 package/test/example PHP files. CI is configured for PHP 8.3, 8.4, and 8.5.

No large benchmark rerun was performed for this naming/compatibility change. Existing benchmark evidence remains in `docs/performance.md`.
