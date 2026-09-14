# Repository continuity

The current live tree was verified as the working standalone nexus/enterprise-import-export package before this restoration. Existing imports, exports, templates, DataDefinition/Field/RelationField, mappings, creator authorization and 50 passing tests were retained. No uploaded/downloaded ZIP was extracted or used to replace the live tree.

Conventions are PHP 8.3+, Illuminate/Laravel 12, PSR-4 Nexus\ImportExport, native enums, DTOs, Laravel provider/routes/jobs/events, Eloquent/query-builder bulk operations, OpenSpout streaming and PHPUnit/Testbench. There is no host application, production database, tenancy library, Redis/Horizon or authorization package to rewrite. A local Git baseline makes changes reviewable; no remote repository was modified.

The restoration adds the missing guest/system/context access and notification layer, independent-process regression tests and measured benchmark tooling. All final claims are tied to files/logs in this tree rather than earlier conversation counts. See VERIFICATION.md, performance.md and the documentation index.
