<?php

namespace Nexus\ImportExport\Fields;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Nexus\ImportExport\Contracts\OptionsProvider;
use Nexus\ImportExport\Enums\OptionInput;
use Nexus\ImportExport\Exceptions\ImportConfigurationException;
use Nexus\ImportExport\Exceptions\RejectRow;

class Field
{
    public string $databaseColumn;

    public string $importHeader;

    public string $exportHeader;

    public string $templateHeader;

    public string $type = 'string';

    public string $help = '';

    public bool $isRequired = false;

    public bool $isNullable = false;

    public bool $isUnique = false;

    public bool $canImport = true;

    public bool $canExport = false;

    public bool $inTemplate = true;

    public bool $hasDefault = false;

    public mixed $defaultValue = null;

    public mixed $sample = null;

    public array $headerAliases = [];

    public array $extraRules = [];

    public mixed $optionSource = null;

    public OptionInput $optionInput = OptionInput::BOTH;

    public bool $useLabels = true;

    public ?Closure $normalizer = null;

    public ?Closure $formatter = null;

    public ?Closure $computer = null;

    public array $eagerLoads = [];

    public ?string $exportPath = null;

    private ?array $resolvedOptions = null;

    final protected function __construct(public readonly string $name)
    {
        $this->databaseColumn = $name;
        $this->excelColumn($name);
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function column(string $column): static
    {
        $this->databaseColumn = $column;

        return $this;
    }

    public function excelColumn(string $header): static
    {
        $this->importHeader = $this->exportHeader = $this->templateHeader = $header;

        return $this;
    }

    public function label(string $label): static
    {
        return $this->excelColumn($label);
    }

    public function importColumn(string $header): static
    {
        $this->importHeader = $header;

        return $this;
    }

    public function exportColumn(string $header): static
    {
        $this->exportHeader = $header;

        return $this;
    }

    public function templateColumn(string $header): static
    {
        $this->templateHeader = $header;

        return $this;
    }

    public function description(string $text): static
    {
        $this->help = $text;

        return $this;
    }

    public function instructions(string $text): static
    {
        return $this->description($text);
    }

    public function required(bool $value = true): static
    {
        $this->isRequired = $value;

        return $this;
    }

    public function nullable(bool $value = true): static
    {
        $this->isNullable = $value;

        return $this;
    }

    public function unique(bool $value = true): static
    {
        $this->isUnique = $value;

        return $this;
    }

    public function aliases(array $aliases): static
    {
        $this->headerAliases = $aliases;

        return $this;
    }

    public function rules(array $rules): static
    {
        $this->extraRules = $rules;

        return $this;
    }

    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->defaultValue = $value;

        return $this;
    }

    public function example(mixed $value): static
    {
        $this->sample = $value;

        return $this;
    }

    public function importable(bool $value = true): static
    {
        $this->canImport = $value;

        return $this;
    }

    public function exportable(bool $value = true): static
    {
        $this->canExport = $value;

        return $this;
    }

    public function templateVisible(bool $value = true): static
    {
        $this->inTemplate = $value;

        return $this;
    }

    public function importOnly(): static
    {
        $this->canImport = true;
        $this->canExport = false;

        return $this;
    }

    public function exportOnly(): static
    {
        $this->canImport = $this->inTemplate = false;
        $this->canExport = true;

        return $this;
    }

    public function templateOnly(): static
    {
        $this->canImport = $this->canExport = false;
        $this->inTemplate = true;

        return $this;
    }

    public function hidden(): static
    {
        $this->canImport = $this->canExport = $this->inTemplate = false;

        return $this;
    }

    public function internal(): static
    {
        return $this->hidden();
    }

    public function string(): static
    {
        $this->type = 'string';

        return $this;
    }

    public function text(): static
    {
        $this->type = 'text';

        return $this;
    }

    public function integer(): static
    {
        $this->type = 'integer';

        return $this;
    }

    public function decimal(): static
    {
        $this->type = 'decimal';

        return $this;
    }

    public function boolean(): static
    {
        $this->type = 'boolean';

        return $this;
    }

    public function date(): static
    {
        $this->type = 'date';

        return $this;
    }

    public function datetime(): static
    {
        $this->type = 'datetime';

        return $this;
    }

    public function email(): static
    {
        $this->type = 'email';

        return $this;
    }

    public function phone(): static
    {
        $this->type = 'phone';

        return $this;
    }

    public function uuid(): static
    {
        $this->type = 'uuid';

        return $this;
    }

    public function normalizeUsing(Closure $callback): static
    {
        $this->normalizer = $callback;

        return $this;
    }

    public function formatUsing(Closure $callback): static
    {
        $this->formatter = $callback;

        return $this;
    }

    public function computed(Closure $callback, array $eagerLoads = []): static
    {
        $this->computer = $callback;
        $this->eagerLoads = $eagerLoads;

        return $this->exportOnly();
    }

    public function exportFrom(string $path): static
    {
        $this->exportPath = $path;

        return $this->exportOnly();
    }

    public function options(array|string|OptionsProvider|Closure $source, OptionInput $input = OptionInput::BOTH): static
    {
        $this->optionSource = $source;
        $this->optionInput = $input;
        $this->resolvedOptions = null;

        return $this;
    }

