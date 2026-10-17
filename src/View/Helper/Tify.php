<?php
namespace Tify\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Renders a TIFY viewer.
 *
 *   echo $this->tify($item);
 *   echo $this->tify($media);                       // opens on that media's page
 *   echo $this->tify([$item1, $item2]);             // a IIIF collection (IIIF Server)
 *   echo $this->tify('https://example.org/manifest.json');
 *   echo $this->tify($item, ['height' => '80vh', 'tify' => ['view' => 'info']]);
 *
 * Options:
 *   - height: CSS height of the viewer (px, em, rem, vh, vw or %)
 *   - tify:   TIFY options, merged over everything else
 *   - class:  extra CSS classes on the container
 *
 * The TIFY options are built in this order, later ones winning, shallowly like
 * TIFY itself merges them into its defaults:
 *
 *   module defaults < main settings < site settings
 *     < main JSON config < site JSON config < the "tify" option of the call
 *
 * Returns an empty string when there is no manifest to show.
 */
class Tify extends AbstractHelper
{
    /**
     * The views (panels) TIFY knows, in its default order. The media view is
     * always present and cannot be configured.
     */
    const VIEWS = ['text', 'thumbnails', 'toc', 'info', 'export', 'collection'];

    const COLOR_MODES = ['auto', 'light', 'dark', 'system'];

    const DEFAULT_HEIGHT = '640px';

    /**
     * URL query keys already handed out on this page. TIFY requires a unique
     * key per instance.
     *
     * @var string[]
     */
    protected $queryKeys = [];

    /**
     * @var int
     */
    protected $count = 0;

    /**
     * @param AbstractResourceEntityRepresentation|AbstractResourceEntityRepresentation[]|string|null $resource
     */
    public function __invoke($resource, array $options = []): string
    {
        if (empty($resource)) {
            return '';
        }

        $view = $this->getView();
        $pages = null;

        if (is_string($resource)) {
            $manifestUrl = $this->validUrl($resource);
        } else {
            $manifestUrl = $view->tifyManifestUrl($resource);
            if ($resource instanceof AbstractResourceEntityRepresentation
                && $resource->resourceName() === 'media'
            ) {
                $pages = $this->pageOfMedia($resource);
            }
        }
        if (!$manifestUrl) {
            return '';
        }

        $settings = $this->settings();

        $tifyOptions = $this->tifyOptions($settings);
        $tifyOptions['manifestUrl'] = $manifestUrl;
        if ($pages) {
            $tifyOptions['pages'] = $pages;
        }
        $tifyOptions = array_merge(
            $tifyOptions,
            $this->decodeJson($settings['main_config']),
            $this->decodeJson($settings['site_config']),
            isset($options['tify']) && is_array($options['tify']) ? $options['tify'] : []
        );
        $tifyOptions = $this->uniqueQueryKey($tifyOptions);

        $height = $this->validHeight($options['height'] ?? null)
            ?? $this->validHeight($settings['height'])
            ?? self::DEFAULT_HEIGHT;

        $this->appendAssets();

        return $view->partial('common/tify', [
            'id' => 'tify-' . ++$this->count,
            'height' => $height,
            'class' => isset($options['class']) ? (string) $options['class'] : '',
            'options' => $tifyOptions,
            'manifestUrl' => $manifestUrl,
        ]);
    }

    /**
     * Builds the TIFY options from the form settings, before any JSON config.
     */
    public function tifyOptions(array $settings): array
    {
        $tify = [
            'language' => $this->language($settings['locale']),
            'colorMode' => in_array($settings['color_mode'], self::COLOR_MODES, true)
                ? $settings['color_mode']
                : 'auto',
            'translationsDirUrl' => $this->getView()->assetUrl('vendor/tify/translations', 'Tify', false, false),
        ];

        $views = $this->parseViews($settings['views']);
        if ($views !== null) {
            $tify['views'] = $views;
        }

        // An empty start view means TIFY's own default: only the media view.
        if (in_array($settings['view'], self::VIEWS, true)) {
            $tify['view'] = $settings['view'];
        }

        $queryKey = $this->validQueryKey($settings['url_query_key']);
        if ($queryKey) {
            $tify['urlQueryKey'] = $queryKey;
        }

        return $tify;
    }

    /**
     * Reads the settings that apply to the current request: on a site page
     * a non-empty site setting replaces the main setting, in the admin only
     * the main settings apply.
     */
    public function settings(): array
    {
        $view = $this->getView();
        $isSite = $view->status()->isSiteRequest();
        $setting = $view->plugin('setting');
        $siteSetting = $isSite ? $view->plugin('siteSetting') : null;

        $value = function (string $name, $default = '') use ($setting, $siteSetting) {
            if ($siteSetting) {
                $site = $siteSetting($name);
                if ($site !== null && $site !== '' && $site !== []) {
                    return $site;
                }
            }
            $main = $setting($name);
            return $main === null ? $default : $main;
        };

        if ($isSite) {
            $locale = $siteSetting('locale') ?: $setting('locale');
        } else {
            $locale = ($view->identity() ? $view->userSetting('locale') : null) ?: $setting('locale');
        }

        return [
            'height' => (string) $value('tify_height', self::DEFAULT_HEIGHT),
            'color_mode' => (string) $value('tify_color_mode', 'auto'),
            'views' => (string) $value('tify_views', ''),
            'view' => (string) $value('tify_view', ''),
            'url_query_key' => (string) $value('tify_url_query_key', ''),
            'main_config' => (string) $setting('tify_config', ''),
            'site_config' => $siteSetting ? (string) $siteSetting('tify_config', '') : '',
            'locale' => (string) $locale,
        ];
    }

