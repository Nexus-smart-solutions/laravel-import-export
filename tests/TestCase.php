<?php

namespace Nexus\ImportExport\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nexus\ImportExport\ImportExportServiceProvider;
use Nexus\ImportExport\Examples\Imports\AtomicStudentsImport;
use Nexus\ImportExport\Examples\Imports\StudentsImport;
use Nexus\ImportExport\Examples\Models\Course;
use Nexus\ImportExport\Tests\Support\TestUser;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ImportExportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('filesystems.default', 'imports-test');
        $app['config']->set('filesystems.disks.imports-test', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/imports-test'),
            'throw' => false,
        ]);
        $app['config']->set('bulk-imports.files.disk', 'imports-test');
        $app['config']->set('bulk-imports.queue.allow_sync', true);
        $app['config']->set('bulk-imports.routes.enabled', true);
        $app['config']->set('bulk-imports.routes.middleware', ['api']);
        $app['config']->set('bulk-imports.definitions', [
            'students' => StudentsImport::class,
            'atomic_students' => AtomicStudentsImport::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports-test');
        $this->migratePackage();
        $this->migrateHostFixtures();
    }

    protected function createActor(string $name = 'Admin'): TestUser
    {
        return TestUser::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test']);
    }

    protected function seedCourses(): void
    {
        foreach (range(1, 10) as $index) {
            Course::query()->create([
                'code' => sprintf('COURSE-%02d', $index),
                'name' => "Course {$index}",
            ]);
        }
    }

    /** @param list<list<string|null>> $rows */
    protected function uploadedCsv(array $rows, string $name = 'students.csv'): UploadedFile
    {
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, ['student_code', 'name', 'email', 'phone', 'date_of_birth', 'course_code'], ',', '"', '', "\n");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '', "\n");
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function migratePackage(): void
    {
        foreach (glob(dirname(__DIR__).'/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
    }

    private function migrateHostFixtures(): void
    {
        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
        Schema::create('tenant_students', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('student_code', 50);
            $table->string('name');
            $table->timestamps();
            $table->unique(['organization_id', 'student_code']);
        });
        (require dirname(__DIR__).'/examples/database/migrations/2026_01_01_100001_create_courses_table.php')->up();
        (require dirname(__DIR__).'/examples/database/migrations/2026_01_01_100002_create_students_table.php')->up();
    }
}
