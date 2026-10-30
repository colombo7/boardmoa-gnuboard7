<?php

namespace Plugins\Easyapplabs\Boardmoa;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 설치 현황: 이 사이트 주소를 게시판모아 개발자 서버에 알린다 (관리자 설정 '개발자에게 알리기'로 끌 수 있다).
 * 그누보드7 은 엔진이 GitHub 에서 업데이트하므로 [업데이트 확인] 단추가 없다 — 설치하거나 새 판으로 바뀐 뒤
 * 첫 앱 요청이 끝날 때 한 번 알린다(응답을 보낸 뒤라 앱은 기다리지 않는다). 실패해도 조용히 넘어간다.
 */
class Registry
{
    const URL = 'https://easyapplabs.com/boardmoa/registry.php';

    const CONNECTOR = 'appapi-g7';

    public static function shareSite(): bool
    {
        return (bool) g7_plugin_settings('easyapplabs-boardmoa', 'share_site', true);
    }

    /** 설정 상태와 판이 지난번 알림과 다르면 응답을 보낸 뒤 알린다 (알린 뒤 끄면 그것도 알려 서버가 사이트에 다시 물어 목록에서 뺀다) */
    public static function maybeNotify(): void
    {
        try {
            $share = self::shareSite();
            $state = Api::VERSION.'|'.($share ? 'Y' : 'N');
            $prev = Cache::get('boardmoa:registry');
            if ($prev === $state) {
                return;
            }
            Cache::put('boardmoa:registry', $state, now()->addDays(30));
            if (! $share && ! (is_string($prev) && str_ends_with($prev, '|Y'))) {
                return; // 알린 적이 없으면 끈 것을 알릴 필요도 없다
            }
            app()->terminating(function () use ($share) {
                try {
                    Http::timeout(20)->connectTimeout(3)->withUserAgent('BoardMoa-appapi-g7')
                        ->post(self::URL, ['url' => Boards::base().'/', 'connector' => self::CONNECTOR, 'version' => Api::VERSION, 'share_site' => $share]);
                } catch (\Throwable $e) {
                    // 알림 실패는 앱 동작과 상관없다
                }
            });
        } catch (\Throwable $e) {
            // 캐시가 없어도 앱 요청은 그대로
        }
    }
}
