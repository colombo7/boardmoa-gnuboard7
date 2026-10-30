<?php

namespace Plugins\Easyapplabs\Boardmoa;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * 쓰기: 글·댓글·추천·스크랩·신고. 모두 게시판 모듈의 공개 API(웹 화면이 부르는 것)를 그대로 실행한다 —
 * 쓰기 권한, 비회원 비밀번호, 금지어, 도배 간격, 글자 수, 첨부 한도, 포인트, 알림은 엔진이 판단한다.
 */
class Write
{
    private static function postPath(string $slug, ?int $id = null): string
    {
        return Boards::BOARD_API.'/'.rawurlencode($slug).'/posts'.($id ? '/'.$id : '');
    }

    /** 엔진 쓰기 실패 → 앱 오류 (권한 없음은 로그인 여부에 따라 401/403) */
    private static function fail(int $status, ?array $json, string $notFound = 'document_not_found'): ApiError
    {
        if (($status === 401 || $status === 403) && Api::$user === null && ! Api::param('password')) {
            return new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }

        return Engine::error($status, $json, $notFound);
    }

    /** 앱 본문 → 엔진 HTML. 앱 꾸밈 편집기(content_format=html)는 그대로(엔진이 거른다), 글자는 이스케이프 + 줄바꿈 */
    private static function contentHtml(): string
    {
        $content = (string) Api::param('content', '');
        if (Api::param('content_format') === 'html') {
            return $content;
        }
        $lines = preg_split('/\r\n|\r|\n/', trim($content)) ?: [];

        return implode('', array_map(fn ($line) => '<p>'.($line === '' ? '&nbsp;' : htmlspecialchars($line, ENT_QUOTES, 'UTF-8')).'</p>', $lines));
    }

    /** @return UploadedFile[] */
    private static function uploaded(string $name): array
    {
        $files = Api::$request->file($name);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        return array_values(array_filter((array) $files, fn ($f) => $f instanceof UploadedFile && $f->isValid()));
    }

    /**
     * 사진·파일을 웹 글쓰기처럼 먼저 첨부로 올린다(엔진 첨부 API: 업로드 권한·확장자·크기·개수 검사).
     * 사진은 본문에 넣을 미리보기 주소를 돌려준다.
     *
     * @return array{0: string, 1: string[]} [temp_key 또는 '', 사진 주소들]
     */
    private static function uploadAttachments(string $slug, ?int $postId): array
    {
        $photos = self::uploaded('photos');
        $files = self::uploaded('files');
        if (! $photos && ! $files) {
            return ['', []];
        }
        $tempKey = (string) Str::uuid();
        $urls = [];
        foreach (array_merge(array_map(fn ($f) => [$f, true], $photos), array_map(fn ($f) => [$f, false], $files)) as [$file, $isPhoto]) {
            $params = ['collection' => 'attachments'];
            if ($postId) {
                $params['post_id'] = $postId;
            } else {
                $params['temp_key'] = $tempKey;
            }
            [$status, $json] = Engine::call('POST', Boards::BOARD_API.'/'.rawurlencode($slug).'/attachments', $params, ['file' => $file]);
            if ($status === 401 || $status === 403) {
                throw new ApiError(403, 'upload_not_permitted', is_array($json) && ($json['message'] ?? '') !== '' ? (string) $json['message'] : '첨부할 권한이 없습니다.', [], is_array($json) && ($json['message'] ?? '') !== '');
            }
            if ($status < 200 || $status >= 300) {
                $error = Engine::error($status, $json);
                throw new ApiError(400, 'upload_failed', $error->getMessage(), [], $error->raw);
            }
            $hash = (string) ($json['data']['data']['hash'] ?? $json['data']['hash'] ?? '');
            if ($isPhoto && $hash !== '') {
                $urls[] = Boards::BOARD_API.'/'.rawurlencode($slug).'/attachment/'.$hash.'/preview';
            }
        }

        return [$postId ? '' : $tempKey, $urls];
    }

    /** 사진 태그 (앱 photo_width: full·half·original) */
    private static function photoHtml(array $urls): string
    {
        $width = (string) Api::param('photo_width', 'full');
        $style = $width === 'half' ? ' style="width:50%"' : ($width === 'original' ? '' : ' style="width:100%"');
        $html = '';
        foreach ($urls as $url) {
            $html .= '<p><img src="'.htmlspecialchars($url, ENT_QUOTES).'" alt=""'.$style.'></p>';
        }

        return $html;
    }

    private static function categoryName(string $slug): ?string
    {
        $srl = Api::intParam('category_srl');
        if ($srl <= 0) {
            return null;
        }
        $names = Boards::categoryNames(['categories' => Boards::boardModel($slug)->categories ?? []]);

        return $names[$srl - 1] ?? null;
    }

