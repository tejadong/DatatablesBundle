<?php

declare(strict_types=1);

namespace Tejadong\DatatablesBundle\Datatables;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use HTMLPurifier;
use HTMLPurifier_Config;

class Datatable
{
    public const JOIN_INNER = 'inner';
    public const JOIN_LEFT = 'left';

    public const RESULT_ARRAY = 'Array';
    public const RESULT_JSON = 'Json';
    public const RESULT_RESPONSE = 'Response';

    /** Máximo de columnas (`iColumns`) que se procesan por petición. */
    private const MAX_COLUMNS = 100;

    /** Longitud máxima del nombre de una columna (`mDataProp_N`). */
    private const MAX_COLUMN_LENGTH = 100;

    /**
     * Forma admitida para el nombre de una columna: identificadores separados por puntos para las relaciones
     * (`codigoPostal.localidad.nombre`). Los nombres de columna llegan del cliente y se usan para construir el DQL,
     * así que cualquier otro carácter (espacios, paréntesis, comillas...) se rechaza.
     */
    private const COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const INTEGER_PATTERN = '/^-?\d{1,12}$/';

    protected array $callbacks = [
        'WhereBuilder' => [],
    ];

    protected bool $useDoctrinePaginator = true;
    protected bool $hideFilteredCount = true;
    protected bool $useDtRowId = false;
    protected bool $useDtRowClass = true;

    protected ?string $dtRowClass = null;

    protected SerializerInterface $serializer;
    protected ClassMetadata $metadata;
    protected EntityRepository $repository;
    protected EntityManagerInterface $em;

    protected string $tableName;
    protected array $request;
    protected array $parameters = [];
    protected array $associations = [];
    protected array $assignedJoins = [];
    // Joins que forman parte de una ruta con colección (desde la propia colección): sus identificadores no se
    // seleccionan ni se agrupan, para que la consulta devuelva una fila por entidad raíz
    protected array $collectionJoins = [];
    protected array $joinTypes = [];
    protected QueryBuilder $qb;

    protected int $offset = 0;
    protected int $amount = 10;
    protected string $echo = '';
    protected string $search = '';

    /** Tope de filas por página (0 = sin tope). `iDisplayLength = -1` («todo») también queda limitado por él. */
    protected int $maxPageLength = 0;

    protected array $identifiers = [];
    protected string $rootEntityIdentifier;

    protected array $datatable = [];
    protected HTMLPurifier $htmlPurifier;

    protected string $defaultJoinType;
    protected string $defaultResultType = self::RESULT_RESPONSE;

    /**
     * @throws BadRequestHttpException si la petición lleva parámetros que no tienen la forma que genera DataTables
     */
    public function __construct(
        array $request,
        EntityRepository $repository,
        ClassMetadata $metadata,
        EntityManagerInterface $em,
        SerializerInterface $serializer,
        int $maxPageLength = 0
    ) {
        $this->request = $request;
        $this->repository = $repository;
        $this->metadata = $metadata;
        $this->em = $em;
        $this->serializer = $serializer;
        $this->maxPageLength = max(0, $maxPageLength);

        $this->tableName = $this->camelize(
            substr(strrchr($metadata->getName(), '\\'), 1)
        );

        $this->defaultJoinType = self::JOIN_LEFT;

        $this->qb = $em->createQueryBuilder();

        $search = $request['sSearch'] ?? '';
        if (!is_string($search)) {
            throw new BadRequestHttpException("Parámetro 'sSearch' no válido.");
        }

        $this->echo = (string) $this->intParam('sEcho', 0);
        $this->search = $search;
        $this->offset = max(0, $this->intParam('iDisplayStart', 0));
        $this->amount = $this->normalizeLength($this->intParam('iDisplayLength', 10));

        $identifiers = $metadata->getIdentifierFieldNames();
        $this->rootEntityIdentifier = (string) array_shift($identifiers);

        $this->htmlPurifierInit();
        $this->setParameters();
    }

