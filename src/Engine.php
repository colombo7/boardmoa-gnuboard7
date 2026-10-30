<?php

namespace Plugins\Easyapplabs\Boardmoa;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * 그누보드7 의 공개 API 를 이 요청 안에서 그대로 실행한다.
 * 웹 화면(SPA)이 부르는 것과 같은 라우트·미들웨어(permission·optional.sanctum·IDV·도배 방지)·컨트롤러를 거치므로
 * 권한·비밀글·포인트·알림 판단은 엔진이 한다. 앱의 회원은 앱 토큰(= 엔진 Sanctum 토큰)을 Bearer 로 그대로 넘긴다.
 */
class Engine
{
    /** 이번 요청에서 엔진에 넘길 Bearer (비회원이면 '') */
    public static string $bearer = '';

    /** 비밀번호로 연 비밀글의 열람 토큰(X-Board-Secret-View-Token) */
    public static string $secretViewToken = '';

    /** 마지막 엔진 응답의 시간대 (응답 시각을 ISO 로 바꿀 때 쓴다) */
    public static string $timezone = 'UTC';

    /**
     * 엔진 API 실행. $path 는 '/api/...' 꼴.
     *
     * @param  array<string, mixed>  $params  GET 이면 쿼리, 그 밖은 본문(JSON 또는 multipart)
     * @param  array<string, UploadedFile|UploadedFile[]>  $files
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: array<string, mixed>|null, 2: Response} [HTTP 상태, JSON 본문(아니면 null), 응답]
     */
    public static function call(string $method, string $path, array $params = [], array $files = [], array $headers = []): array
    {
        $original = Api::$request;
        $method = strtoupper($method);
        $server = $original ? $original->server->all() : [];
        // 원래 요청의 접속 정보(IP·프록시 머리말)는 그대로 둔다: 엔진의 IP 별 제한·기록이 웹과 같게
        foreach (array_keys($server) as $key) {
            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'HTTP_CONTENT_TYPE', 'HTTP_CONTENT_LENGTH', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION',
                'HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_X_TIMEZONE', 'HTTP_COOKIE', 'PATH_INFO', 'ORIG_PATH_INFO', 'QUERY_STRING', 'REQUEST_URI'], true)
                || str_starts_with($key, 'HTTP_X_BOARD_')) {
                unset($server[$key]);
            }
        }
        $server['HTTP_ACCEPT'] = 'application/json';
        $server['HTTP_ACCEPT_LANGUAGE'] = Text::engineLocale();
        if (self::$bearer !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.self::$bearer;
        }
        if (self::$secretViewToken !== '') {
            $server['HTTP_X_BOARD_SECRET_VIEW_TOKEN'] = self::$secretViewToken;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $content = null;
        $body = $params;
        if ($method !== 'GET' && $method !== 'HEAD' && ! $files) {
            // 엔진 FormRequest 는 JSON 본문과 같은 값을 받는다 (불리언·배열 그대로)
            $server['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($params, JSON_UNESCAPED_UNICODE);
            $body = [];
        }
        $sub = Request::create($path, $method, $method === 'GET' || $method === 'HEAD' ? $params : $body, [], $files, $server, $content);
        if ($content !== null) {
            $sub->setJson(new \Symfony\Component\HttpFoundation\InputBag($params));
        }

        $app = app();
        $kernel = $app->make(Kernel::class);
        // 앞의 엔진 호출이 남긴 인증 상태를 지우고, 이번 Bearer 로 엔진이 다시 판단하게 한다
        Auth::forgetGuards();
        try {
            $response = $kernel->handle($sub);
        } finally {
            if (app()->bound('user_timezone')) {
                self::$timezone = (string) app('user_timezone');
            }
            $app->instance('request', $original);
            Facade::clearResolvedInstance('request');
            Auth::forgetGuards();
            Api::restoreUser();
        }
        $status = $response->getStatusCode();
        $json = null;
        $type = (string) $response->headers->get('Content-Type', '');
        if (str_contains($type, 'json')) {
            $decoded = json_decode((string) $response->getContent(), true);
            $json = is_array($decoded) ? $decoded : null;
        }

        return [$status, $json, $response];
    }

    /**
     * 엔진 호출이 성공해야 하는 곳: 실패면 엔진 문구로 앱 오류를 던진다.
     *
     * @return array<string, mixed> 응답의 data
     */
    public static function data(string $method, string $path, array $params = [], array $files = [], array $headers = []): array
    {
        [$status, $json] = self::call($method, $path, $params, $files, $headers);
        if ($status >= 200 && $status < 300 && is_array($json) && ($json['success'] ?? true) !== false) {
            $data = $json['data'] ?? [];

            return is_array($data) ? $data : ['value' => $data];
        }
        throw self::error($status, $json);
    }

    /** 엔진 실패 응답 → 앱 오류 (코드는 앱 규약, 문구는 엔진 것) */
    public static function error(int $status, ?array $json, ?string $notFound = null): ApiError
    {
        $message = is_array($json) ? (string) ($json['message'] ?? '') : '';
        $errors = is_array($json) ? ($json['errors'] ?? null) : null;
        if (is_array($errors)) {
            // 검증 오류: 첫 항목의 첫 문구 (웹 양식이 칸 아래 보이는 문구)
            foreach ($errors as $list) {
                $first = is_array($list) ? reset($list) : $list;
                if (is_string($first) && $first !== '') {
                    $message = $first;
                    break;
                }
            }
        }
        $raw = $message !== '';
        if ($status === 401) {
            return new ApiError(401, 'login_required', $raw ? $message : '로그인이 필요합니다.', [], $raw);
        }
        if ($status === 403) {
            return new ApiError(403, 'not_permitted', $raw ? $message : '권한이 없습니다.', [], $raw);
        }
        if ($status === 404) {
            return new ApiError(404, $notFound ?? 'not_found', $raw ? $message : '없는 페이지입니다.', [], $raw);
        }
        if ($status === 422 || $status === 400) {
            return new ApiError(400, 'invalid_request', $raw ? $message : '처리하지 못했습니다.', [], $raw);
        }
        if ($status === 423 || $status === 429) {
            return new ApiError(429, 'too_many_attempts', $raw ? $message : '잠시 뒤에 다시 해 주세요.', [], $raw);
        }
        if ($status === 428) {
            // 엔진 본인인증(IDV) 요구: 앱에서 할 수 없어 웹으로 안내
            return new ApiError(403, 'not_permitted', '웹사이트에서 본인 확인을 마친 뒤 다시 해 주세요.');
        }

        return new ApiError($status >= 500 ? 500 : 400, $status >= 500 ? 'server_error' : 'invalid_request', $raw ? $message : '처리하지 못했습니다.', [], $raw);
    }
}
