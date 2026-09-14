<?php

namespace Nexus\ImportExport\Examples\Enterprise\Definitions;

use Nexus\ImportExport\Definitions\DataDefinition;
use Nexus\ImportExport\Enums\DuplicateStrategy;
use Nexus\ImportExport\Examples\Enterprise\Models\Brand;
use Nexus\ImportExport\Examples\Enterprise\Models\Category;
use Nexus\ImportExport\Examples\Enterprise\Models\Product;
use Nexus\ImportExport\Fields\Field;
use Nexus\ImportExport\Fields\RelationField;

final class ProductDefinition extends DataDefinition
{
    public function model(): string
    {
        return Product::class;
    }

    public function duplicateStrategy(): DuplicateStrategy
    {
        return DuplicateStrategy::UPSERT;
    }

    public function fields(): array
    {
        return [
            Field::make('sku')->excelColumn('SKU')->required()->unique()->rules(['max:50'])->exportable(),
            Field::make('name')->excelColumn('Name')->required()->rules(['max:255'])->exportable(),
            RelationField::make('category')->column('category_id')->excelColumn('Category')->belongsTo('category', Category::class)
                ->importBy('code')->exportUsing('name')->codeAndLabel()->dropdown()->required()->exportable(),
            RelationField::make('brand')->column('brand_id')->excelColumn('Brand')->belongsTo('brand', Brand::class)
                ->importBy('code')->exportUsing('name')->nullable()->dropdown()->exportable(),
            Field::make('status')->excelColumn('Status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->required()->exportable(),
            Field::make('price')->column('unit_price')->importColumn('New Price')->exportColumn('Price')->templateColumn('Price to Import')
                ->decimal()->required()->rules(['min:0', 'max:99999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'])->exportable(),
        ];
    }
}