    private function camelize(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
    }

    /**
     * Lee un parámetro entero de la petición. Vacío o ausente devuelve el valor por defecto.
     *
     * @throws BadRequestHttpException si no es un entero
     */
    private function intParam(string $key, int $default): int
    {
        $value = $this->request[$key] ?? '';

        if ($value === '' || $value === null) {
            return $default;
        }

        if (!is_string($value) || preg_match(self::INTEGER_PATTERN, $value) !== 1) {
            throw new BadRequestHttpException("Parámetro '$key' no válido.");
        }

        return (int) $value;
    }

    /** Normaliza `iDisplayLength`: -1 es «todo»; 0 o negativos distintos de -1 usan el valor por defecto. */
    private function normalizeLength(int $length): int
    {
        if ($length === -1) {
            return $this->maxPageLength > 0 ? $this->maxPageLength : -1;
        }

        if ($length < 1) {
            $length = 10;
        }

        return $this->maxPageLength > 0 ? min($length, $this->maxPageLength) : $length;
    }

    /** Escapa `\`, `%` y `_` para que el texto buscado se compare literalmente en un LIKE. */
    private function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function useDtRowId(bool $useDtRowId): self
    {
        $this->useDtRowId = $useDtRowId;
        return $this;
    }

    public function useDtRowClass(bool $useDtRowClass): self
    {
        $this->useDtRowClass = $useDtRowClass;
        return $this;
    }

    public function setDtRowClass(?string $dtRowClass): self
    {
        $this->dtRowClass = $dtRowClass;
        return $this;
    }

    public function useDoctrinePaginator(bool $useDoctrinePaginator): self
    {
        $this->useDoctrinePaginator = $useDoctrinePaginator;
        return $this;
    }

    /**
     * @throws BadRequestHttpException si el número de columnas o el nombre de alguna no son válidos
     */
    public function setParameters(): void
    {
        if (!isset($this->request['iColumns'])) {
            return;
        }

        $columnCount = $this->intParam('iColumns', 0);
        if ($columnCount < 0 || $columnCount > self::MAX_COLUMNS) {
            throw new BadRequestHttpException("Parámetro 'iColumns' no válido.");
        }

        $params = [];
        $associations = [];

        for ($i = 0; $i < $columnCount; $i++) {
            $columnKey = 'mDataProp_' . $i;

            if (!isset($this->request[$columnKey])) {
                continue;
            }

            $column = $this->request[$columnKey];

            if (
                !is_string($column)
                || strlen($column) > self::MAX_COLUMN_LENGTH
                || preg_match(self::COLUMN_PATTERN, $column) !== 1
            ) {
                throw new BadRequestHttpException("Nombre de la columna $i no válido.");
            }

            $fields = explode('.', $column);

            // Se conserva el índice de la columna: el orden y la búsqueda se refieren a él (`iSortCol_N`, `bSearchable_N`).
            $params[$i] = $column;
            $associations[$i] = ['containsCollections' => false];

            if (count($fields) > 1) {
                $this->setRelatedEntityColumnInfo($associations[$i], $fields);
            } else {
                $this->setSingleFieldColumnInfo($associations[$i], $fields[0]);
            }
        }

        $this->parameters = $params;
        $this->associations = $associations;
    }

