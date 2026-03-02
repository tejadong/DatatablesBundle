# Tejadong Datatables Bundle

Modern server-side processing for [DataTables.js](https://datatables.net/) using Doctrine ORM.

Fully compatible with:

- PHP 8.2+
- Symfony 6.4 / 7+
- Doctrine ORM 3+
- JMS Serializer Bundle

Originally inspired by LanKit/DatatablesBundle.  
Fully modernized and refactored for modern Symfony applications.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [DataTables Column Mapping](#datatables-column-mapping)
- [Join Types](#join-types)
- [Result Types](#result-types)
- [Pre-Filtering Results](#pre-filtering-results)
- [Date Formatting](#date-formatting)
- [DT_RowId and DT_RowClass](#dt_rowid-and-dt_rowclass)
- [Doctrine Paginator](#doctrine-paginator)
- [Security](#security)
- [What’s New (v2.0)](#whats-new-v20)
- [License](#license)

---

## Features

- Automatic mapping of DataTables `mData` to Doctrine entities
- Unlimited nested entity associations via dot notation
- Automatic JOIN handling
- Support for INNER and LEFT joins
- Global and per-column filtering
- Doctrine Paginator support
- Custom pre-filter callbacks
- JSON, Array or Symfony Response output
- Optional `DT_RowId` and `DT_RowClass`
- HTML purification support

---

## Requirements

- PHP 8.2+
- Symfony 6.4 or 7+
- Doctrine ORM 3+
- JMS Serializer Bundle

Install JMS Serializer if not already installed:

```bash
composer require jms/serializer-bundle
```

---

## Installation

Install via Composer:

```bash
composer require tejadong/datatables-bundle
```

The bundle uses Symfony autowiring and requires no manual service configuration.

---

## Basic Usage

Example controller usage:

```php
use Tejadong\DatatablesBundle\Datatables\DatatableManager;
use Tejadong\DatatablesBundle\Datatables\Datatable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends AbstractController
{
    public function datatable(DatatableManager $datatableManager): Response
    {
        $datatable = $datatableManager->getDatatable(
            \App\Entity\Customer::class
        );

        return $datatable->getSearchResults();
    }
}
```

---

## DataTables Column Mapping

Example DataTables configuration:

```javascript
"columns": [
    { "data": "id" },
    { "data": "description" },
    { "data": "customer.firstName" },
    { "data": "customer.lastName" },
    { "data": "customer.location.address" }
]
```

Nested associations are supported without depth limitations.

If an association represents a collection, an array of values will be returned.

---

## Join Types

Default join type is `LEFT`.

Change it globally:

```php
$datatable->setDefaultJoinType(Datatable::JOIN_INNER);
```

Or per column:

```php
$datatable->setJoinType('customer', Datatable::JOIN_LEFT);
```

Available constants:

- `Datatable::JOIN_LEFT`
- `Datatable::JOIN_INNER`

---

## Result Types

By default, `getSearchResults()` returns a Symfony `Response`.

You can request a different format:

```php
// Array
$results = $datatable->getSearchResults(Datatable::RESULT_ARRAY);

// JSON string
$json = $datatable->getSearchResults(Datatable::RESULT_JSON);
```

Available constants:

- `Datatable::RESULT_RESPONSE`
- `Datatable::RESULT_ARRAY`
- `Datatable::RESULT_JSON`

---

## Pre-Filtering Results

You can add custom filtering logic before execution:

```php
$datatable->addWhereBuilderCallback(function ($qb) {
    $qb->andWhere(
        $qb->expr()->eq('Customer.isActive', ':active')
    )->setParameter('active', 1);
});
```

By default, filtered counts reflect pre-filtered results.

To include total records before filtering:

```php
$datatable->hideFilteredCount(false);
```

---

## Date Formatting

If you use MySQL and need `DATE_FORMAT` support via DoctrineExtensions:

```yaml
doctrine:
    orm:
        dql:
            string_functions:
                date_format: DoctrineExtensions\Query\Mysql\DateFormat
```

For JMS Serializer date formatting:

```yaml
jms_serializer:
    handlers:
        datetime:
            default_format: "Y-m-d H:i:s"
```

Or per field via annotation:

```php
use JMS\Serializer\Annotation as Serializer;

/**
 * @Serializer\Type("DateTime<'Y-m-d'>")
 */
```

---

## DT_RowId and DT_RowClass

These special DataTables properties can be enabled:

```php
$datatable
    ->setDtRowClass('custom-class another-class')
    ->useDtRowId(true);
```

By default, these properties are not included in the output.

---

## Doctrine Paginator

Doctrine Paginator is enabled by default.

To disable:

```php
$datatable->useDoctrinePaginator(false);
```

---

## Security

All output data is sanitized using HTMLPurifier before being returned.

---

## What’s New (v2.0)

- Symfony 7 compatibility
- Doctrine ORM 3 support
- PHP 8.2 strict typing
- Removed Container injection
- Removed legacy Symfony 2/3 compatibility
- PSR-4 autoloading
- Modern service configuration
- Deprecated APIs removed

---

## License

MIT License.