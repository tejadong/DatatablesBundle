<?php

namespace Tejadong\DatatablesBundle;

use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Tejadong\DatatablesBundle\DependencyInjection\TejadongDatatablesExtension;

class TejadongDatatablesBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new TejadongDatatablesExtension();
    }
}
