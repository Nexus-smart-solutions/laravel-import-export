# Release notes

## Nexus Enterprise Import Export

This archive is the renamed compatibility release of the existing package; it is not a rewrite.

### New public identity

- Composer package: `nexus/enterprise-import-export`
- PHP namespace: `Nexus\\ImportExport`
- Service provider: `Nexus\\ImportExport\\ImportExportServiceProvider`
- PHP: `^8.3`
- Laravel: `^12.0` components / Laravel 12+
- OpenSpout: `^4.28`
- License: proprietary

### Preserved runtime contracts

The package intentionally keeps existing database tables/migrations, REST `/api/data/...` flows, queue behavior, and legacy `bulk-imports` config/container identifiers where changing them would create migration or host-application breakage.

### Local verification after installing dependencies

```bash
composer install
composer validate --strict
php vendor/bin/phpunit --exclude-group performance
php vendor/bin/phpstan analyse --memory-limit=512M
php vendor/bin/pint --test
```

The included GitHub Actions matrix runs the normal suite on PHP 8.3, 8.4, and 8.5.
