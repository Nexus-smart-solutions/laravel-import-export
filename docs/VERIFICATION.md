# Verification

The original pre-rename release was verified on PHP 8.4 with 81 tests / 333 assertions, PHPStan/Larastan level 5, Pint, and strict Composer validation passing.

This compatibility/rename release changes the Composer package name to `nexus/enterprise-import-export`, the namespace to `Nexus\\ImportExport`, and the PHP constraint to `^8.3`. OpenSpout is moved to the 4.x line (`^4.28`) so PHP 8.3 remains installable.

The source in this archive has been syntax-checked after the rename. Because dependency installation is not bundled in the release archive, run the normal project checks after `composer install` in the target environment:

```bash
composer validate --strict
php vendor/bin/phpunit --exclude-group performance
php vendor/bin/phpstan analyse --memory-limit=512M
php vendor/bin/pint --test
```

The CI matrix now covers PHP 8.3, 8.4, and 8.5 with Laravel 12. No large benchmark rerun is required for this naming/compatibility change.
