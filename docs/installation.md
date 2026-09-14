# Installation and local development

Use PHP 8.3+, Laravel 12, Composer, and the fileinfo/mbstring/ZIP/DOM/XML extensions required by the streaming reader/writer. In this repository:

```bash
composer require nexus-smart-solutions/laravel-import-export
```


Then run `composer require nexus/enterprise-import-export:@dev`. Composer discovers ImportExportServiceProvider. Publish configuration:

```bash
php artisan vendor:publish --tag=bulk-imports-config
php artisan vendor:publish --tag=import-export-config
php artisan migrate
```

All package migrations auto-load. `import-export-migrations` publishes every migration (the legacy `bulk-imports-migrations` tag is also retained) when the host needs copies; do not install renamed duplicate copies of migrations already applied. Example business models/migrations are not auto-installed. A host must provide Laravel's job_batches migration for Bus batches and normal queue/failed_jobs infrastructure for its chosen driver. For database notifications provide Laravel's notification table separately.

Use asynchronous queues outside tests; sync processing is disabled by default. Configure one shared private disk reachable by all workers. APP_KEY is required for encrypted verified guest addresses. Native MySQL/PostgreSQL, Horizon, object-storage and host policy integration need deployment validation.
