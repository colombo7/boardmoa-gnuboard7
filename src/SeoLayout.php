<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Services\LayoutService;

/**
 * '페이지로 보기' 전용 레이아웃 읽기 (WebPage 가 이 요청 안에서만 SEO 화면 그리기에 넘긴다).
 *
 * 엔진의 SEO 화면 그리기는 레이아웃의 meta.seo 가 꺼져 있거나 모듈 설정에서 SEO 를 끄면 그리지 않는다.
 * 그 설정은 '검색 봇에게 보이지 않기'라서, 앱에서 회원 본인 권한으로 보는 화면에는 해당하지 않는다.
 * 그래서 읽어 온 레이아웃의 사본만 '그려도 됨'으로 본다. 사이트 설정·DB·캐시는 바꾸지 않고, 봇에게는 그대로 꺼져 있다.
 * 엔진의 getLayout 꼴이 바뀌면 WebPage 가 이 클래스를 쓰지 않는다(Reflection 으로 먼저 확인).
 */
class SeoLayout extends LayoutService
{
    private const PRESETS = ['mobile' => [0, 767], 'tablet' => [768, 1023], 'desktop' => [1024, PHP_INT_MAX], 'portable' => [0, 1023]];

    public function getLayout(string $templateIdentifier, string $layoutName, bool $withSourceMeta = false): array
    {
        return self::adapt(parent::getLayout($templateIdentifier, $layoutName, $withSourceMeta));
    }

    /** 레이아웃 사본을 '그려도 됨'·앱 폭 모양으로 */
    public static function adapt(array $layout): array
    {
        $seo = is_array($layout['meta']['seo'] ?? null) ? $layout['meta']['seo'] : [];
        $seo['enabled'] = true;
        unset($seo['toggle_setting']);
        // SEO 를 생각하지 않은 레이아웃은 그릴 때 가져올 데이터 목록이 비어 있다: 그 레이아웃의 데이터를 모두
        if (empty($seo['data_sources'])) {
            $seo['data_sources'] = array_values(array_filter(array_map(
                fn ($ds) => is_array($ds) ? ($ds['id'] ?? null) : null,
                (array) ($layout['data_sources'] ?? [])
            )));
        }
        $layout['meta']['seo'] = $seo;

        return self::responsive($layout);
    }

    /** 칸마다의 responsive 중 앱 폭(WebPage::$width)에 맞는 것을 기본 속성에 합치고 responsive 는 지운다 (엔진 ComponentHtmlMapper·React ResponsiveManager 와 같은 규칙: 사용자 범위 우선, 좁은 범위 우선) */
    private static function responsive(array $node): array
    {
        if (isset($node['responsive']) && is_array($node['responsive'])) {
            $key = self::matching($node['responsive']);
            if ($key !== null) {
                $override = $node['responsive'][$key];
                if (isset($override['props']) && is_array($override['props'])) {
                    $node['props'] = array_merge((array) ($node['props'] ?? []), $override['props']);
                }
                foreach (['if', 'text', 'children', 'iteration'] as $field) {
                    if (array_key_exists($field, $override)) {
                        $node[$field] = $override[$field];
                    }
                }
            }
            unset($node['responsive']);
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = self::responsive($v);
            }
        }

        return $node;
    }

    private static function matching(array $responsive): ?string
    {
        $best = null;
        foreach ($responsive as $key => $override) {
            if (! is_array($override)) {
                continue;
            }
            $key = (string) $key;
            $range = self::PRESETS[$key] ?? (preg_match('/^(-?\d*)-(-?\d*)$/', $key, $m)
                ? [$m[1] === '' ? 0 : (int) $m[1], $m[2] === '' ? PHP_INT_MAX : (int) $m[2]] : null);
            if (! $range || $range[0] > $range[1] || WebPage::$width < $range[0] || WebPage::$width > $range[1]) {
                continue;
            }
            $rank = [isset(self::PRESETS[$key]) ? 1 : 0, $range[1] - $range[0]];
            if ($best === null || $rank < $best[1]) {
                $best = [$key, $rank];
            }
        }

        return $best[0] ?? null;
    }
}
