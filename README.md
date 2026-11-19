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

The column names, sorting and paging parameters come from the client (`mDataProp_N`, `iSortCol_N`, `sSortDir_N`,
`iDisplayLength`...) and are used to build the DQL query, so they are validated:

- **Column names** (`mDataProp_N`) must be identifiers separated by dots (`customer.location.address`). Any other
  character (spaces, parentheses, quotes...) is rejected with a `400 Bad Request`
  (`Symfony\Component\HttpKernel\Exception\BadRequestHttpException`), as is a relation or property that does not exist.
- **Columns that are not an entity field** (actions, images, computed totals...) are *virtual*: they are returned as
  usual but never used to sort, search or select.
- **Sorting**: the direction is normalized to `asc` / `desc`; unknown or out-of-range column indexes are ignored.
- **Search**: the search text is always bound as a parameter, and `%`, `_` and `\` are escaped so they are matched
  literally (they are not wildcards). A search for `0` is a valid search.
- **Numbers** (`sEcho`, `iColumns`, `iDisplayStart`, `iDisplayLength`, `iSortingCols`...) must be integers and at most
  100 columns are processed.
- **Page size**: optionally limit the rows per page (it also limits the "all" option, `iDisplayLength = -1`):

  ```yaml
  tejadong_datatables:
      datatable:
          max_page_length: 5000   # 0 = no limit (default)
  ```

All output data is sanitized using HTMLPurifier before being returned. Its definition cache is stored in the system
temporary directory (not inside `vendor/`).

Use the bundle only on endpoints that already check access (roles / voters): the pre-filter callbacks
(`addWhereBuilderCallback`) are the place to restrict which rows each user can see.

---

## What’s New (v2.0.5)

- Security: the column name no longer reaches the DQL unvalidated (DQL injection through `mDataProp_N` when the column
  was not an entity field); sorting direction and indexes, numeric parameters and `sSearch_N` types are validated.
- Invalid client input is a `400 Bad Request` (it used to be a `500` `RuntimeException`).
- LIKE searches escape `%`, `_` and `\`; searching `0` per column now works; a global search with no searchable column
  no longer produces an empty `WHERE`.
- `max_page_length` option; the column index is preserved when a `mDataProp_N` is missing.
- Entity namespace aliases (`App:Customer`) are rejected with a clear exception on Doctrine ORM 3.

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