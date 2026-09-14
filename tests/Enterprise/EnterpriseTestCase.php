<?php

namespace Nexus\ImportExport\Tests\Enterprise;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Nexus\ImportExport\Examples\Enterprise\Definitions\EmployeeDefinition;
use Nexus\ImportExport\Examples\Enterprise\Definitions\ProductDefinition;
use Nexus\ImportExport\Examples\Enterprise\Definitions\StudentDefinition;
use Nexus\ImportExport\Examples\Enterprise\Models\Classroom;
use Nexus\ImportExport\Examples\Enterprise\Models\Country;
use Nexus\ImportExport\Examples\Enterprise\Models\School;
use Nexus\ImportExport\Tests\TestCase;

abstract class EnterpriseTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('bulk-imports.definitions', ['students' => StudentDefinition::class, 'products' => ProductDefinition::class, 'employees' => EmployeeDefinition::class]);
        $app['config']->set('import-export.exports.allow_sync', true);
        $app['config']->set('import-export.routes.enabled', true);
        $app['config']->set('import-export.routes.middleware', ['api']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::drop('students');
        (require dirname(__DIR__, 2).'/examples/Enterprise/database/2026_09_05_100000_create_example_resources.php')->up();
        School::query()->insert(['id' => 17, 'code' => 'SCH001', 'name' => 'Future Language School']);
        Classroom::query()->insert(['code' => 'CLS01', 'name' => 'Class One']);
        Country::query()->insert(['code' => 'EG', 'name' => 'Egypt']);
    }

    protected function csv(array $rows, array $headers = ['Student Code', 'Student Name', 'Status', 'School']): UploadedFile
    {
        $file = tmpfile();
        fputcsv($file, $headers, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($file, $row, ',', '"', '');
        }
        rewind($file);
        $data = stream_get_contents($file);
        fclose($file);

        return UploadedFile::fake()->createWithContent('data.csv', $data);
    }
}
