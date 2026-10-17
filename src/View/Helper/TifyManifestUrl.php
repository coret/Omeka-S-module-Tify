<?php
namespace Tify\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Finds the IIIF manifest URL of one resource or of a list of resources.
 *
 * In order of preference:
 *
 *  1. A URL in the "external manifest" property. The setting name is shared
 *     with the IIIF Server and Universal Viewer modules on purpose, so a site
 *     running several of them configures the property once.
 *  2. The manifest the IIIF Server module builds from the Omeka files, when
 *     that module is active. Several resources become a IIIF collection.
 *  3. Nothing: without either source there is no manifest to show.
 *
 * Media and digital objects (module DigitalObject) are shown through the
 * manifest of their item.
 */
class TifyManifestUrl extends AbstractHelper
{
    /**
     * @param AbstractResourceEntityRepresentation|AbstractResourceEntityRepresentation[] $resource
     */
    public function __invoke($resource): ?string
    {
        if (is_array($resource)) {
            return $this->forList($resource);
        }
        if (!$resource instanceof AbstractResourceEntityRepresentation) {
            return null;
        }

        $resource = $this->manifestOwner($resource);
        if (!$resource) {
            return null;
        }

        $external = $this->externalManifest($resource);
        if ($external) {
            return $external;
        }

        $plugins = $this->getView()->getHelperPluginManager();
        if (!$plugins->has('iiifUrl') || !$this->hasContent($resource)) {
            return null;
        }
        return $plugins->get('iiifUrl')->__invoke($resource) ?: null;
    }

    /**
     * Returns the resource whose manifest shows the given one: the resource
     * itself for items and item sets, the parent item for media and digital
     * objects, null for anything else.
     */
    public function manifestOwner(AbstractResourceEntityRepresentation $resource): ?AbstractResourceEntityRepresentation
    {
        $name = $resource->resourceName();
        if (in_array($name, ['items', 'item_sets'], true)) {
            return $resource;
        }
        if (in_array($name, ['media', 'digital_objects'], true) && method_exists($resource, 'item')) {
            return $resource->item() ?: null;
        }
        return null;
    }

    /**
     * Returns the first URL in the external manifest property, if any.
     */
    public function externalManifest(AbstractResourceEntityRepresentation $resource): ?string
    {
        $property = $this->getView()->setting('iiifserver_manifest_external_property');
        if (!$property) {
            return null;
        }

        foreach ($resource->value($property, ['all' => true]) as $value) {
            if ($value->type() === 'uri') {
                $url = (string) $value->uri();
            } elseif ($value->valueResource()) {
                // A linked resource is a cross-reference, not a manifest. Its
                // string value would be its site URL, so it is skipped.
                continue;
            } else {
                $url = trim((string) $value->value());
            }
            if (filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)
                && preg_match('~^https?://~i', $url)
            ) {
                return $url;
            }
        }
        return null;
    }

    /**
     * Several resources: the parent items are deduplicated. One remaining item
     * gets its own manifest, more become a collection built by IIIF Server.
     */
    protected function forList(array $resources): ?string
    {
        $owners = [];
        foreach ($resources as $resource) {
            if (!$resource instanceof AbstractResourceEntityRepresentation) {
                continue;
            }
            $owner = $this->manifestOwner($resource);
            if ($owner) {
                $owners[$owner->resourceName() . ':' . $owner->id()] = $owner;
            }
        }
        if (!$owners) {
            return null;
        }
        if (count($owners) === 1) {
            return $this->__invoke(reset($owners));
        }

        $plugins = $this->getView()->getHelperPluginManager();
        if (!$plugins->has('iiifUrl')) {
            return null;
        }
        return $plugins->get('iiifUrl')->__invoke(array_values($owners)) ?: null;
    }

    /**
     * IIIF Server cannot build a useful manifest for an item without files or
     * an item set without items, so those are not shown.
     */
    protected function hasContent(AbstractResourceEntityRepresentation $resource): bool
    {
        if ($resource->resourceName() === 'item_sets') {
            return (bool) $resource->itemCount();
        }
        if ($resource->media()) {
            return true;
        }
        // Digital objects are linked through property values rather than
        // attached as media.
        foreach ($resource->values() as $property) {
            foreach ($property['values'] as $value) {
                $linked = $value->valueResource();
                if ($linked && $linked->resourceName() === 'digital_objects') {
                    return true;
                }
            }
        }
        return false;
    }
}
