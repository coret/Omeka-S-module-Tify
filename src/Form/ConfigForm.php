<?php
namespace Tify\Form;

use Laminas\Form\Form;
use Omeka\Form\Element\PropertySelect;

/**
 * Module configuration: where external manifests are found.
 *
 * The setting name is the one of the IIIF Server and Universal Viewer modules,
 * so the three share it rather than each keeping its own copy.
 */
class ConfigForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name' => 'iiifserver_manifest_external_property',
            'type' => PropertySelect::class,
            'options' => [
                'label' => 'Property with the URL of an external IIIF manifest', // @translate
                'info' => 'When a resource has a URL in this property, the viewer shows that manifest. Otherwise it shows the manifest built by the module IIIF Server, when installed. Usually "dcterms:hasFormat" or "dcterms:isFormatOf". This setting is shared with the modules IIIF Server and Universal Viewer.', // @translate
                'empty_option' => '',
                'term_as_value' => true,
            ],
            'attributes' => [
                'id' => 'iiifserver_manifest_external_property',
                'class' => 'chosen-select',
                'data-placeholder' => 'Select a property…', // @translate
            ],
        ]);

        $this->getInputFilter()->add([
            'name' => 'iiifserver_manifest_external_property',
            'required' => false,
        ]);
    }
}
