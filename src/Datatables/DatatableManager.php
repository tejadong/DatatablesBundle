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
    private int $maxPageLength;

    public function __construct(
        ManagerRegistry $registry,
        RequestStack $requestStack,
        SerializerInterface $serializer,
        bool $useDoctrinePaginator = true,
        int $maxPageLength = 0
    ) {
        $this->registry = $registry;
        $this->requestStack = $requestStack;
        $this->serializer = $serializer;
        $this->useDoctrinePaginator = $useDoctrinePaginator;
        $this->maxPageLength = $maxPageLength;
    }

    /**
     * Given an entity class name or alias, convert it to the full class name.
     *
     * Los alias de namespace (`App:Entity`) se eliminaron en Doctrine ORM 3: con ORM 2 se resuelven y con ORM 3 se
     * lanza una excepción clara en lugar de un error fatal.
     */
    protected function getClassName(string $className): string
    {
        if (str_contains($className, ':')) {
            [$namespaceAlias, $simpleClassName] = explode(':', $className, 2);

            $configuration = $this->registry->getManager()->getConfiguration();

            if (!method_exists($configuration, 'getEntityNamespace')) {
                throw new \InvalidArgumentException(
                    "Los alias de entidad ('$className') no se admiten con Doctrine ORM 3: usa el nombre completo de la clase."
                );
            }

            $className = $configuration->getEntityNamespace($namespaceAlias) . '\\' . $simpleClassName;
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
            $this->serializer,
            $this->maxPageLength
        );

        return $datatable->useDoctrinePaginator($this->useDoctrinePaginator);
    }
}