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
use HTMLPurifier;
use HTMLPurifier_Config;

class Datatable
{
    public const JOIN_INNER = 'inner';
    public const JOIN_LEFT = 'left';

    public const RESULT_ARRAY = 'Array';
    public const RESULT_JSON = 'Json';
    public const RESULT_RESPONSE = 'Response';

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
    protected array $joinTypes = [];
    protected QueryBuilder $qb;

    protected int $offset = 0;
    protected int $amount = 10;
    protected string $echo = '';
    protected string $search = '';

    protected array $identifiers = [];
    protected string $rootEntityIdentifier;

    protected array $datatable = [];
    protected HTMLPurifier $htmlPurifier;

    protected string $defaultJoinType;
    protected string $defaultResultType = self::RESULT_RESPONSE;

    public function __construct(
        array $request,
        EntityRepository $repository,
        ClassMetadata $metadata,
        EntityManagerInterface $em,
        SerializerInterface $serializer
    ) {
        $this->request = $request;
        $this->repository = $repository;
        $this->metadata = $metadata;
        $this->em = $em;
        $this->serializer = $serializer;

        $this->tableName = $this->camelize(
            substr(strrchr($metadata->getName(), '\\'), 1)
        );

        $this->defaultJoinType = self::JOIN_LEFT;

        $this->qb = $em->createQueryBuilder();

        $this->echo = (string)($request['sEcho'] ?? '');
        $this->search = (string)($request['sSearch'] ?? '');
        $this->offset = (int)($request['iDisplayStart'] ?? 0);
        $this->amount = (int)($request['iDisplayLength'] ?? 10);

        $identifiers = $metadata->getIdentifierFieldNames();
        $this->rootEntityIdentifier = (string) array_shift($identifiers);

        $this->htmlPurifierInit();
        $this->setParameters();
    }

    private function camelize(string $string): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $string)));
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

    public function setParameters(): void
    {
        if (!isset($this->request['iColumns']) || !is_numeric($this->request['iColumns'])) {
            return;
        }

        $params = [];
        $associations = [];

        for ($i = 0; $i < (int) $this->request['iColumns']; $i++) {
            $columnKey = 'mDataProp_' . $i;

            if (!isset($this->request[$columnKey])) {
                continue;
            }

            $fields = explode('.', $this->request[$columnKey]);

            $params[] = $this->request[$columnKey];
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

        while ($field = array_shift($fields)) {
            $columnName .= empty($columnName) ? $field : ".$field";
            $entityName = lcfirst($this->camelize($field));

            if (!$metadata->hasAssociation($entityName)) {
                throw new \RuntimeException(
                    "Relación '$entityName' no encontrada ($mdataName)"
                );
            }

            $joinOn = "$joinName.$entityName";

            if ($metadata->isCollectionValuedAssociation($entityName)) {
                $association['containsCollections'] = true;
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

            if (!isset($this->assignedJoins[$joinName])) {
                $this->assignedJoins[$joinName] = [
                    'joinOn' => $joinOn,
                    'mdataColumn' => $columnName
                ];

                $this->identifiers[$joinName] = $metadata->getIdentifierFieldNames();
            }
        }

        if (!$metadata->hasField(lcfirst($lastField))) {
            throw new \RuntimeException(
                "Propiedad '$lastField' en la relación '$entityName' no encontrada ($mdataName)"
            );
        }

        $association['entityName'] = $entityName;
        $association['fieldName'] = $lastField;
        $association['joinName'] = $joinName;
        $association['fullName'] = $this->getFullName($association);
    }

    protected function setSingleFieldColumnInfo(array &$association, string $fieldName): void
    {
        $fieldName = $this->camelize($fieldName);

        if (!$this->metadata->hasField(lcfirst($fieldName))) {
            $association['fieldName'] = $fieldName;
            $association['entityName'] = null;
            $association['fullName'] = lcfirst($fieldName);
        } else {
            $association['fieldName'] = $fieldName;
            $association['entityName'] = $this->tableName;
            $association['fullName'] = $this->tableName . '.' . lcfirst($fieldName);
        }
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

        for ($i = 0; $i < (int) $this->request['iSortingCols']; $i++) {
            $columnIndex = (int) $this->request['iSortCol_' . $i];

            if (($this->request['bSortable_' . $columnIndex] ?? '') === "true") {
                $qb->addOrderBy(
                    $this->associations[$columnIndex]['fullName'],
                    $this->request['sSortDir_' . $i]
                );
            }
        }
    }

    public function setWhere(QueryBuilder $qb): void
    {
        if ($this->search !== '') {
            $orExpr = $qb->expr()->orX();

            foreach ($this->parameters as $i => $parameter) {
                if (($this->request['bSearchable_' . $i] ?? '') !== "true") {
                    continue;
                }

                $qbParam = "sSearch_global_{$i}";
                $fieldName = $this->associations[$i]['fullName'];

                $orExpr->add(
                    $qb->expr()->like($fieldName, ":$qbParam")
                );

                $qb->setParameter($qbParam, '%' . $this->search . '%');
            }

            $qb->andWhere($orExpr);
        }

        $andExpr = $qb->expr()->andX();

        foreach ($this->parameters as $i => $parameter) {
            if (
                ($this->request['bSearchable_' . $i] ?? '') === "true" &&
                isset($this->request['sSearch_' . $i]) &&
                $this->request['sSearch_' . $i] !== ''
            ) {
                $qbParam = "sSearch_single_{$i}";
                $fieldName = $this->associations[$i]['fullName'];

                $andExpr->add(
                    $qb->expr()->like($fieldName, ":$qbParam")
                );

                $qb->setParameter(
                    $qbParam,
                    '%' . $this->request['sSearch_' . $i] . '%'
                );
            }
        }

        if ($andExpr->count() > 0) {
            $qb->andWhere($andExpr);
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

        foreach (array_keys($this->assignedJoins) as $joinName) {
            $columns[$joinName] = [];
        }

        foreach ($this->associations as $column) {
            $parts = explode('.', $column['fullName']);

            if (count($parts) > 1) {
                $columns[$parts[0]][] = $parts[1];
            }
        }

        foreach ($this->identifiers as $joinName => $identifiers) {
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

        $qb->select(implode(',', $partials))
            ->from($this->metadata->getName(), $this->tableName);
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