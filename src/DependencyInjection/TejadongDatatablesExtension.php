<?php

declare(strict_types=1);

namespace Tejadong\DatatablesBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class TejadongDatatablesExtension extends Extension
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

        // 🔥 CARGAR services.yaml
        $loader = new YamlFileLoader(
                $container,
                new FileLocator(__DIR__.'/../../config')
        );

        $loader->load('services.yaml');
    }
}