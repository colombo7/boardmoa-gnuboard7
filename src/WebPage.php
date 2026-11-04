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
