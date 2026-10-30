<?php

namespace Plugins\Easyapplabs\Boardmoa;

/** 본문 HTML 다듬기 */
class Html
{
    /** src·href 의 사이트 안 주소(/로 시작)를 절대 주소로. 같은 사이트 글 링크에는 앱이 바로 여는 ?mid=&document_srl= 를 붙인다 */
    public static function absolutize(string $html, string $base): string
    {
        if ($html === '') {
            return '';
        }
        $html = preg_replace_callback('#\b(src|href|poster)=(["\'])(/(?!/)[^"\']*)\2#i', function ($m) use ($base) {
            return $m[1].'='.$m[2].$base.$m[3].$m[2];
        }, $html) ?? $html;
        $host = preg_quote((string) parse_url($base, PHP_URL_HOST), '#');

        return preg_replace_callback('#\bhref=(["\'])(https?://(?:www\.)?'.$host.'/board/([A-Za-z0-9_\-]+)/(\d+))(["\'])#i', function ($m) {
            return 'href='.$m[1].$m[2].'?mid='.rawurlencode($m[3]).'&amp;document_srl='.$m[4].$m[5];
        }, $html) ?? $html;
    }
}
