<?php
namespace Plugins\Easyapplabs\Boardmoa;

/**
 * 플러그인 문구 언어 (한국어 원문 + 앱과 같은 11개 언어). 한국어 원문을 열쇠로 Text::t('…') 에 넣고, 값 자리는 {0}, {1} …
 * 언어별 문구는 lang/text.<코드>.php (한국어 원문 → 그 언어, 열쇠는 text.en.php 와 같다). 다른 엔진의 연결 모듈과 같은 방식이다.
 * 앱에 보내는 문구(오류 등)는 앱이 보낸 언어(X-App-Lang: ko, en, ja, es, pt-BR, de, fr, it, vi, id, zh-Hant, zh-Hans), 없으면(예전 앱) 사이트 언어.
 * 없는 언어는 영어(한국어는 한국어일 때만). 문구 하나가 없으면 그 언어 → 기본 언어(pt-BR → pt) → 영어 → 한국어 원문.
 * 엔진이 만든 문구와 사이트 내용(글)은 바꾸지 않는다.
 */
class Text
{
    /** 문구 언어 (앱의 Lang.Code 와 같다). ko 는 원문이라 파일이 없다 */
    const LANGUAGES = ['ko', 'en', 'ja', 'es', 'pt-BR', 'de', 'fr', 'it', 'vi', 'id', 'zh-Hant', 'zh-Hans'];

    private static $code = null;
    private static $tables = [];

    /** 이번 요청의 문구 언어 (LANGUAGES 가운데 하나) */
    public static function code()
    {
        if (self::$code === null) {
            $requested = Api::$request ? trim((string)Api::$request->header('X-App-Lang', '')) : '';
            if ($requested !== '') {
                self::$code = self::resolve($requested);
            } else {
                $site = (string)config('app.locale', 'ko');
                self::$code = $site === '' ? 'ko' : self::resolve($site);
            }
        }
        return self::$code;
    }

    /**
     * 언어 표시(앱 코드, 사이트 locale ko·ja·zh-TW·pt_BR 꼴 등) → 문구 언어. 없는 언어는 영어.
     * 중국어는 번체(Hant·TW·HK·MO)는 zh-Hant, 그 밖(Hans·CN·SG·지역 없는 zh)은 zh-Hans, 포르투갈어는 모두 pt-BR.
     */
    public static function resolve($tag)
    {
        $tag = strtolower(str_replace('_', '-', trim((string)$tag)));
        foreach (self::LANGUAGES as $code) {
            if (strtolower($code) === $tag) {
                return $code;
            }
        }
        $parts = explode('-', $tag);
        $base = $parts[0];
        if ($base === 'pt') {
            return 'pt-BR';
        }
        if ($base === 'jp') {
            return 'ja';
        }
        if ($base === 'in') {
            return 'id';
        }
        if ($base === 'zh') {
            $rest = array_slice($parts, 1);
            $traditional = in_array('hant', $rest, true) || (!in_array('hans', $rest, true) && array_intersect(['tw', 'hk', 'mo'], $rest));
            return $traditional ? 'zh-Hant' : 'zh-Hans';
        }
        return in_array($base, self::LANGUAGES, true) ? $base : 'en';
    }

    /** lang/text.<코드>.php (없으면 빈 표) */
    private static function table($code)
    {
        if (!isset(self::$tables[$code])) {
            $file = dirname(__DIR__) . '/lang/text.' . $code . '.php';
            self::$tables[$code] = preg_match('/^[A-Za-z-]+$/', $code) && is_file($file) ? (array)include $file : [];
        }
        return self::$tables[$code];
    }

    public static function t($ko, ...$args)
    {
        $text = $ko;
        $code = self::code();
        if ($code !== 'ko') {
            foreach (array_unique([$code, explode('-', $code)[0], 'en']) as $c) {
                $table = self::table($c);
                if (isset($table[$ko])) {
                    $text = $table[$ko];
                    break;
                }
            }
        }
        foreach ($args as $i => $value) {
            $text = str_replace('{' . $i . '}', (string)$value, $text);
        }
        return $text;
    }

    /**
     * 엔진에 넘길 언어(Accept-Language): 앱 언어가 사이트에서 켠 언어(app.supported_locales)면 그 언어로 처리한다
     * (게시판 이름·엔진 문구가 그 언어). 사이트에 없는 언어면 영어(켰을 때), 아니면 사이트 기본 언어.
     * X-Content-Lang: site(앱 설정 '게시판 언어: 사이트 기본 언어')나 머리말이 없으면(예전 앱) 사이트 기본 언어.
     */
    public static function engineLocale()
    {
        $site = (string)config('app.locale', 'ko');
        $request = Api::$request;
        if (!$request || strtolower(trim((string)$request->header('X-Content-Lang', ''))) === 'site') {
            return $site;
        }
        $tag = strtolower(str_replace('_', '-', trim((string)$request->header('X-App-Lang', ''))));
        if ($tag === '') {
            return $site;
        }
        $supported = (array)config('app.supported_locales', [$site]);
        $lower = [];
        foreach ($supported as $loc) {
            $lower[strtolower((string)$loc)] = (string)$loc;
        }
        $base = explode('-', $tag)[0];
        $want = [$tag, $base];
        if ($base === 'zh') {
            $want = array_merge($want, preg_match('/-(hans|cn|sg)\b/', $tag) ? ['zh-cn', 'zh-hans'] : ['zh-tw', 'zh-hk', 'zh-hant']);
        }
        foreach (array_keys($lower) as $l) {
            if (strpos($l, $base . '-') === 0) {
                $want[] = $l;
            }
        }
        foreach ($want as $w) {
            if (isset($lower[$w])) {
                return $lower[$w];
            }
        }
        return isset($lower['en']) ? $lower['en'] : $site;
    }
}
