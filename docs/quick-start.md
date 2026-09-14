# Quick start

Copy the [complete StudentDefinition](../examples/Enterprise/Definitions/StudentDefinition.php) into the host, point it at your model classes, and register it:

```php
// config/bulk-imports.php
'definitions' => ['students' => App\Data\StudentDefinition::class],
```

The example has Student Code, Student Name, Email, Phone, Birth Date, Status, School, Class Code, Country Code and Active. It imports school/class/country codes and exports names. Unique student_code drives upserts. `birth_date` maps to `date_of_birth` in the database. Add the corresponding indexes, foreign keys and model belongsTo methods in your application.

Enable import-export.routes and configure an authenticated guard or deliberate guest access. `GET /api/data/resources/students/template` downloads the XLSX template. Upload with multipart `POST /api/data/resources/students/imports` and a file field. Creation returns 202 with the operation ID. Poll `/api/data/imports/{id}` as its creator. Use `/failure-report` when ready.

`POST /api/data/resources/students/exports` accepts declared field names and returns 202. Poll `/api/data/exports/{id}` and download from `/download`. Use `GET /api/data/resources/students/template?with_data=true` for an asynchronous import-compatible export. That response is operation metadata rather than the file itself.

See [queues](queues.md), [authorization](authorization.md), and [API reference](api-reference.md).
