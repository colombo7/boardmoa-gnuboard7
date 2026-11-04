<?php

namespace Plugins\Easyapplabs\Boardmoa;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * '페이지로 보기'(1.1.0~): 웹과 같은 화면을 HTML 로.
 *
 * 그누보드7 화면은 브라우저의 React 가 그려서 서버가 보낼 HTML 이 없다. 대신 엔진의 공식 기능인
 * 검색엔진용 화면 그리기(App\Seo, 관리자 환경설정 SEO)를 같은 요청 안에서 불러 완성된 HTML 을 받는다.
 *  - 회원이면 회원 화면: 그리기가 사이트 자신에게 보내는 데이터 요청에 이 회원의 토큰을 붙인다(Laravel Http 미들웨어).
 *    비회원이면 토큰 없이(웹 비회원과 같은 권한). 결과는 캐시하지 않는다(엔진의 봇 화면 캐시도 쓰지 않음).
 *  - 관리자가 SEO 를 꺼도 그린다: 사이트 설정은 그대로 두고 이 요청의 레이아웃 사본만 '그려도 됨'으로 본다(SeoLayout).
 *  - 화면 폭: 엔진은 봇을 PC 로 보고 그리므로, 앱이 보낸 폭(width, 없으면 폰 390)의 responsive 모양을 먼저 고른다(SeoLayout).
 *  - 스크립트·양식, 본문 바깥의 머리·메뉴·꼬리·서랍 메뉴는 뺀다. 본문 칸에 data-bh-main.
 * 그릴 수 없으면 null: 앱은 그 페이지를 앱 꾸밈으로 보여 준다.
 */
final class WebPage
{
    /** 앱 화면 폭(px). 엔진의 SEO 그리기는 봇을 PC(1024)로 보고 칸마다의 responsive 모양을 고르므로, SeoLayout 이 그 전에 이 폭의 모양을 골라 둔다 */
    public static int $width = 390;

    public static function render(string $path, int $width = 390): ?array
    {
        self::$width = max(320, min($width, 2560));
        try {
            $html = self::renderHtml($path);
        } catch (\Throwable $e) {
            return null;
        }

        return $html === null ? null : self::clean($html);
    }

