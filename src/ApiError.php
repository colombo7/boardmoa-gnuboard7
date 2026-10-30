<?php

namespace Plugins\Easyapplabs\Boardmoa;

/** 앱에 돌려줄 오류 (HTTP 상태, 오류 코드, 사용자에게 보일 문장) */
class ApiError extends \Exception
{
    /**
     * @param  array<string, mixed>  $extra  error 에 더할 값 (code·message 는 덮지 않는다)
     * @param  bool  $raw  true 면 엔진이 만든 문구라 번역하지 않는다
     */
    public function __construct(
        public int $status,
        public string $codeName,
        string $message,
        public array $extra = [],
        public bool $raw = false,
    ) {
        parent::__construct($message);
    }
}
