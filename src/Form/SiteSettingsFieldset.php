<?php
namespace Tify\Form;

use Laminas\Form\Element;

/**
 * The viewer settings of the site settings form. Every field left empty uses
 * the main setting.
 */
class SiteSettingsFieldset extends SettingsFieldset
{
    public function init(): void
    {
        $this->inheritLabel = 'Use the main setting'; // @translate
        parent::init();

        // Full sentences rather than appended fragments, so they translate.
        $infos = [
            'tify_height' => 'CSS height of the viewer, for example "640px" or "80vh". Leave empty to use the main setting.', // @translate
            'tify_views' => 'The panels next to the image, in this order, separated by commas: text, search, thumbnails, toc, info, export, collection. Leave empty to use the main setting.', // @translate
            'tify_url_query_key' => 'Keeps the state of the viewer in the URL of the page under this key. Leave empty to use the main setting.', // @translate
            'tify_config' => 'A JSON object with any TIFY option, merged over the main JSON options. Use {"urlQueryKey": null} to switch off a URL query key set in the main settings.', // @translate
        ];
        foreach ($infos as $name => $info) {
            $this->get($name)->setOption('info', $info);
        }
    }

    protected function addSpecificElements(): void
    {
        $this->add([
            'name' => 'tify_placement',
            'type' => Element\MultiCheckbox::class,
            'options' => [
                'element_group' => self::ELEMENT_GROUP,
                'label' => 'Show the viewer automatically (themes without resource page blocks)', // @translate
                'info' => 'Only for older themes. With a theme that has configurable resource page blocks, add the "TIFY viewer" block in the theme settings instead. The browse pages require the module IIIF Server.', // @translate
                'value_options' => [
                    'after/items' => 'Item page', // @translate
                    'browse/items' => 'Item browse page', // @translate
                    'browse/item_sets' => 'Item set browse page', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'tify_placement',
            ],
        ]);
    }
}