    protected function setRelatedEntityColumnInfo(array &$association, array $fields): void
    {
        $mdataName = implode('.', $fields);
        $lastField = $this->camelize(array_pop($fields));
        $joinName = $this->tableName;
        $entityName = '';
        $columnName = '';

        $metadata = $this->metadata;
        $crossesCollection = false;

        foreach ($fields as $field) {
            $columnName .= $columnName === '' ? $field : ".$field";
            $entityName = lcfirst($this->camelize($field));

            if (!$metadata->hasAssociation($entityName)) {
                throw new BadRequestHttpException(
                    "Relación '$entityName' no encontrada ($mdataName)"
                );
            }

            $joinOn = "$joinName.$entityName";

            if ($metadata->isCollectionValuedAssociation($entityName)) {
                $association['containsCollections'] = true;
                $crossesCollection = true;
            }

            $metadata = $this->em->getClassMetadata(
                $metadata->getAssociationTargetClass($entityName)
            );

            $joinName .= '_' . $this->getJoinName(
                    $metadata,
                    $this->camelize(substr(strrchr($metadata->getName(), '\\'), 1)),
                    $entityName
                );

            $joinName .= '_' . $entityName;

            if ($crossesCollection) {
                $this->collectionJoins[$joinName] = true;
            }

            if (!isset($this->assignedJoins[$joinName])) {
                $this->assignedJoins[$joinName] = [
                    'joinOn' => $joinOn,
                    'mdataColumn' => $columnName
                ];

                $this->identifiers[$joinName] = $metadata->getIdentifierFieldNames();
            }
        }

        if (!$metadata->hasField(lcfirst($lastField))) {
            throw new BadRequestHttpException(
                "Propiedad '$lastField' en la relación '$entityName' no encontrada ($mdataName)"
            );
        }

        $association['entityName'] = $entityName;
        $association['fieldName'] = $lastField;
        $association['joinName'] = $joinName;
        $association['fullName'] = $this->getFullName($association);

        if (!empty($association['containsCollections'])) {
            $association['mdataName'] = $mdataName;
            $association['aggregateAlias'] = str_replace('.', '_', $mdataName) . '_agg';
        }
    }

    protected function setSingleFieldColumnInfo(array &$association, string $fieldName): void
    {
        $camelized = $this->camelize($fieldName);
        $property = lcfirst($camelized);

        if ($this->metadata->hasField($property)) {
            $association['fieldName'] = $camelized;
            $association['entityName'] = $this->tableName;
            $association['fullName'] = $this->tableName . '.' . $property;

            return;
        }

        // Columna sin campo en la entidad (acciones, imágenes, totales calculados...): se muestra, pero nunca se usa para
        // ordenar, buscar ni seleccionar. Antes el nombre que enviaba el cliente se pegaba en el DQL.
        $association['fieldName'] = $camelized;
        $association['entityName'] = null;
        $association['fullName'] = null;
        $association['virtual'] = true;
    }

    /** Las columnas virtuales (sin campo de entidad) no intervienen en la consulta. */
    protected function isVirtual(array $column): bool
    {
        return !empty($column['virtual']);
    }

    protected function getJoinName(
        ClassMetadata $metadata,
        string $tableName,
        string $entityName
    ): string {
        if ($metadata->getName() === $this->metadata->getName()) {
            return $entityName;
        }

        return $tableName;
    }

    protected function getFullName(array $associationInfo): string
    {
        return $associationInfo['joinName'] . '.' . lcfirst($associationInfo['fieldName']);
    }

    public function setDefaultJoinType(string $joinType): self
    {
        $constant = 'self::JOIN_' . strtoupper($joinType);

        if (defined($constant)) {
            $this->defaultJoinType = constant($constant);
        }

        return $this;
    }

    public function setJoinType(string $column, string $joinType): self
    {
        $constant = 'self::JOIN_' . strtoupper($joinType);

        if (defined($constant)) {
            $this->joinTypes[$column] = constant($constant);
        }

        return $this;
    }

    public function hideFilteredCount(bool $hideFilteredCount): self
    {
        $this->hideFilteredCount = $hideFilteredCount;
        return $this;
    }

    public function setLimit(QueryBuilder $qb): void
    {
        if ($this->amount !== -1) {
            $qb->setFirstResult($this->offset)
                ->setMaxResults($this->amount);
        }
    }

