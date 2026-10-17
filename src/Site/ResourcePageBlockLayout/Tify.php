<?php
namespace Tify\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

/**
 * Resource page block "TIFY viewer", for themes with configurable resource
 * pages. On a media page the viewer opens on that media.
 */
class Tify implements ResourcePageBlockLayoutInterface
{
    public function getLabel(): string
    {
        return 'TIFY viewer'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return [
            'items',
            'media',
            'item_sets',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        return $view->partial('common/resource-page-block-layout/tify', [
            'resource' => $resource,
        ]);
    }
}
