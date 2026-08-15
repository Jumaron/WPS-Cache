<?php

declare(strict_types=1);

namespace WPSCache\Optimization\Html;

use WPSCache\Config\Settings;
use WPSCache\Contracts\HtmlProcessor;
use WPSCache\Contracts\Module;

/** Browser-derived, per-URL critical and used-CSS profiles for same-origin stylesheets. */
final class RenderedCssOptimizer implements HtmlProcessor, Module
{
    /** @var array<string, mixed> */
    private array $settings;
    private string $root;
    private string $baseUrl;

    /** @param Settings|array<string, mixed> $settings */
    public function __construct(Settings|array $settings, ?string $root = null, ?string $baseUrl = null)
    {
        $this->settings = $settings instanceof Settings ? $settings->all() : $settings;
        $this->root = rtrim($root ?? WPSC_CACHE_DIR . 'rendered-css', '/\\') . DIRECTORY_SEPARATOR;
        $this->baseUrl = rtrim($baseUrl ?? content_url('cache/wps-cache/rendered-css'), '/');
    }

    public function id(): string
    {
        return 'rendered-css';
    }

    public function boot(): void
    {
        if (empty($this->settings['css_rendered_profiles'])) {
            return;
        }
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('wps-cache/v1', '/css-profile', [
            'methods' => 'POST',
            'callback' => [$this, 'recordProfile'],
            'permission_callback' => static fn(): bool => current_user_can('manage_options'),
        ]);
    }

    public function process(string $html): string
    {
        if (empty($this->settings['css_rendered_profiles']) || stripos($html, '</head>') === false) {
            return $html;
        }
        $url = $this->requestPath();
        $device = $this->device();
        $profile = $this->loadProfile($url, $device);
        $head = '';
        if (is_array($profile)) {
            $critical = (string) ($profile['critical'] ?? '');
            if (!empty($this->settings['css_critical_rendered']) && $critical !== '') {
                $head .= '<style id="wpsc-critical-css" data-wpsc-critical>' . $this->safeInlineCss($critical) . '</style>';
            }
            if (!empty($this->settings['css_linked_prune']) && !empty($profile['used_url']) && is_array($profile['sources'] ?? null)) {
                $html = $this->removeProfiledStylesheets($html, $profile['sources']);
                $href = esc_url((string) $profile['used_url']);
                if (!empty($this->settings['css_critical_rendered'])) {
                    $head .= '<link rel="preload" href="' . $href . '" as="style" data-wpsc-critical onload="this.onload=null;this.rel=\'stylesheet\'"><noscript><link rel="stylesheet" href="' . $href . '" data-wpsc-critical></noscript>';
                } else {
                    $head .= '<link rel="stylesheet" href="' . $href . '" data-wpsc-critical>';
                }
            }
        }
        if ($head !== '') {
            $html = preg_replace('~</head>~i', $head . '</head>', $html, 1) ?? $html;
        }
        if (($profile === null || isset($_GET['wpsc_refresh_css_profile'])) && current_user_can('manage_options')) {
            $script = $this->captureScript($url, $device);
            $html = stripos($html, '</body>') !== false
                ? (preg_replace('~</body>~i', $script . '</body>', $html, 1) ?? $html)
                : $html . $script;
        }
        return $html;
    }