    /** 비회원 글·댓글 이름·비밀번호 (토큰이 없을 때) */
    private static function guestFields(array &$body): void
    {
        if (Api::$user !== null) {
            return;
        }
        $name = trim((string) Api::param('nick_name', ''));
        $password = (string) Api::param('password', '');
        if ($name === '' || $password === '') {
            if ($name === '' && $password === '') {
                throw new ApiError(401, 'login_required', '로그인이 필요합니다.');
            }
            throw new ApiError(400, 'guest_info_required', '이름과 비밀번호를 입력하세요.');
        }
        $body['author_name'] = $name;
        $body['password'] = $password;
    }

    public static function write(): array
    {
        $slug = Boards::slugParam();
        Boards::boardModel($slug);
        $body = [
            'title' => trim((string) Api::param('title', '')),
            'content' => self::contentHtml(),
            'content_mode' => 'html',
        ];
        if (Api::boolParam('is_secret')) {
            $body['is_secret'] = true;
        }
        if (($category = self::categoryName($slug)) !== null) {
            $body['category'] = $category;
        }
        if (($parent = Api::intParam('parent_srl')) > 0) {
            $body['parent_id'] = $parent;
        }
        self::guestFields($body);
        [$tempKey, $urls] = self::uploadAttachments($slug, null);
        if ($tempKey !== '') {
            $body['temp_key'] = $tempKey;
        }
        $body['content'] .= self::photoHtml($urls);
        [$status, $json] = Engine::call('POST', self::postPath($slug), $body);
        if ($status < 200 || $status >= 300) {
            throw self::fail($status, $json, 'board_not_found');
        }

        return ['document_srl' => (int) ($json['data']['id'] ?? 0)];
    }

    /** 비회원 비밀글을 비밀번호로 연다: 엔진의 비밀번호 확인 → 열람 토큰을 이번 요청의 엔진 호출에 붙인다 */
    public static function unlockSecret(string $slug, int $id, string $password): void
    {
        Throttle::check($slug, $id);
        [$status, $json] = Engine::call('POST', self::postPath($slug, $id).'/verify-password', ['password' => $password]);
        if ($status >= 200 && $status < 300 && is_array($json)) {
            $token = (string) ($json['secret_view_token'] ?? $json['data']['secret_view_token'] ?? '');
            if ($token !== '') {
                Engine::$secretViewToken = $token;
            }

            return;
        }
        if ($status === 404) {
            throw new ApiError(404, 'document_not_found', '없는 글입니다.');
        }
        if ($status === 429) {
            throw Engine::error($status, $json);
        }
        Throttle::fail($slug, $id);
        throw new ApiError(403, 'wrong_password', '비밀번호가 맞지 않습니다.');
    }

