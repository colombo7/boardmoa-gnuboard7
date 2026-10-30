<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * 기기별 앱 로그인 (표 boardmoa_tokens).
 * - 접근 토큰 = 엔진의 Sanctum 토큰(이름 'boardmoa', 1시간). 앱이 보내면 그대로 엔진 API 에 넘긴다 → 엔진이 회원을 판단.
 * - 갱신 토큰 = 이 표에 해시만. 갱신하면 엔진 토큰을 새로 만들고 예전 것은 지운다.
 * 다른 엔진의 연결 모듈과 같은 동작(응답을 놓쳐도 직전 갱신 토큰을 한 번 더 받는다, 비밀번호를 바꾸면 끊긴다).
 */
class Store
{
    const ACCESS_TTL = 3600;

    const REFRESH_TTL = 31536000;

    const TOKEN_NAME = 'boardmoa';

    public static function passwordSig(?string $passwordHash): string
    {
        return hash('sha256', 'boardmoa|'.(string) $passwordHash);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** 엔진 Sanctum 토큰을 새로 만든다 (만료 1시간). [평문, 행 번호] */
    private static function newAccess(User $user): array
    {
        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addSeconds(self::ACCESS_TTL));

        return [$token->plainTextToken, (int) $token->accessToken->getKey()];
    }

    private static function output(string $access, string $refresh): array
    {
        return [
            'access_token' => $access,
            'access_expires_in' => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'refresh_expires_in' => self::REFRESH_TTL,
        ];
    }

    /**
     * 로그인 성공: 기기 줄을 만든다. $engineToken 은 엔진 로그인이 준 토큰(이 기기의 첫 접근 토큰으로 쓴다).
     *
     * @return array{0: array<string, mixed>, 1: int} [앱 토큰, 기기 번호]
     */
    public static function issue(User $user, ?string $engineToken, string $deviceName, string $platform, string $ip): array
    {
        $tokenId = null;
        $access = null;
        if ($engineToken) {
            $row = PersonalAccessToken::findToken($engineToken);
            if ($row && (int) $row->tokenable_id === (int) $user->getKey()) {
                // 엔진 로그인 토큰을 앱 토큰으로 이름·만료만 바꿔 쓴다
                $row->forceFill(['name' => self::TOKEN_NAME, 'expires_at' => now()->addSeconds(self::ACCESS_TTL)])->save();
                $tokenId = (int) $row->getKey();
                $access = $engineToken;
            }
        }
        if ($access === null) {
            [$access, $tokenId] = self::newAccess($user);
        }
        $refresh = Str::random(64);
        $now = time();
        $id = DB::table('boardmoa_tokens')->insertGetId([
            'user_id' => $user->getKey(),
            'access_token_id' => $tokenId,
            'refresh_hash' => self::hash($refresh),
            'refresh_expires' => $now + self::REFRESH_TTL,
            'password_sig' => self::passwordSig($user->password),
            'device_name' => mb_substr($deviceName, 0, 100),
            'platform' => mb_substr($platform, 0, 20),
            'ip_address' => mb_substr($ip, 0, 64),
            'created' => $now,
            'last_used' => $now,
        ]);
        if (mt_rand(1, 50) === 1) {
            self::prune();
        }

        return [self::output($access, $refresh), (int) $id];
    }

    /** 만료된 기기 줄과 그 엔진 토큰을 지운다 */
    private static function prune(): void
    {
        $rows = DB::table('boardmoa_tokens')->where('refresh_expires', '<', time())->get(['id', 'access_token_id']);
        foreach ($rows as $row) {
            self::revoke((int) $row->id);
        }
    }

    /** 접근 토큰(Bearer)의 기기 줄. 엔진 토큰이 없거나 만료면 null */
    public static function findByAccess(string $access): ?object
    {
        if ($access === '') {
            return null;
        }
        $token = PersonalAccessToken::findToken($access);
        if (! $token || $token->name !== self::TOKEN_NAME) {
            return null;
        }
        if ($token->expires_at && $token->expires_at->isPast()) {
            return null;
        }
        $row = DB::table('boardmoa_tokens')->where('access_token_id', $token->getKey())->first();

        return $row ?: null;
    }

    /** 새 접근 토큰이 처음 쓰였으니 직전 갱신 토큰은 더 받지 않는다 */
    public static function touch(object $row, string $ip): void
    {
        $now = time();
        if ($row->prev_refresh_hash !== null || $row->last_used < $now - 60) {
            DB::table('boardmoa_tokens')->where('id', $row->id)->update(['last_used' => $now, 'prev_refresh_hash' => null, 'ip_address' => mb_substr($ip, 0, 64)]);
        }
    }

    /**
     * 갱신 토큰으로 새 쌍을 발급 (회전).
     *
     * @return array{0: object, 1: array<string, mixed>}|null [기기 줄, 새 토큰]
     */
    public static function rotate(string $refresh, string $ip): ?array
    {
        if ($refresh === '') {
            return null;
        }
        $hash = self::hash($refresh);
        $row = DB::table('boardmoa_tokens')->where('refresh_hash', $hash)->orWhere('prev_refresh_hash', $hash)->first();
        if (! $row || $row->refresh_expires < time()) {
            return null;
        }
        $user = User::find($row->user_id);
        if (! $user) {
            self::revoke((int) $row->id);

            return null;
        }
        [$access, $tokenId] = self::newAccess($user);
        if ($row->access_token_id) {
            PersonalAccessToken::whereKey($row->access_token_id)->delete();
        }
        $newRefresh = Str::random(64);
        $now = time();
        DB::table('boardmoa_tokens')->where('id', $row->id)->update([
            'access_token_id' => $tokenId,
            'refresh_hash' => self::hash($newRefresh),
            'prev_refresh_hash' => $row->refresh_hash === $hash ? $hash : $row->prev_refresh_hash,
            'refresh_expires' => $now + self::REFRESH_TTL,
            'ip_address' => mb_substr($ip, 0, 64),
            'last_used' => $now,
        ]);

        return [$row, self::output($access, $newRefresh)];
    }

    /** 기기 줄 하나와 그 엔진 토큰을 지운다 */
    public static function revoke(int $id): void
    {
        $tokenId = DB::table('boardmoa_tokens')->where('id', $id)->value('access_token_id');
        if ($tokenId) {
            PersonalAccessToken::whereKey($tokenId)->delete();
        }
        DB::table('boardmoa_tokens')->where('id', $id)->delete();
    }

    /** 이 회원의 모든 앱 로그인을 끊는다 (탈퇴·비밀번호 변경) */
    public static function revokeUser(int $userId, ?int $exceptId = null): void
    {
        $query = DB::table('boardmoa_tokens')->where('user_id', $userId);
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        foreach ($query->pluck('id') as $id) {
            self::revoke((int) $id);
        }
    }

    /** 로그인한 기기 (auth.devices). 해시·IP 는 내보내지 않는다 */
    public static function devices(int $userId): array
    {
        return DB::table('boardmoa_tokens')->where('user_id', $userId)->where('refresh_expires', '>=', time())
            ->orderBy('last_used', 'desc')->orderBy('id', 'desc')
            ->get(['id', 'device_name', 'platform', 'created', 'last_used'])->all();
    }

    /** 이 회원의 기기 줄만 지운다. 지웠으면 true */
    public static function revokeOwn(int $userId, int $id): bool
    {
        if (! DB::table('boardmoa_tokens')->where('id', $id)->where('user_id', $userId)->exists()) {
            return false;
        }
        self::revoke($id);

        return true;
    }
}