    /**
     * 앱 메뉴를 웹 메뉴와 같게 (새 앱이 menu 에 web_pages=1 을 보낼 때, 1.1.4~).
     * 손으로 정하지 않고 엔진이 그린 첫 화면에서 읽는다: 머리(<header>)의 메뉴 줄 순서대로(웹의 '더보기'에 접힌 게시판도
     * 이어서 펼친다 — 사용자 결정), 꼬리(<footer>)의 묶음(제목 h4 + 링크)은 그 이름 그대로. 게시판·페이지는 이 회원이 볼 수 있는 것만,
     * 그 밖의 같은 사이트 주소(인기·쇼핑 등)는 웹 화면 전용 페이지 'web:<경로>'. 그리지 못하면 null (앱 메뉴는 예전대로)
     */
    public static function siteMenu(array $known): ?array
    {
        try {
            $html = self::renderHtml('/');
        } catch (\Throwable $e) {
            return null;
        }
        if ($html === null) {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $header = $dom->getElementsByTagName('header')->item(0);
        if (! $header) {
            return null;
        }
        $home = $known[Boards::HOME_MID] ?? null;
        $items = $home ? [$home] : [];
        $seen = [Boards::HOME_MID => true];
        foreach ($header->getElementsByTagName('nav') as $nav) {
            foreach ($nav->getElementsByTagName('a') as $a) {
                $item = self::menuItem($a, $known);
                if (! $item || isset($seen[$item['mid']])) {
                    continue;
                }
                $seen[$item['mid']] = true;
                $items[] = $item;
            }
        }
        $footer = $dom->getElementsByTagName('footer')->item(0);
        foreach ($footer ? $footer->getElementsByTagName('h4') : [] as $h) {
            $children = [];
            foreach ($h->parentNode->getElementsByTagName('a') as $a) {
                $item = self::menuItem($a, $known);
                if ($item && ! isset($seen[$item['mid']])) {
                    $seen[$item['mid']] = true;
                    $children[] = $item;
                }
            }
            if ($children) {
                $items[] = self::section(trim($h->textContent), $children);
            }
        }

        return count($items) > 1 ? $items : null;
    }

    private static function section(string $title, array $children): array
    {
        return ['title' => $title, 'mid' => null, 'module' => null, 'grant' => null, 'url' => null, 'open_window' => false, 'children' => $children];
    }

    /** 메뉴 링크 하나 → 앱 메뉴 항목. 게시판·페이지는 이 회원 메뉴에 있는 것만(이름은 웹 그대로), 로그인·가입·내 정보 같은 회원 기능은 뺀다 */
    private static function menuItem(\DOMElement $a, array $known): ?array
    {
        $href = html_entity_decode($a->getAttribute('href'), ENT_QUOTES, 'UTF-8');
        $base = Boards::base();
        if (str_starts_with($href, $base)) {
            $href = substr($href, strlen($base));
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        if ($path === '' || $path[0] !== '/' || str_starts_with($href, '//') || $path === '/' || parse_url($href, PHP_URL_HOST)) {
            return null;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        if (preg_match('#^/board/([A-Za-z0-9_\-]+)/?$#', $path, $m)) {
            $item = $known[$m[1]] ?? null;
        } elseif (preg_match('#^/page/([A-Za-z0-9_\-]+)/?$#', $path, $m)) {
            $item = $known['page-'.$m[1]] ?? null;
        } elseif (preg_match('#^/(api|admin|login|logout|register|password|mypage|cart|checkout|orders?)(/|$)#', $path) || $title === '') {
            return null;
        } else {
            $item = ['title' => $title, 'mid' => 'web:'.$path, 'module' => 'page', 'grant' => null, 'url' => $base.$path, 'open_window' => false, 'children' => []];
        }
        if (! $item) {
            return null;
        }
        if ($title !== '') {
            $item['title'] = $title;
        }

        return $item;
    }

    private static function renderHtml(string $path): ?string
    {
        if (! interface_exists(\App\Seo\Contracts\SeoRendererInterface::class) || ! class_exists(\App\Seo\SeoRenderer::class)) {
            return null;
        }
        $base = Boards::base();
        $bearer = Engine::$bearer;
        if ($bearer !== '') {
            // 사이트 자신에게 가는 요청에만 (그리기의 데이터 요청은 사이트 주소로만 간다)
            Http::globalRequestMiddleware(function ($request) use ($base, $bearer) {
                $uri = (string) $request->getUri();

                return str_starts_with($uri, $base.'/') || $uri === $base
                    ? $request->withHeader('Authorization', 'Bearer '.$bearer)
                    : $request;
            });
        }
        $renderer = self::layoutCompatible()
            ? app()->make(\App\Seo\SeoRenderer::class, ['layoutService' => app()->make(SeoLayout::class)])
            : app(\App\Seo\Contracts\SeoRendererInterface::class);
        $html = $renderer->render(Request::create($path, 'GET'));

        return is_string($html) && $html !== '' ? $html : null;
    }

    /** 엔진의 getLayout·SeoRenderer 꼴이 SeoLayout 이 아는 것과 같은지 (다르면 SeoLayout 을 불러오지 않는다: 관리자가 끈 페이지만 앱 꾸밈으로) */
    private static function layoutCompatible(): bool
    {
        try {
            $get = new \ReflectionMethod(\App\Services\LayoutService::class, 'getLayout');
            $names = array_map(fn ($p) => $p->getName(), $get->getParameters());
            if ($names !== ['templateIdentifier', 'layoutName', 'withSourceMeta'] || (string) $get->getReturnType() !== 'array') {
                return false;
            }
            $ctor = (new \ReflectionClass(\App\Seo\SeoRenderer::class))->getConstructor();
            foreach ($ctor ? $ctor->getParameters() : [] as $p) {
                if ($p->getName() === 'layoutService') {
                    return (string) $p->getType() === \App\Services\LayoutService::class;
                }
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    private static function clean(string $html): ?array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body) {
            return null;
        }

        $css = [];
        $style = '';
        foreach (iterator_to_array($dom->getElementsByTagName('link')) as $link) {
            if (strtolower($link->getAttribute('rel')) === 'stylesheet' && strtolower($link->getAttribute('media')) !== 'print') {
                $href = Boards::absolute(html_entity_decode($link->getAttribute('href'), ENT_QUOTES, 'UTF-8'));
                if ($href) {
                    $css[$href] = true;
                }
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $tag) {
            if ($tag->parentNode && strtolower($tag->parentNode->nodeName) === 'head') {
                $style .= $tag->textContent."\n";
            }
        }
        foreach (['script', 'noscript', 'form', 'link', 'template'] as $name) {
            foreach (iterator_to_array($body->getElementsByTagName($name)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        self::repairLinks($body);
        $main = self::mainOf($body);
        if (! $main) {
            return null;
        }
        // 본문과 그 조상만 남긴다: 조상 옆의 머리·메뉴·꼬리·서랍 메뉴·통화 선택은 웹의 틀이라 앱에서는 탭·메뉴가 대신한다
        for ($node = $main; $node && $node !== $body; $node = $node->parentNode) {
            foreach (iterator_to_array($node->parentNode->childNodes) as $sibling) {
                if ($sibling !== $node && ($sibling instanceof \DOMElement || trim((string) $sibling->textContent) !== '')) {
                    $node->parentNode->removeChild($sibling);
                }
            }
        }
        $main->setAttribute('data-bh-main', '1');
        if (trim($main->textContent) === '' && ! $main->getElementsByTagName('img')->length) {
            return null;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        $out = preg_replace('/<!--.*?-->/s', '', $out);
        if ($style !== '') {
            $out = '<style>'.$style.'</style>'.$out;
        }

        return [
            'content_html' => Html::absolutize($out, Boards::base()),
            'css' => array_keys($css),
            'body_class' => trim($body->getAttribute('class')),
            'rendered' => true,
        ];
    }

    /**
     * 엔진의 SEO 그리기는 테마 계산식의 글자 더하기('/' + …)를 계산하지 못해, 주소 앞머리가 계산식인 링크가 빈 앞머리로 나온다
     * (예: 쇼핑몰 {{shopBase}}/products → /products, 404). 엔진의 화면 경로표(TemplateRouteResolver)에 없는 주소이면,
     * 앞머리가 계산식인 경로 중 그 식을 뺀 꼴이 같은 것을 찾아 엔진이 풀어 준 실제 주소로 바꾼다 (하나로 정해질 때만, 사이트·테마와 상관없이)
     */
    private static function repairLinks(\DOMElement $body): void
    {
        try {
            if (! class_exists(\App\Seo\TemplateRouteResolver::class)) {
                return;
            }
            $resolver = app(\App\Seo\TemplateRouteResolver::class);
            $load = new \ReflectionMethod($resolver, 'loadActiveRoutes');
            $load->setAccessible(true);
            $loaded = $load->invoke($resolver, 'user');
            $resolve = new \ReflectionMethod($resolver, 'resolveRoutePath');
            $resolve->setAccessible(true);
        } catch (\Throwable $e) {
            return;
        }
        $templated = [];
        foreach ((array) ($loaded[1] ?? []) as $route) {
            $raw = (string) ($route['path'] ?? '');
            if (str_contains($raw, '{{')) {
                $bare = '/'.trim((string) preg_replace(['/\{\{.+?\}\}/', '#/+#'], ['', '/'], ltrim($raw, '*')), '/');
                $templated[$bare][] = $raw;
            }
        }
        if (! $templated) {
            return;
        }
        $base = Boards::base();
        foreach ($body->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            $rel = str_starts_with($href, $base) ? substr($href, strlen($base)) : $href;
            if ($rel === '' || $rel[0] !== '/' || str_starts_with($rel, '//')) {
                continue;
            }
            $path = (string) parse_url($rel, PHP_URL_PATH);
            if (! isset($templated[$path]) || $resolver->routeExists($path)) {
                continue;
            }
            $fixed = array_values(array_unique(array_map(fn ($raw) => (string) $resolve->invoke($resolver, $raw), $templated[$path])));
            if (count($fixed) === 1 && $fixed[0] !== $path && $resolver->routeExists($fixed[0])) {
                $a->setAttribute('href', substr($rel, 0, 0).$fixed[0].substr($rel, strlen($path)));
            }
        }
    }

    /** 본문 칸: <main>, 없으면 머리(<header>)와 꼬리(<footer>) 사이의 칸 중 글이 가장 많은 것 */
    private static function mainOf(\DOMElement $body): ?\DOMElement
    {
        $main = $body->getElementsByTagName('main')->item(0);
        if ($main) {
            return $main;
        }
        $header = $body->getElementsByTagName('header')->item(0);
        if (! $header || ! $header->parentNode) {
            return null;
        }
        $best = null;
        $after = false;
        foreach ($header->parentNode->childNodes as $child) {
            if ($child === $header) {
                $after = true;

                continue;
            }
            if (! $after || ! $child instanceof \DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->nodeName), ['footer', 'nav', 'aside'], true)) {
                break;
            }
            if (! $best || strlen(trim($child->textContent)) > strlen(trim($best->textContent))) {
                $best = $child;
            }
        }

        return $best;
    }
}