    public function setOrderBy(QueryBuilder $qb): void
    {
        if (!isset($this->request['iSortCol_0'])) {
            return;
        }

        $sortingColumns = min($this->intParam('iSortingCols', 0), self::MAX_COLUMNS);

        for ($i = 0; $i < $sortingColumns; $i++) {
            $columnIndex = $this->intParam('iSortCol_' . $i, -1);

            if (!isset($this->associations[$columnIndex])) {
                continue;
            }

            if (($this->request['bSortable_' . $columnIndex] ?? '') !== "true") {
                continue;
            }

            $column = $this->associations[$columnIndex];

            if ($this->isVirtual($column)) {
                continue;
            }

            $orderField = !empty($column['containsCollections'])
                ? $column['aggregateAlias']
                : $column['fullName'];

            // Solo `asc` o `desc`: la dirección se concatena en el DQL.
            $direction = strtolower((string) ($this->request['sSortDir_' . $i] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $qb->addOrderBy($orderField, $direction);
        }
    }

    public function setWhere(QueryBuilder $qb): void
    {
        $hasCollectionColumn = $this->doesQueryContainCollections();

        if ($this->search !== '') {
            $orExpr = $qb->expr()->orX();

            foreach ($this->parameters as $i => $parameter) {
                if (($this->request['bSearchable_' . $i] ?? '') !== "true") {
                    continue;
                }

                $column = $this->associations[$i];

                if ($this->isVirtual($column)) {
                    continue;
                }

                $qbParam = "sSearch_global_{$i}";
                $fieldName = !empty($column['containsCollections'])
                    ? $column['aggregateAlias']
                    : $column['fullName'];

                $orExpr->add(
                    $qb->expr()->like($fieldName, ":$qbParam")
                );

                $qb->setParameter($qbParam, '%' . $this->escapeLike($this->search) . '%');
            }

            // Sin columnas buscables (todas virtuales o no marcadas) la búsqueda global no filtra: un OR vacío daría un
            // WHERE vacío y Doctrine lo rechazaría.
            if ($orExpr->count() > 0) {
                if ($hasCollectionColumn) {
                    $qb->andHaving($orExpr);
                } else {
                    $qb->andWhere($orExpr);
                }
            }
        }

        $andExpr = $qb->expr()->andX();
        $havingExpr = $qb->expr()->andX();

        foreach ($this->parameters as $i => $parameter) {
            if (($this->request['bSearchable_' . $i] ?? '') !== "true") {
                continue;
            }

            $columnSearch = $this->request['sSearch_' . $i] ?? '';

            if (!is_string($columnSearch)) {
                throw new BadRequestHttpException("Parámetro 'sSearch_$i' no válido.");
            }

            // `"0"` es una búsqueda válida (antes `!empty()` la ignoraba).
            if ($columnSearch === '') {
                continue;
            }

            $column = $this->associations[$i];

            if ($this->isVirtual($column)) {
                continue;
            }

            $qbParam = "sSearch_single_{$i}";

            if (!empty($column['containsCollections'])) {
                $havingExpr->add(
                    $qb->expr()->like($column['aggregateAlias'], ":$qbParam")
                );
            } else {
                $andExpr->add(
                    $qb->expr()->like($column['fullName'], ":$qbParam")
                );
            }

            $qb->setParameter(
                $qbParam,
                '%' . $this->escapeLike($columnSearch) . '%'
            );
        }

        if ($andExpr->count() > 0) {
            $qb->andWhere($andExpr);
        }

        if ($havingExpr->count() > 0) {
            $qb->andHaving($havingExpr);
        }

        foreach ($this->callbacks['WhereBuilder'] as $callback) {
            $callback($qb);
        }
    }

    public function setAssociations(QueryBuilder $qb): void
    {
        foreach ($this->assignedJoins as $joinName => $joinInfo) {
            $joinType = $this->joinTypes[$joinInfo['mdataColumn']] ?? $this->defaultJoinType;

            $method = $joinType . 'Join';

            $qb->$method(
                $joinInfo['joinOn'],
                $joinName
            );
        }
    }

    public function setSelect(QueryBuilder $qb): void
    {
        $columns = [];
        $partials = [];
        $aggregates = [];
        $collectionJoinNames = [];

        foreach (array_keys($this->assignedJoins) as $joinName) {
            $columns[$joinName] = [];
        }

        foreach ($this->associations as $column) {
            if ($this->isVirtual($column) || empty($column['fullName'])) {
                continue;
            }

            if (!empty($column['containsCollections'])) {
                $aggregates[] = sprintf(
                    "GROUP_CONCAT(DISTINCT %s SEPARATOR ', ') AS %s",
                    $column['fullName'],
                    $column['aggregateAlias']
                );
                $collectionJoinNames[$column['joinName']] = true;
                continue;
            }

            $parts = explode('.', $column['fullName']);

            if (count($parts) > 1) {
                $columns[$parts[0]][] = $parts[1];
            }
        }

        foreach ($this->identifiers as $joinName => $identifiers) {
            // Los joins de una ruta con colección (no solo el último) se agregan con GROUP_CONCAT: seleccionar su
            // identificador partiría el resultado en una fila por elemento de la colección
            if (isset($collectionJoinNames[$joinName]) || isset($this->collectionJoins[$joinName])) {
                continue;
            }

            if (!in_array($identifiers[0], $columns[$joinName] ?? [])) {
                array_unshift($columns[$joinName], $identifiers[0]);
            }
        }

        if (!isset($columns[$this->tableName])) {
            $columns[$this->tableName] = [];
        }

        if (!in_array($this->rootEntityIdentifier, $columns[$this->tableName])) {
            array_unshift($columns[$this->tableName], $this->rootEntityIdentifier);
        }

        foreach ($columns as $alias => $fields) {
            if (!empty($fields)) {
                $partials[] = "partial $alias.{" . implode(',', $fields) . "}";
            }
        }

        $qb->select(implode(',', array_merge($partials, $aggregates)))
            ->from($this->metadata->getName(), $this->tableName);

        if (!empty($aggregates)) {
            $groupBy = [];
            foreach ($columns as $alias => $fields) {
                foreach ($fields as $field) {
                    $groupBy[] = "$alias.$field";
                }
            }
            $qb->groupBy(implode(',', $groupBy));
        }
    }

    public function makeSearch(): self
    {
        $this->setSelect($this->qb);
        $this->setAssociations($this->qb);
        $this->setWhere($this->qb);
        $this->setOrderBy($this->qb);
        $this->setLimit($this->qb);

        return $this;
    }

    protected function isAssocArray(array $array): bool
    {
        return (bool) count(array_filter(array_keys($array), 'is_string'));
    }

    public function executeSearch(): self
    {
        $output = ["aaData" => []];

        $query = $this->qb->getQuery();
        $query->setHydrationMode(Query::HYDRATE_ARRAY);

        $items = $this->useDoctrinePaginator
            ? new Paginator($query, $this->doesQueryContainCollections())
            : $query->getResult(Query::HYDRATE_ARRAY);

        foreach ($items as $item) {
            if (isset($item[0]) && is_array($item[0])) {
                $entity = $item[0];
                unset($item[0]);
                $item = $entity + $item;
            }

            foreach ($this->associations as $column) {
                if (!empty($column['containsCollections'])) {
                    $value = $item[$column['aggregateAlias']] ?? null;
                    unset($item[$column['aggregateAlias']]);
                    $this->injectNestedValue($item, $column['mdataName'], $value);
                }
            }

            if ($this->useDtRowClass && $this->dtRowClass !== null) {
                $item['DT_RowClass'] = $this->dtRowClass;
            }

            if ($this->useDtRowId) {
                $item['DT_RowId'] = $item[$this->rootEntityIdentifier];
            }

            $item = $this->purifyRecursive($item);

            $output['aaData'][] = $item;
        }

        $this->datatable = [
                "sEcho" => (int) $this->echo,
                "iTotalRecords" => $this->getCountAllResults(),
                "iTotalDisplayRecords" => $this->getCountFilteredResults(),
            ] + $output;

        return $this;
    }

    protected function doesQueryContainCollections(): bool
    {
        foreach ($this->associations as $column) {
            if (!empty($column['containsCollections'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Escribe $value en $item siguiendo una ruta en notación de puntos,
     * creando los arrays intermedios que hagan falta.
     * injectNestedValue($item, 'deportes.nombre', 'a, b') => $item['deportes']['nombre'] = 'a, b'
     */
    protected function injectNestedValue(array &$item, string $path, $value): void
    {
        $keys = explode('.', $path);
        $target = &$item;
        $lastIndex = count($keys) - 1;

        foreach ($keys as $index => $key) {
            if ($index === $lastIndex) {
                $target[$key] = $value;
                break;
            }

            if (!isset($target[$key]) || !is_array($target[$key])) {
                $target[$key] = [];
            }

            $target = &$target[$key];
        }
    }

    public function getSearchResults(string $resultType = ''): mixed
    {
        if ($resultType === '' || !defined('self::RESULT_' . strtoupper($resultType))) {
            $resultType = $this->defaultResultType;
        } else {
            $resultType = constant('self::RESULT_' . strtoupper($resultType));
        }

        $this->makeSearch();
        $this->executeSearch();

        return $this->{'getSearchResults' . $resultType}();
    }

    public function getSearchResultsJson(): string
    {
        return $this->serializer->serialize($this->datatable, 'json');
    }

    public function getSearchResultsArray(): array
    {
        return $this->datatable;
    }

    public function getSearchResultsResponse(): Response
    {
        return new Response(
            $this->serializer->serialize($this->datatable, 'json'),
            200,
            ['Content-Type' => 'application/json']
        );
    }

    public function getCountAllResults(): int
    {
        $qb = $this->repository->createQueryBuilder($this->tableName)
            ->select('count(' . $this->tableName . '.' . $this->rootEntityIdentifier . ')');

        foreach ($this->callbacks['WhereBuilder'] as $callback) {
            $callback($qb);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getCountFilteredResults(): int
    {
        if ($this->doesQueryContainCollections()) {
            $qb = $this->em->createQueryBuilder();

            $this->setSelect($qb);
            $this->setAssociations($qb);
            $this->setWhere($qb);

            return (new Paginator($qb->getQuery(), true))->count();
        }

        $qb = $this->repository->createQueryBuilder($this->tableName)
            ->select('count(distinct ' . $this->tableName . '.' . $this->rootEntityIdentifier . ')');

        $this->setAssociations($qb);
        $this->setWhere($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function addWhereBuilderCallback(callable $callback): self
    {
        $this->callbacks['WhereBuilder'][] = $callback;
        return $this;
    }

    public function setCustom($response, $nombre, $clase, $funcion, $parameters = []){

        $data = json_decode($response->getContent(), true);

        foreach($data['aaData'] as $key => $registro){
            if(count($parameters) == 0)
                $parametros = array($registro['id']);
            else{
                $parametros = array_merge(array($registro['id']), $parameters);
            }

            $data['aaData'][$key][$nombre] = call_user_func_array( array( $clase, $funcion), $parametros );
        }

        return $response->setContent(json_encode($data));

    }

    private function htmlPurifierInit(): void
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', null);
        $config->set('CSS.AllowedProperties', null);

        // Por defecto HTMLPurifier guarda su caché dentro de `vendor/`, que suele no ser escribible en producción.
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tejadong-htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        $this->htmlPurifier = new HTMLPurifier($config);
    }

    protected function purifyRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->htmlPurifier->purify($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->purifyRecursive($value);
            }
        }
        return $data;
    }

}
