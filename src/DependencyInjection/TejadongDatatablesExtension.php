<?php

declare(strict_types=1);

namespace Tejadong\DatatablesBundle\DependencyInjection;

use DoctrineExtensions\Query\Mysql\GroupConcat;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class TejadongDatatablesExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Set parameter
        $container->setParameter(
            'tejadong_datatables.use_doctrine_paginator',
            $config['datatable']['use_doctrine_paginator']
        );

        // CARGAR services.yaml
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../../config')
        );

        $loader->load('services.yaml');
    }

    /**
     * Registra automáticamente la función DQL GROUP_CONCAT que necesita
     * Datatable::setSelect() para agregar columnas de colección (relaciones
     * to-many). Sin esto, cualquier proyecto que instale el bundle y tenga
     * una columna de colección se encontraría con:
     * "QueryException: [Semantical Error] ... Unknown function 'GROUP_CONCAT'"
     * sin ninguna pista de por qué, a menos que lea el README.
     *
     * Al hacerlo aquí (prepend), el usuario del bundle no tiene que tocar
     * su doctrine.yaml para que esto funcione.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!class_exists(GroupConcat::class)) {
            // beberlei/doctrineextensions no instalado: no forzamos el prepend
            // para no romper el arranque de la app si por lo que sea falta.
            return;
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'dql' => [
                    'string_functions' => [
                        'group_concat' => GroupConcat::class,
                    ],
                ],
            ],
        ]);
    }
}