<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Extension\AbstractPlugin;

/**
 * 게시판모아(BoardMoa) 앱 연결 플러그인.
 *
 * 앱 규약(docs/API.md, 다른 엔진용 연결 모듈·플러그인과 같은 규약)을 `/api/plugins/easyapplabs-boardmoa/api?r=<route>` 로 받는다.
 * 게시판 일은 그누보드7 게시판 모듈의 공개 API 를 이 요청 안에서 그대로 실행해 처리한다(Engine::call) —
 * 권한·비밀글·스팸 방지·포인트·알림은 웹 화면과 같은 엔진 코드가 판단한다.
 */
class Plugin extends AbstractPlugin
{
    // 앱 로그인 토큰 표(boardmoa_tokens)는 database/migrations 로 만들고, 플러그인을 지우면 엔진이 되돌린다

    /**
     * 관리자 설정: 개발자에게 알리기 (설치 현황, 다른 엔진용 연결 모듈·플러그인과 같은 설정)
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            'share_site' => [
                'type' => 'boolean',
                'default' => true,
                'label' => [
                    'ko' => '개발자에게 알리기',
                    'en' => 'Let the developer know',
                ],
                'hint' => [
                    'ko' => '이 사이트 주소와 플러그인 버전을 게시판모아 개발자에게 알립니다(설치하거나 새 판으로 바뀐 뒤 한 번). 알려 준 사이트는 앱의 추천 사이트에 들어갈 수 있습니다. 끄면 알리지 않고, 이미 알린 사이트도 목록에서 빠집니다.',
                    'en' => 'Sends this site address and the plugin version to the BoardMoa developer (once after installing or updating). Shared sites may be added to the app\'s suggested sites. Turn off to stop sharing; the site is also removed from the list.',
                ],
                'required' => false,
            ],
        ];
    }

    /**
     * 설정 기본값
     *
     * @return array<string, mixed>
     */
    public function getConfigValues(): array
    {
        return ['share_site' => true];
    }
}