    public function exportLabels(bool $enabled = true): static
    {
        $this->useLabels = $enabled;

        return $this;
    }

    public function optionValues(array $context): array
    {
        if ($this->resolvedOptions !== null) {
            return $this->resolvedOptions;
        }
        $source = $this->optionSource;
        if ($source === null) {
            return $this->resolvedOptions = [];
        }
        $limit = (int) config('import-export.options_limit', 5000);
        if (is_string($source) && enum_exists($source)) {
            $items = [];
            foreach ($source::cases() as $case) {
                $items[$case instanceof BackedEnum ? $case->value : $case->name] = $case->name;
            }
        } else {
            if (is_string($source)) {
                $source = app($source);
            }
            $items = match (true) {
                $source instanceof OptionsProvider => $source->options($context, $limit + 1),
                $source instanceof Closure => $source($context, $limit + 1),
                is_array($source) => $source,
                default => throw new ImportConfigurationException('Options must use an enum, array, closure, or OptionsProvider.'),
            };
        }
        $values = [];
        foreach ($items as $value => $label) {
            if (count($values) >= $limit || ! is_scalar($label) || strlen((string) $label) > 1000) {
                throw new ImportConfigurationException("Option set for [{$this->name}] exceeds its bounded size or contains an invalid label.");
            }
            $values[$value] = (string) $label;
        }
        if (count(array_unique($values, SORT_STRING)) !== count($values)) {
            throw new ImportConfigurationException("Duplicate option labels for [{$this->name}].");
        }
        if ($this->optionInput === OptionInput::BOTH) {
            foreach ($values as $key => $label) {
                if (array_key_exists($label, $values) && (string) $key !== $label) {
                    throw new ImportConfigurationException("Ambiguous option key/label for [{$this->name}].");
                }
            }
        }

        return $this->resolvedOptions = $values;
    }

    public function normalize(mixed $value, array $context): mixed
    {
        if ($value instanceof DateTimeInterface) {
            $value = $value->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === '') {
            $value = null;
        }
        if ($value === null && $this->hasDefault) {
            $value = $this->defaultValue;
        }
        if ($this->normalizer !== null) {
            $value = ($this->normalizer)($value, $context);
        }
        if ($value === null) {
            return null;
        }
        $options = $this->optionValues($context);
        if ($options !== []) {
            $key = is_bool($value) ? (int) $value : $value;
            if ($this->optionInput !== OptionInput::LABEL && array_key_exists($key, $options)) {
                return (string) $key;
            }
            if ($this->optionInput !== OptionInput::VALUE) {
                $key = array_search((string) $value, $options, true);
                if ($key !== false) {
                    return (string) $key;
                }
            }
            throw new RejectRow('invalid_option', 'Choose a configured option value.', $this->name);
        }
        if ($this->type === 'boolean') {
            return match (mb_strtolower((string) $value)) {
                '1', 'true', 'yes', 'y', 'on' => 1,
                '0', 'false', 'no', 'n', 'off', '' => 0,
                default => $value,
            };
        }
        if ($this->type === 'email') {
            return mb_strtolower((string) $value);
        }
        if ($this->type === 'date' || $this->type === 'datetime') {
            $format = $this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s';
            $date = DateTimeImmutable::createFromFormat('!'.$format, (string) $value);
            if ($date === false || $date->format($format) !== (string) $value) {
                throw new RejectRow('invalid_date', "Expected {$format}.", $this->name);
            }
        }

        return in_array($this->type, ['string', 'text', 'phone', 'relation', 'uuid'], true) && is_scalar($value) ? (string) $value : $value;
    }

    public function validationRules(array $context): array
    {
        $rules = [$this->isRequired ? 'required' : 'sometimes'];
        if ($this->isNullable) {
            $rules[] = 'nullable';
        }
        $rules[] = match ($this->type) {
            'text', 'phone', 'relation' => 'string',
            'decimal' => 'numeric',
            'datetime' => 'date_format:Y-m-d H:i:s',
            'date' => 'date_format:Y-m-d',
            default => $this->type,
        };
        $options = $this->optionValues($context);
        if ($options !== []) {
            $rules[] = Rule::in(array_keys($options));
        }
        foreach ($this->extraRules as $rule) {
            if ((is_string($rule) && preg_match('/(?:^|\|)(?:unique|exists)(?::|$)/i', $rule))
                || $rule instanceof Unique || $rule instanceof Exists) {
                throw new ImportConfigurationException('Use unique keys and RelationField instead of per-row database validation rules.');
            }
            $rules[] = $rule;
        }

        return $rules;
    }

    public function format(mixed $value, array $context, bool $compatible = false): mixed
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            $value = $value->format($this->type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }
        $options = $this->optionValues($context);
        if ($value !== null && $options !== [] && (($compatible && $this->optionInput === OptionInput::LABEL) || (! $compatible && $this->useLabels))) {
            $value = $options[is_bool($value) ? (int) $value : $value] ?? $value;
        } elseif ($options === [] && $this->type === 'boolean' && $value !== null) {
            $value = $compatible ? (int) (bool) $value : ($value ? 'Yes' : 'No');
        }
        if (! $compatible && $this->formatter !== null) {
            return ($this->formatter)($value, $context);
        }

        return $value;
    }
}
