# Compatibility

Nexus Enterprise Import Export targets Laravel 12+ and PHP 8.3+ within the currently supported PHP 8.x range of its spreadsheet dependency.

## PHP

- PHP 8.3: supported by the package constraint and OpenSpout 4.x.
- PHP 8.4: supported by the package constraint and OpenSpout 4.x.
- PHP 8.5: supported by the package constraint and the current OpenSpout 4.x line.

`composer.json` uses `php: ^8.3` and `openspout/openspout: ^4.28`.

OpenSpout 5.x is intentionally not required because its current releases require PHP 8.4+ and would exclude PHP 8.3.

Future PHP minor versions are subject to OpenSpout adding support for those versions.
