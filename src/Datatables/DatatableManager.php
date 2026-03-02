<?php

declare(strict_types=1);

namespace Tejadong\DatatablesBundle\Datatables;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class DatatableManager
{
    private ManagerRegistry $registry;
    private RequestStack $requestStack;
    private SerializerInterface $serializer;
    private bool $useDoctrinePaginator;

    public function __construct(
        ManagerRegistry $registry,
        RequestStack $requestStack,
        SerializerInterface $serializer,
        bool $useDoctrinePaginator = true
    ) {
        $this->registry = $registry;
        $this->requestStack = $requestStack;
        $this->serializer = $serializer;
        $this->useDoctrinePaginator = $useDoctrinePaginator;
    }

    /**
     * Given an entity class name or alias, convert it to the full class name
     */
    protected function getClassName(string $className): string
    {
        if (str_contains($className, ':')) {
            [$namespaceAlias, $simpleClassName] = explode(':', $className);

            $className = $this->registry
                    ->getManager()
                    ->getConfiguration()
                    ->getEntityNamespace($namespaceAlias)
                . '\\' . $simpleClassName;
        }

        return $className;
    }

    /**
     * Get a Datatable instance for the given entity
     */
    public function getDatatable(string $class): Datatable
    {
        $request = $this->requestStack
            ->getCurrentRequest()?->query->all() ?? [];

        $class = $this->getClassName($class);

        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        $metadata = $em->getClassMetadata($class);
        $repository = $em->getRepository($class);

        $datatable = new Datatable(
            $request,
            $repository,
            $metadata,
            $em,
            $this->serializer
        );

        return $datatable->useDoctrinePaginator($this->useDoctrinePaginator);
    }
}