    /** 비회원 글 비밀번호가 맞는가 (엔진의 고치기 전 확인) */
    private static function checkPostPassword(string $slug, int $id, string $password): void
    {
        Throttle::check($slug, $id);
        [$status, $json] = Engine::call('POST', self::postPath($slug, $id).'/verify-password-for-modify', ['password' => $password]);
        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status === 404) {
            throw new ApiError(404, 'document_not_found', '없는 글입니다.');
        }
        if ($status === 401 || $status === 403 || $status === 422 || $status === 400) {
            Throttle::fail($slug, $id);
            throw new ApiError(403, 'wrong_password', '비밀번호가 맞지 않습니다.');
        }
        throw Engine::error($status, $json);
    }

    public static function source(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $model = Boards::boardModel($slug);
        $password = (string) Api::param('password', '');
        if ($password !== '') {
            self::checkPostPassword($slug, $id, $password);
        }
        $post = Boards::enginePost($slug, $id);
        $manage = ! empty($post['abilities']['can_manage']);
        $mine = ! empty($post['is_author']) && empty($post['is_guest_post']);
        if (! $manage && ! $mine && $password === '') {
            throw Api::$user ? new ApiError(403, 'not_permitted', '권한이 없습니다.') : new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }
        $categories = Boards::categoryNames(['categories' => $model->categories ?? []]);
        $category = $post['category'] ?? null;
        $i = is_string($category) ? array_search($category, $categories, true) : false;
        $content = (string) ($post['content'] ?? '');
        if (($post['content_mode'] ?? 'html') !== 'html') {
            $content = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        }
        $abilities = (array) ($post['abilities'] ?? []);
        $board = (array) ($post['board'] ?? []);
        $result = [
            'document_srl' => $id,
            'title' => (string) ($post['title'] ?? ''),
            'content' => $content,
            'base_url' => Boards::base().'/',
            'category_srl' => $i === false ? 0 : $i + 1,
            'extra_keys' => [],
            'extra_values' => (object) [],
            'upload' => Boards::upload($model, ! empty($abilities['can_upload'])),
            'board' => Boards::boardInfo($slug, $model, ['name' => $board['name'] ?? $slug, 'type' => $board['type'] ?? null,
                'settings' => ['use_comment' => $board['use_comment'] ?? true, 'show_view_count' => $board['show_view_count'] ?? true, 'use_reaction' => $board['use_reaction'] ?? false]],
                $abilities, $categories),
        ];
        if ($manage) {
            $result['manage'] = ['is_notice' => ! empty($post['is_notice']), 'title_bold' => false, 'title_color' => ''];
        }

        return $result;
    }

    public static function update(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        Boards::boardModel($slug);
        $password = (string) Api::param('password', '');
        if ($password !== '' && Api::$user === null) {
            self::checkPostPassword($slug, $id, $password);
        }
        $body = [
            'title' => trim((string) Api::param('title', '')),
            'content' => (string) Api::param('content', ''),
            'content_mode' => 'html',
        ];
        if (Api::$request->has('category_srl')) {
            $body['category'] = self::categoryName($slug);
        }
        $current = Boards::enginePost($slug, $id);
        if (! empty($current['abilities']['can_manage']) && Api::$request->has('is_notice')) {
            $body['is_notice'] = Api::boolParam('is_notice');
        }
        if ($password !== '') {
            $body['password'] = $password;
        }
        [, $urls] = self::uploadAttachments($slug, $id);
        $body['content'] .= self::photoHtml($urls);
        [$status, $json] = Engine::call('PUT', self::postPath($slug, $id), $body);
        if ($status < 200 || $status >= 300) {
            throw self::fail($status, $json);
        }

        return ['document_srl' => $id];
    }

    public static function delete(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $password = (string) Api::param('password', '');
        if ($password !== '' && Api::$user === null) {
            self::checkPostPassword($slug, $id, $password);
        }
        [$status, $json] = Engine::call('DELETE', self::postPath($slug, $id), $password !== '' ? ['password' => $password] : []);
        if ($status < 200 || $status >= 300) {
            throw self::fail($status, $json);
        }

        return ['deleted' => true];
    }

    // ---- 댓글

    private static function commentPath(string $slug, int $postId, ?int $commentId = null): string
    {
        return self::postPath($slug, $postId).'/comments'.($commentId ? '/'.$commentId : '');
    }

    /** 댓글이 속한 글 번호 (앱은 comment_srl 만 보내기도 한다) */
    private static function commentPost(int $commentId): int
    {
        $id = Api::intParam('document_srl');
        if ($id > 0) {
            return $id;
        }
        $postId = class_exists(\Modules\Sirsoft\Board\Models\Comment::class)
            ? \Modules\Sirsoft\Board\Models\Comment::whereKey($commentId)->value('post_id') : null;
        if (! $postId) {
            throw new ApiError(404, 'comment_not_found', '없는 댓글입니다.');
        }

        return (int) $postId;
    }

    private static function plainContent(): string
    {
        return trim((string) Api::param('content', ''));
    }

    public static function commentWrite(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $body = ['content' => self::plainContent()];
        if (($parent = Api::intParam('parent_srl')) > 0) {
            $body['parent_id'] = $parent;
        }
        if (Api::boolParam('is_secret')) {
            $body['is_secret'] = true;
        }
        self::guestFields($body);
        $docPassword = (string) Api::param('document_password', '');
        if ($docPassword !== '') {
            self::unlockSecret($slug, $id, $docPassword);
        }
        [$status, $json] = Engine::call('POST', self::commentPath($slug, $id), $body);
        if ($status < 200 || $status >= 300) {
            throw self::fail($status, $json);
        }

        return ['comment_srl' => (int) ($json['data']['id'] ?? 0), 'document_srl' => $id, 'pending' => false];
    }

    public static function commentUpdate(): array
    {
        $slug = Boards::slugParam();
        $commentId = Api::intParam('comment_srl');
        $id = self::commentPost($commentId);
        $body = ['content' => self::plainContent()];
        $password = (string) Api::param('password', '');
        if ($password !== '') {
            $body['password'] = $password;
        }
        [$status, $json] = Engine::call('PUT', self::commentPath($slug, $id, $commentId), $body);
        if ($status < 200 || $status >= 300) {
            throw self::commentFail($status, $json, $password);
        }

        return ['comment_srl' => $commentId, 'document_srl' => $id];
    }

    public static function commentDelete(): array
    {
        $slug = Boards::slugParam();
        $commentId = Api::intParam('comment_srl');
        $id = self::commentPost($commentId);
        $password = (string) Api::param('password', '');
        [$status, $json] = Engine::call('DELETE', self::commentPath($slug, $id, $commentId), $password !== '' ? ['password' => $password] : []);
        if ($status < 200 || $status >= 300) {
            throw self::commentFail($status, $json, $password);
        }

        return ['deleted' => true];
    }

    private static function commentFail(int $status, ?array $json, string $password): ApiError
    {
        if ($password !== '' && Api::$user === null && in_array($status, [401, 403], true)) {
            return new ApiError(403, 'wrong_password', '비밀번호가 맞지 않습니다.');
        }

        return self::fail($status, $json, 'comment_not_found');
    }

    // ---- 추천(반응) · 스크랩 · 신고

    /** 게시판 반응 종류 번호 (like·dislike) */
    private static function reactionType(array $post, int $point): int
    {
        $code = $point < 0 ? 'dislike' : 'like';
        foreach ((array) ($post['board']['reaction_type_options'] ?? []) as $t) {
            if (is_array($t) && ($t['code'] ?? '') === $code) {
                return (int) $t['id'];
            }
        }
        throw new ApiError(400, 'vote_failed', '이 게시판에서는 추천할 수 없습니다.');
    }

    public static function vote(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $post = Boards::enginePost($slug, $id);
        $type = self::reactionType($post, Api::intParam('point', 1));
        $mine = $post['my_reaction_type_id'] ?? null;
        $cancel = Api::boolParam('cancel');
        // 엔진은 같은 반응을 다시 누르면 취소한다: 이미 그 반응이면 취소 요청일 때만 보낸다
        if (($mine !== null && (int) $mine === $type) !== $cancel) {
            if ($cancel) {
                return self::documentActions($slug, $id);
            }
            throw new ApiError(400, 'vote_failed', '이미 추천했습니다.');
        }
        [$status, $json] = Engine::call('POST', self::postPath($slug, $id).'/react', ['reaction_type_id' => $type]);
        if ($status < 200 || $status >= 300) {
            $error = Engine::error($status, $json);
            throw $status === 400 || $status === 422 ? new ApiError(400, 'vote_failed', $error->getMessage(), [], $error->raw) : $error;
        }

        return self::documentActions($slug, $id);
    }

    private static function documentActions(string $slug, int $id): array
    {
        Api::$request->merge(['mid' => $slug, 'document_srl' => $id]);
        $doc = Boards::document();

        return $doc['actions'];
    }

    public static function commentVote(): array
    {
        if (Api::$user === null) {
            throw new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }
        $slug = Boards::slugParam();
        $commentId = Api::intParam('comment_srl');
        $id = self::commentPost($commentId);
        $post = Boards::enginePost($slug, $id);
        $type = self::reactionType($post, Api::intParam('point', 1));
        $comment = null;
        foreach ((array) ($post['comments'] ?? []) as $c) {
            if ((int) ($c['id'] ?? 0) === $commentId) {
                $comment = $c;
            }
        }
        $mine = $comment['my_reaction_type_id'] ?? null;
        $cancel = Api::boolParam('cancel');
        if (($mine !== null && (int) $mine === $type) === $cancel) {
            [$status, $json] = Engine::call('POST', Boards::BOARD_API.'/'.rawurlencode($slug).'/comments/'.$commentId.'/react', ['reaction_type_id' => $type]);
            if ($status < 200 || $status >= 300) {
                $error = Engine::error($status, $json);
                throw $status === 400 || $status === 422 ? new ApiError(400, 'vote_failed', $error->getMessage(), [], $error->raw) : $error;
            }
        } elseif (! $cancel) {
            throw new ApiError(400, 'vote_failed', '이미 추천했습니다.');
        }
        Api::$request->merge(['mid' => $slug, 'document_srl' => $id]);
        foreach (Boards::comments()['items'] as $item) {
            if ($item['comment_srl'] === $commentId) {
                return ['comment_srl' => $commentId, 'vote' => $item['vote']];
            }
        }

        return ['comment_srl' => $commentId, 'vote' => null];
    }

    public static function scrap(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $post = Boards::enginePost($slug, $id);
        if (! empty($post['is_scraped'])) {
            return ['scrapped' => true];
        }
        [$status, $json] = Engine::call('POST', self::postPath($slug, $id).'/scrap/toggle');
        if ($status < 200 || $status >= 300) {
            throw Engine::error($status, $json);
        }

        return ['scrapped' => true];
    }

    public static function declare(): array
    {
        $slug = Boards::slugParam();
        $id = Api::intParam('document_srl');
        $reason = trim((string) Api::param('reason', ''));
        [$status, $json] = Engine::call('POST', Boards::BOARD_API.'/'.rawurlencode($slug).'/posts/'.$id.'/reports',
            ['reason_type' => 'other', 'reason_detail' => $reason !== '' ? mb_substr($reason, 0, 1000) : Text::t('앱에서 신고했습니다.')]);
        if ($status < 200 || $status >= 300) {
            throw Engine::error($status, $json);
        }

        return ['declared' => true];
    }
}
