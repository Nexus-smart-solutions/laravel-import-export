# Options, enums and providers

`options(['active'=>'Active','inactive'=>'Inactive'])` maps stored values to labels. Imports accept both by default (`OptionInput::BOTH`); select VALUE or LABEL deliberately. Exports emit labels by default; exportLabels(false) selects raw values. Compatible exports use an accepted input representation.

A backed enum class, array, Closure, OptionsProvider instance or OptionsProvider class is supported. Implement `options(array $context, int $limit): iterable`. Providers receive the trusted context and a bound; use a query limit/generator rather than loading a huge lookup table. A config-backed source can return a bounded config array from a callback. Dynamic provider results are cached per field instance; configuration/metadata requests should not force massive dropdown queries.

`import-export.options_limit` defaults to 5000. Oversized/ambiguous option sets are rejected. Options normalize before Laravel row validation; they also populate template reference values and instructions. Database relation choices have a separate bounded lookup/dropdown path. See [template dropdowns](template-dropdowns.md).