    public function recordProfile(mixed $request): mixed
    {
        $token = is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('token') : '';
        if (!hash_equals($this->token(), $token)) {
            return new \WP_Error('invalid_token', 'Invalid CSS profile token.', ['status' => 403]);
        }
        $originHost = (string) parse_url((string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
        if ($originHost !== '' && strcasecmp($originHost, (string) parse_url(home_url('/'), PHP_URL_HOST)) !== 0) {
            return new \WP_Error('invalid_origin', 'Cross-origin CSS profile denied.', ['status' => 403]);
        }
        $rateKey = 'wpsc_css_profile_rate_' . hash_hmac('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), wp_salt('nonce'));
        $rate = (int) get_transient($rateKey);
        if ($rate >= 10) {
            return new \WP_Error('rate_limited', 'CSS profile rate exceeded.', ['status' => 429]);
        }
        set_transient($rateKey, $rate + 1, MINUTE_IN_SECONDS);
        $payload = is_object($request) && method_exists($request, 'get_json_params') ? $request->get_json_params() : [];
        $result = $this->storeProfile(is_array($payload) ? $payload : []);
        if (!$result) {
            return new \WP_Error('invalid_profile', 'The rendered CSS profile was rejected.', ['status' => 400]);
        }
        do_action('wpsc_clear_cache');
        return rest_ensure_response(['stored' => true]);
    }

    /** @param array<string, mixed> $payload */
    public function storeProfile(array $payload): bool
    {
        $url = $this->normalizePath((string) ($payload['url'] ?? '/'));
        $device = in_array($payload['device'] ?? '', ['desktop', 'mobile', 'tablet'], true) ? (string) $payload['device'] : 'desktop';
        $critical = $this->sanitizeCss((string) ($payload['critical'] ?? ''), 262144);
        $used = $this->sanitizeCss((string) ($payload['used'] ?? ''), 786432);
        $sources = array_values(array_unique(array_filter(array_map(fn(mixed $source): string => $this->sameOriginPath((string) $source), (array) ($payload['sources'] ?? [])))));
        if ($url === '' || $sources === [] || $used === '') {
            return false;
        }
        if (!$this->prepareDirectory()) {
            return false;
        }
        $key = $this->profileKey($url, $device);
        $cssFile = $this->root . $key . '.css';
        if (!$this->atomicWrite($cssFile, $used)) {
            return false;
        }
        $profile = [
            'version' => 1,
            'url' => $url,
            'device' => $device,
            'created' => time(),
            'critical' => $critical,
            'sources' => $sources,
            'used_url' => $this->baseUrl . '/' . $key . '.css?v=' . substr(hash('sha256', $used), 0, 12),
        ];
        $json = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return is_string($json) && $this->atomicWrite($this->root . $key . '.json', $json);
    }

    /** @return array<string, mixed>|null */
    public function loadProfile(string $url, string $device): ?array
    {
        $file = $this->root . $this->profileKey($this->normalizePath($url), $device) . '.json';
        if (!is_file($file)) {
            return null;
        }
        $retention = max(1, (int) ($this->settings['css_profile_retention'] ?? 30)) * DAY_IN_SECONDS;
        if ((int) filemtime($file) < time() - $retention) {
            return null;
        }
        $profile = json_decode((string) file_get_contents($file), true);
        return is_array($profile) && (int) ($profile['version'] ?? 0) === 1 ? $profile : null;
    }

    /** @param list<mixed> $sources */
    private function removeProfiledStylesheets(string $html, array $sources): string
    {
        $lookup = array_fill_keys(array_map('strval', $sources), true);
        return preg_replace_callback('~<link\b[^>]*\brel=["\'][^"\']*stylesheet[^"\']*["\'][^>]*>~i', function (array $match) use ($lookup): string {
            if (stripos($match[0], 'data-wpsc-keep') !== false || !preg_match('~\bhref=["\']([^"\']+)["\']~i', $match[0], $href)) {
                return $match[0];
            }
            $path = $this->sameOriginPath(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5));
            return $path !== '' && isset($lookup[$path]) ? '' : $match[0];
        }, $html) ?? $html;
    }

    private function captureScript(string $url, string $device): string
    {
        $endpoint = add_query_arg('token', $this->token(), rest_url('wps-cache/v1/css-profile'));
        return '<script id="wpsc-rendered-css-audit">(()=>{const endpoint=' . wp_json_encode($endpoint) . ',nonce=' . wp_json_encode(wp_create_nonce('wp_rest')) . ',page=' . wp_json_encode($url) . ',device=' . wp_json_encode($device) . ';const dynamic=/:(hover|focus|active|visited|checked|target|focus-within|focus-visible|before|after|first|last|nth|not|has|is|where)/i;function hit(sel,critical){try{if(dynamic.test(sel))return true;for(const el of document.querySelectorAll(sel)){if(!critical)return true;const r=el.getBoundingClientRect(),s=getComputedStyle(el);if(r.bottom>=0&&r.top<=innerHeight&&r.width>0&&r.height>0&&s.visibility!=="hidden"&&s.display!=="none")return true}}catch(e){return true}return false}function absolute(css,base){return css.replace(/url\(\s*(["\x27]?)(?!data:|blob:|#)([^)"\x27]+)\1\s*\)/gi,(all,q,value)=>{try{return"url(\x22"+new URL(value,base).href.replace(/\x22/g,"%22")+"\x22)"}catch(e){return all}})}function walk(rules,critical,base){let out="";for(const rule of rules){try{if(rule.type===CSSRule.STYLE_RULE){const selectors=rule.selectorText.split(",").filter(s=>hit(s.trim(),critical));if(selectors.length)out+=selectors.join(",")+"{"+absolute(rule.style.cssText,base)+"}"}else if(rule.type===CSSRule.IMPORT_RULE&&rule.styleSheet){out+=walk(rule.styleSheet.cssRules,critical,rule.href||base)}else if(rule.cssRules){const inner=walk(rule.cssRules,critical,base),open=rule.cssText.indexOf("{");if(inner&&open>=0)out+=rule.cssText.slice(0,open+1)+inner+"}"}else if(!critical&&(rule.type===CSSRule.FONT_FACE_RULE||rule.type===CSSRule.KEYFRAMES_RULE))out+=absolute(rule.cssText,base)}catch(e){}}return out}function run(){let used="",critical="",sources=[];for(const sheet of document.styleSheets){try{const same=!sheet.href||new URL(sheet.href,location.href).origin===location.origin;if(!same)continue;const removable=!!sheet.href,base=sheet.href||location.href;if(removable){sources.push(sheet.href);used+=walk(sheet.cssRules,false,base)}critical+=walk(sheet.cssRules,true,base)}catch(e){}}if(!used||!sources.length)return;fetch(endpoint,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},body:JSON.stringify({url:page,device,used,critical,sources}),keepalive:true}).catch(()=>{})}addEventListener("load",()=>setTimeout(()=>("requestIdleCallback"in window?requestIdleCallback(run,{timeout:3000}):run()),500),{once:true})})();</script>';
    }

    private function requestPath(): string
    {
        return $this->normalizePath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    private function normalizePath(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        return '/' . ltrim($path, '/') . ($query !== '' ? '?' . $query : '');
    }

    private function sameOriginPath(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== '' && strcasecmp($host, (string) parse_url(home_url('/'), PHP_URL_HOST)) !== 0) {
            return '';
        }
        return $this->normalizePath($url);
    }

    private function device(): string
    {
        $agent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (preg_match('/ipad|tablet|kindle|silk/', $agent) === 1) {
            return 'tablet';
        }
        return preg_match('/mobile|iphone|ipod|android.*mobile|opera mini/', $agent) === 1 ? 'mobile' : 'desktop';
    }

    private function profileKey(string $url, string $device): string
    {
        return hash('sha256', $device . '|' . $url);
    }

    private function token(): string
    {
        return hash_hmac('sha256', gmdate('Y-m-d') . '|rendered-css|' . home_url('/'), wp_salt('nonce'));
    }

    private function sanitizeCss(string $css, int $maximum): string
    {
        $css = str_ireplace(['</style', '</script'], ['<\\/style', '<\\/script'], $css);
        return substr($css, 0, $maximum);
    }

    private function safeInlineCss(string $css): string
    {
        return str_ireplace('</style', '<\\/style', $css);
    }

    private function prepareDirectory(): bool
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0755, true) && !is_dir($this->root)) {
            return false;
        }
        if (!is_file($this->root . 'index.php')) {
            @file_put_contents($this->root . 'index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
        }
        return is_writable($this->root);
    }

    private function atomicWrite(string $file, string $content): bool
    {
        $temporary = tempnam($this->root, 'wpsc_css_');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0644);
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }
}
