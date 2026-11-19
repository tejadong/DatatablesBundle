<?php

declare(strict_types=1);

namespace Tejadong\DatatablesBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tejadong_datatables');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->arrayNode('datatable')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('use_doctrine_paginator')
            ->defaultTrue()
            ->end()
            ->integerNode('max_page_length')
            ->info('Máximo de filas por página (0 = sin tope). Limita también la opción «todo» (iDisplayLength = -1).')
            ->min(0)
            ->defaultValue(0)
            ->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}