    /**
     * Maps an Omeka locale ("nl", "nl_NL", "pt-BR") to a TIFY language. TIFY
     * only knows its built-in English and the translations shipped in
     * asset/vendor/tify/translations, so anything else falls back to English.
     */
    public function language(?string $locale): string
    {
        $language = strtolower(substr(trim((string) $locale), 0, 2));
        if ($language === '' || $language === 'en' || !preg_match('/^[a-z]{2}$/', $language)) {
            return 'en';
        }
        $file = dirname(__DIR__, 3) . '/asset/vendor/tify/translations/' . $language . '.json';
        return is_file($file) ? $language : 'en';
    }

    /**
     * Parses a comma separated list of views, keeping the given order and
     * dropping unknown and duplicate names. Returns null when nothing is set,
     * so that TIFY keeps its own default.
     */
    public function parseViews(?string $views): ?array
    {
        $views = trim((string) $views);
        if ($views === '') {
            return null;
        }
        $result = [];
        foreach (preg_split('/[\s,;]+/', strtolower($views), -1, PREG_SPLIT_NO_EMPTY) as $name) {
            if (in_array($name, self::VIEWS, true) && !in_array($name, $result, true)) {
                $result[] = $name;
            }
        }
        return $result;
    }

    public function validHeight(?string $height): ?string
    {
        $height = trim((string) $height);
        if (preg_match('/^\d+$/', $height)) {
            return $height . 'px';
        }
        return preg_match('/^\d+(\.\d+)?(px|em|rem|vh|vw|svh|dvh|%)$/', $height) ? $height : null;
    }

    /**
     * TIFY only accepts the characters A-Z a-z 0-9 - _ in its URL query key.
     */
    public function validQueryKey(?string $key): ?string
    {
        $key = trim((string) $key);
        return preg_match('/^[A-Za-z0-9_-]+$/', $key) ? $key : null;
    }

    /**
     * Decodes a JSON config. Anything but a JSON object is ignored, so that a
     * typo in a setting cannot break the page; the settings form validates it
     * on save.
     */
    public function decodeJson(?string $json): array
    {
        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) && !array_is_list($data) ? $data : [];
    }

    /**
     * Gives the second and later viewers on a page their own URL query key
     * ("tify", "tify-2", "tify-3"), since TIFY needs a unique key for each.
     */
    public function uniqueQueryKey(array $tifyOptions): array
    {
        $key = isset($tifyOptions['urlQueryKey']) && is_string($tifyOptions['urlQueryKey'])
            ? $this->validQueryKey($tifyOptions['urlQueryKey'])
            : null;
        if (!$key) {
            unset($tifyOptions['urlQueryKey']);
            return $tifyOptions;
        }
        $unique = $key;
        for ($i = 2; in_array($unique, $this->queryKeys, true); ++$i) {
            $unique = $key . '-' . $i;
        }
        $this->queryKeys[] = $unique;
        $tifyOptions['urlQueryKey'] = $unique;
        return $tifyOptions;
    }

    /**
     * The 1-based page of a media in its item's manifest, when it can be
     * known: the manifest built by IIIF Server has a canvas for each image of
     * the item, in media order.
     */
    public function pageOfMedia(AbstractResourceEntityRepresentation $media): ?array
    {
        $item = $media->item();
        if (!$item || !$this->isImage($media)) {
            return null;
        }
        $page = 0;
        foreach ($item->media() as $sibling) {
            if (!$this->isImage($sibling)) {
                continue;
            }
            ++$page;
            if ($sibling->id() === $media->id()) {
                return [$page];
            }
        }
        return null;
    }

    protected function isImage(AbstractResourceEntityRepresentation $media): bool
    {
        return strpos((string) $media->mediaType(), 'image/') === 0
            || in_array($media->renderer(), ['iiif', 'tile'], true);
    }

    protected function validUrl(string $url): ?string
    {
        $url = trim($url);
        return filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url) ? $url : null;
    }

    /**
     * Adds the TIFY library and the module's starter script. TIFY is an ES
     * module; module scripts are deferred and run in document order, so the
     * starter runs after TIFY has set window.Tify. Laminas' head helpers drop
     * duplicates, so this is safe for every viewer on the page.
     */
    protected function appendAssets(): void
    {
        $view = $this->getView();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('vendor/tify/tify.css', 'Tify'))
            ->appendStylesheet($assetUrl('css/tify.css', 'Tify'));
        $view->headScript()
            ->appendFile($assetUrl('vendor/tify/tify.js', 'Tify'), 'module')
            ->appendFile($assetUrl('js/tify.js', 'Tify'), 'module');
    }
}
