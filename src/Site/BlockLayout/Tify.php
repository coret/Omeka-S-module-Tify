<?php
namespace Tify\Site\BlockLayout;

use Laminas\Form\Element;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Site\BlockLayout\TemplateableBlockLayoutInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Site page block: a TIFY viewer for the attached items or media, or for any
 * IIIF manifest given by its URL.
 *
 * One attachment shows its own manifest (a media opens on its own page);
 * several become a IIIF collection, which requires the module IIIF Server.
 */
class Tify extends AbstractBlockLayout implements TemplateableBlockLayoutInterface
{
    const PARTIAL_NAME = 'common/block-layout/tify';

    public function getLabel()
    {
        return 'TIFY viewer'; // @translate
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData();

        $data['manifest_url'] = trim((string) ($data['manifest_url'] ?? ''));
        if ($data['manifest_url'] !== ''
            && (!filter_var($data['manifest_url'], FILTER_VALIDATE_URL)
                || !preg_match('~^https?://~i', $data['manifest_url']))
        ) {
            $errorStore->addError('o:block', 'The manifest URL of the TIFY block is not a valid http(s) URL.'); // @translate
        }

        $data['height'] = trim((string) ($data['height'] ?? ''));
        if ($data['height'] !== '' && !preg_match('/^\d+(\.\d+)?(px|em|rem|vh|vw|svh|dvh|%)?$/', $data['height'])) {
            $errorStore->addError('o:block', 'The height of the TIFY block must be a CSS length such as "640px" or "80vh".'); // @translate
        }

        $data['config'] = trim((string) ($data['config'] ?? ''));
        if ($data['config'] !== '') {
            json_decode($data['config']);
            if (json_last_error() !== JSON_ERROR_NONE || $data['config'][0] !== '{') {
                $errorStore->addError('o:block', 'The TIFY options of the block must be a JSON object.'); // @translate
            }
        }

        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $manifestUrl = new Element\Url('o:block[__blockIndex__][o:data][manifest_url]');
        $manifestUrl->setOptions([
            'label' => 'IIIF manifest URL', // @translate
            'info' => 'Shows this manifest or collection instead of the attachments below.', // @translate
        ]);
        $manifestUrl->setAttribute('placeholder', 'https://example.org/iiif/manifest.json');
        $manifestUrl->setValue($block ? $block->dataValue('manifest_url', '') : '');

        $height = new Element\Text('o:block[__blockIndex__][o:data][height]');
        $height->setOptions([
            'label' => 'Height', // @translate
            'info' => 'CSS height, for example "640px" or "80vh". Leave empty to use the site setting.', // @translate
        ]);
        $height->setValue($block ? $block->dataValue('height', '') : '');

        $config = new Element\Textarea('o:block[__blockIndex__][o:data][config]');
        $config->setOptions([
            'label' => 'Additional TIFY options (JSON)', // @translate
            'info' => 'Merged over the settings, for example {"pages": [3], "view": "info"}.', // @translate
        ]);
        $config->setAttribute('rows', 4);
        $config->setValue($block ? $block->dataValue('config', '') : '');

        return $view->blockAttachmentsForm($block)
            . '<a href="#" class="collapse" aria-label="collapse"><h4>' . $view->translate('Options') . '</h4></a>'
            . '<div class="collapsible">'
            . $view->formRow($manifestUrl)
            . $view->formRow($height)
            . $view->formRow($config)
            . '</div>';
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block, $templateViewScript = self::PARTIAL_NAME)
    {
        $resources = [];
        foreach ($block->attachments() as $attachment) {
            $item = $attachment->item();
            if ($item) {
                $resources[] = $attachment->media() ?: $item;
            }
        }

        $manifestUrl = trim((string) $block->dataValue('manifest_url', ''));
        if ($manifestUrl === '' && !$resources) {
            return '';
        }

        $options = [];
        if ($block->dataValue('height')) {
            $options['height'] = (string) $block->dataValue('height');
        }
        $config = json_decode((string) $block->dataValue('config', ''), true);
        if (is_array($config) && $config) {
            $options['tify'] = $config;
        }

        return $view->partial($templateViewScript, [
            'block' => $block,
            'resource' => $manifestUrl !== ''
                ? $manifestUrl
                : (count($resources) === 1 ? reset($resources) : $resources),
            'options' => $options,
        ]);
    }
}
