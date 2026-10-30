<?php

namespace Plugins\Easyapplabs\Boardmoa;

use Illuminate\Support\Facades\Cache;

/**
 * 비회원 글·댓글 비밀번호 시도 제한 (다른 엔진의 연결 모듈과 같다): 같은 IP 가 같은 글을 10분 안에 10번 틀리면
 * 그동안은 맞는 비밀번호도 막는다(429 too_many_attempts). 엔진의 비밀글 비밀번호 제한도 함께 걸린다.
 */
class Throttle
{
    const LIMIT = 10;

    const WINDOW = 600;

    private static function key(string $slug, int $id): string
    {
        return 'boardmoa:pw:'.sha1((string) Api::$request->ip().'|'.$slug.'|'.$id);
    }

    public static function check(string $slug, int $id): void
    {
        if ((int) Cache::get(self::key($slug, $id), 0) >= self::LIMIT) {
            throw new ApiError(429, 'too_many_attempts', '비밀번호를 여러 번 틀렸습니다. 잠시 뒤에 다시 해 주세요.');
        }
    }

    public static function fail(string $slug, int $id): void
    {
        $key = self::key($slug, $id);
        Cache::add($key, 0, self::WINDOW);
        Cache::increment($key);
    }
}
