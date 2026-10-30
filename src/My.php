<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Models\User;

/**
 * 내 활동·알림·회원(이름 메뉴)·내 정보 수정. 모두 엔진 API(웹 마이페이지가 부르는 것)를 그대로 실행한다.
 */
class My
{
    const ME = '/api/me';

    private static function page(): int
    {
        return max(1, Api::intParam('page', 1));
    }

    // ---- 내 활동

    public static function menu(): array
    {
        $items = [
            ['key' => 'documents', 'title' => Text::t('내가 쓴 글')],
            ['key' => 'comments', 'title' => Text::t('내가 쓴 댓글')],
            ['key' => 'scraps', 'title' => Text::t('스크랩')],
        ];

        return ['items' => $items];
    }

    /** 내가 쓴 글 (엔진 '내 활동 게시글'의 작성 글) */
    public static function documents(): array
    {
        $data = Engine::data('GET', '/api/modules/sirsoft-board/me/board-activities', ['page' => self::page(), 'activity_type' => 'authored']);
        $docs = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $docs[] = [
                'document_srl' => (int) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'author' => Api::$user ? (string) Api::$user->name : '',
                'author_image' => Api::avatar(Api::$user),
                'author_srl' => Api::$user ? (int) Api::$user->getKey() : 0,
                'is_mine' => true,
                'regdate' => Boards::iso($row['created_at'] ?? null),
                'last_update' => Boards::iso($row['created_at'] ?? null),
                'category_srl' => 0,
                'comment_count' => (int) ($row['comment_count'] ?? 0),
                'read_count' => (int) ($row['view_count'] ?? 0),
                'vote_count' => null,
                'is_notice' => false,
                'is_secret' => ! empty($row['is_secret']),
                'has_files' => false,
                'thumbnail' => null,
                'summary' => mb_substr(trim((string) ($row['content_plain'] ?? '')), 0, 200),
                'mid' => (string) ($row['board_slug'] ?? ''),
                'board_title' => (string) ($row['board_name'] ?? ''),
            ];
        }

        return ['documents' => $docs, 'page' => (int) ($data['current_page'] ?? 1), 'total_pages' => max(1, (int) ($data['last_page'] ?? 1)), 'total_count' => (int) ($data['total'] ?? count($docs))];
    }

    public static function comments(): array
    {
        $data = Engine::data('GET', '/api/modules/sirsoft-board/me/my-comments', ['page' => self::page()]);
        $items = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $items[] = [
                'comment_srl' => (int) $row['id'],
                'document_srl' => (int) ($row['post_id_val'] ?? $row['post_id'] ?? 0),
                'content' => mb_substr(trim(strip_tags((string) ($row['content'] ?? ''))), 0, 200),
                'regdate' => Boards::iso($row['created_at'] ?? null),
                'is_secret' => ! empty($row['is_secret']),
                'document_title' => (string) ($row['post_title'] ?? ''),
                'mid' => (string) ($row['board_slug'] ?? ''),
                'board_title' => (string) ($row['board_name'] ?? ''),
            ];
        }

        return ['comments' => $items, 'page' => (int) ($data['current_page'] ?? 1), 'total_pages' => max(1, (int) ($data['last_page'] ?? 1)), 'total_count' => (int) ($data['total'] ?? count($items))];
    }

    public static function scraps(): array
    {
        $data = Engine::data('GET', '/api/modules/sirsoft-board/me/scraps', ['page' => self::page()]);
        $docs = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $post = (array) ($row['post'] ?? []);
            $board = (array) ($row['board'] ?? $post['board'] ?? []);
            $docs[] = [
                'document_srl' => (int) ($row['post_id'] ?? $post['id'] ?? 0),
                'title' => (string) ($post['title'] ?? $row['title'] ?? ''),
                'author' => (string) ($post['author']['name'] ?? $row['author_name'] ?? ''),
                'scrapped_at' => Boards::iso($row['created_at'] ?? null),
                'mid' => (string) ($board['slug'] ?? $row['board_slug'] ?? ''),
                'board_title' => (string) ($board['name'] ?? $row['board_name'] ?? ''),
                'exists' => $post !== [] || ! empty($row['post_id']),
                'scrap_srl' => (int) ($row['id'] ?? 0),
            ];
        }
        $pagination = (array) ($data['pagination'] ?? []);

        return ['documents' => $docs, 'folders' => [], 'folder_srl' => null, 'page' => (int) ($pagination['current_page'] ?? 1),
            'total_pages' => max(1, (int) ($pagination['last_page'] ?? 1)), 'total_count' => (int) ($pagination['total'] ?? count($docs))];
    }

    /** 스크랩 빼기: 그 글의 내 스크랩 줄을 찾아 엔진 '스크랩 삭제'로 */
    public static function scrapDelete(): array
    {
        $postId = Api::intParam('document_srl');
        $scrapId = 0;
        if (class_exists(\Modules\Sirsoft\Board\Models\PostScrap::class)) {
            $scrapId = (int) \Modules\Sirsoft\Board\Models\PostScrap::where('user_id', Api::$user->getKey())->where('post_id', $postId)->value('id');
        }
        if ($scrapId <= 0) {
            throw new ApiError(404, 'not_found', '없는 페이지입니다.');
        }
        Engine::data('DELETE', '/api/modules/sirsoft-board/me/scraps/'.$scrapId);

        return ['deleted' => true];
    }

    // ---- 알림 (엔진 알림 센터: 댓글·답글 등은 엔진이 만든다)

    public static function notifyCount(): array
    {
        $data = Engine::data('GET', '/api/user/notifications/unread-count');

        return ['enabled' => true, 'unread' => (int) ($data['unread_count'] ?? 0)];
    }

    public static function notifyList(): array
    {
        $data = Engine::data('GET', '/api/user/notifications', ['page' => self::page(), 'per_page' => 20]);
        $items = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $text = trim((string) ($row['subject'] ?? ''));
            $body = trim(strip_tags((string) ($row['body'] ?? '')));
            $items[] = [
                'notify' => (string) $row['id'],
                'text' => $text !== '' ? $text : $body,
                'url' => Boards::absolute(is_string($row['url'] ?? null) ? $row['url'] : null) ?? Boards::base().'/',
                'regdate' => Boards::iso($row['created_at'] ?? null),
                'readed' => ! empty($row['read_at']),
                'nick_name' => '',
                'profile_image' => null,
            ];
        }
        $pagination = (array) ($data['pagination'] ?? []);

        return ['notifications' => $items, 'page' => (int) ($pagination['current_page'] ?? 1),
            'total_pages' => max(1, (int) ($pagination['last_page'] ?? 1)), 'unread' => self::notifyCount()['unread']];
    }

    public static function notifyRead(): array
    {
        $id = (string) Api::param('notify', '');
        if ($id === '' || ! preg_match('/^[A-Za-z0-9\-]{1,64}$/', $id)) {
            throw new ApiError(404, 'not_found', '없는 페이지입니다.');
        }
        Engine::data('PATCH', '/api/user/notifications/'.$id.'/read');

        return ['read' => true, 'unread' => self::notifyCount()['unread']];
    }

    public static function notifyReadAll(): array
    {
        Engine::data('POST', '/api/user/notifications/read-all');

        return ['read' => true, 'unread' => 0];
    }

    // ---- 이름 메뉴 · 회원 정보

    private static function memberParam(): User
    {
        $user = User::find(Api::intParam('member_srl'));
        if (! $user) {
            throw new ApiError(404, 'member_not_found', '없는 회원입니다.');
        }

        return $user;
    }

    public static function memberMenu(): array
    {
        $user = self::memberParam();
        $actions = [];
        if (Api::$user) {
            $actions[] = ['type' => 'info', 'label' => Text::t('회원 정보 보기')];
        }
        $mid = trim((string) Api::param('mid', ''));
        if ($mid !== '' && Boards::can($mid, 'posts.read')) {
            $actions[] = ['type' => 'documents', 'label' => Text::t('작성 글 보기'), 'mid' => $mid,
                'search' => ['search_target' => 'nick_name', 'search_keyword' => (string) $user->name]];
        }

        return ['member_srl' => (int) $user->getKey(), 'actions' => $actions];
    }

    /** 회원 정보: 웹 공개 프로필(/users/:uuid)과 같은 엔진 응답 */
    public static function memberInfo(): array
    {
        $user = self::memberParam();
        $data = Engine::data('GET', '/api/users/'.$user->uuid.'/profile');
        $fields = [];
        foreach (['bio' => '자기소개', 'homepage' => '홈페이지'] as $key => $title) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '') {
                $fields[] = ['name' => $key, 'title' => Text::t($title), 'value' => $value];
            }
        }

        return [
            'member_srl' => (int) $user->getKey(),
            'nick_name' => (string) ($data['name'] ?? $user->name),
            'profile_image' => Boards::absolute($data['avatar'] ?? null),
            'regdate' => Boards::iso($data['created_at'] ?? null),
            'fields' => $fields,
        ];
    }

    // ---- 가입 (엔진 /api/auth/register: 웹 가입 화면과 같은 검사) · 탈퇴 (웹에서)

    /** 가입 양식: 웹 가입 화면(Basic 템플릿)과 같은 칸과 약관 두 개(이용약관·개인정보 처리방침 페이지) */
    public static function signupForm(): array
    {
        $min = (int) g7_core_settings('security.password_min_length', 8);
        $special = (bool) g7_core_settings('security.require_password_special_char', false);
        $hint = $special ? Text::t('비밀번호는 {0}자 이상, 특수문자를 넣어 정해 주세요.', max(1, $min)) : Text::t('비밀번호는 {0}자 이상으로 정해 주세요.', max(1, $min));
        $agreements = [];
        foreach ([['terms', '이용약관'], ['privacy', '개인정보 처리방침']] as $i => [$slug, $title]) {
            $page = self::pageText($slug);
            $agreements[] = ['index' => $i, 'title' => $page['title'] !== '' ? $page['title'] : Text::t($title), 'content' => $page['content'], 'required' => true];
        }
        $field = fn (string $name, string $title, string $type) => ['name' => $name, 'title' => Text::t($title), 'type' => $type, 'required' => false, 'value' => '', 'options' => [], 'editable' => true];

        return [
            'enabled' => true,
            'web_url' => Boards::base().'/register',
            'basic' => [
                ['name' => 'email_address', 'title' => Text::t('이메일'), 'required' => true],
                ['name' => 'nick_name', 'title' => Text::t('이름'), 'required' => true],
            ],
            'agreements' => $agreements,
            'password_hint' => $hint,
            'needs_confirm' => false,
            'fields' => [$field('nickname', '닉네임', 'text'), $field('mobile', '휴대폰 번호', 'tel'), $field('phone', '전화번호', 'tel')],
        ];
    }

    /** 약관 페이지(sirsoft-page)의 제목과 글자만의 내용 — 웹 가입 화면의 약관 창과 같은 페이지 */
    private static function pageText(string $slug): array
    {
        [$status, $json] = Engine::call('GET', '/api/modules/sirsoft-page/pages/'.$slug);
        if ($status !== 200 || ! is_array($json)) {
            return ['title' => '', 'content' => ''];
        }
        $page = (array) ($json['data'] ?? []);
        $title = $page['title'] ?? '';
        $content = $page['content'] ?? '';
        if (is_array($title)) {
            $title = $title[app()->getLocale()] ?? reset($title);
        }
        if (is_array($content)) {
            $content = $content[app()->getLocale()] ?? reset($content);
        }
        $text = preg_replace('#<(br|/p|/h[1-6]|/li|/div|/tr)\b[^>]*>#i', "\n", (string) $content) ?? '';
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+\n/", "\n", $text) ?? '') ?? '');

        return ['title' => (string) $title, 'content' => $text];
    }

    /** POST signup: 엔진 가입(중복 메일·비밀번호 규칙·약관 동의 기록·본인인증 정책·환영 알림은 엔진) → 이 기기에 로그인 */
    public static function signup(): array
    {
        $accepted = array_map('intval', (array) Api::$request->input('agreements', []));
        $password = (string) Api::param('password', '');
        $fields = (array) Api::$request->input('fields', []);
        $body = [
            'email' => trim((string) Api::param('email_address', '')),
            'name' => trim((string) Api::param('nick_name', '')),
            'password' => $password,
            'password_confirmation' => $password,
            'agree_terms' => in_array(0, $accepted, true),
            'agree_privacy' => in_array(1, $accepted, true),
            'language' => Text::engineLocale(),
        ];
        foreach (['nickname', 'mobile', 'phone'] as $name) {
            $value = $fields[$name] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $body[$name] = trim($value);
            }
        }
        [$status, $json] = Engine::call('POST', '/api/auth/register', $body);
        if ($status < 200 || $status >= 300) {
            $error = Engine::error($status, $json);
            throw new ApiError($error->status === 403 ? 403 : 400, $error->status === 403 ? $error->codeName : 'signup_failed', $error->getMessage(), [], $error->raw);
        }
        $data = (array) ($json['data'] ?? []);
        $uuid = (string) ($data['user']['uuid'] ?? '');
        $user = $uuid !== '' ? User::where('uuid', $uuid)->first() : null;
        if (! $user || ! in_array((string) ($user->status instanceof \BackedEnum ? $user->status->value : $user->status), ['', 'active'], true)) {
            // 가입 뒤 본인인증·승인을 기다리는 사이트(엔진 정책): 바로 로그인하지 않는다
            return ['needs_confirm' => false, 'logged_in' => false];
        }
        [$tokens] = Store::issue($user, (string) ($data['token'] ?? ''), (string) Api::param('device_name', ''), (string) Api::param('platform', ''), (string) Api::$request->ip());

        return ['needs_confirm' => false, 'logged_in' => true, 'tokens' => $tokens, 'member' => Api::profile($user)];
    }

    public static function webOnly(): array
    {
        throw new ApiError(403, 'not_permitted', '웹사이트에서 해 주세요.');
    }

    // ---- 내 정보 수정 (엔진 /api/me: 웹 마이페이지 '정보 수정'과 같은 검사)

    /** 앱에서 고칠 수 있는 항목 (엔진 프로필 수정 양식의 칸) */
    private const FIELDS = [
        'homepage' => ['홈페이지', 'url'],
        'mobile' => ['휴대폰 번호', 'tel'],
        'phone' => ['전화번호', 'tel'],
        'signature' => ['서명', 'textarea'],
        'bio' => ['자기소개', 'textarea'],
    ];

    public static function form(): array
    {
        $me = Engine::data('GET', self::ME);
        $fields = [];
        foreach (self::FIELDS as $name => [$title, $type]) {
            $fields[] = ['name' => $name, 'title' => Text::t($title), 'type' => $type, 'required' => false,
                'value' => (string) ($me[$name] ?? ''), 'options' => [], 'editable' => true];
        }

        return [
            'nick_name' => (string) ($me['name'] ?? ''),
            'email_address' => (string) ($me['email'] ?? ''),
            'profile_image' => Boards::absolute($me['avatar'] ?? null),
            'can_change_nickname' => true,
            'can_change_email' => false,
            'profile_image_enabled' => true,
            'profile_image_deletable' => true,
            'images' => [],
            'nickname_notice' => null,
            'fields' => $fields,
            'web_notice' => null,
            'web_url' => Boards::base().'/mypage/profile/edit',
        ];
    }

    /** 지금 비밀번호 확인 (엔진 /api/me/verify-password, 회원마다 10분에 10번) */
    private static function verifyCurrent(): void
    {
        $password = (string) Api::param('current_password', '');
        Throttle::check('me', (int) Api::$user->getKey());
        [$status] = Engine::call('POST', self::ME.'/verify-password', ['password' => $password]);
        if ($status < 200 || $status >= 300) {
            Throttle::fail('me', (int) Api::$user->getKey());
            throw new ApiError(400, 'invalid_password', '비밀번호가 맞지 않습니다.');
        }
    }

    public static function update(): array
    {
        self::verifyCurrent();
        $me = Engine::data('GET', self::ME);
        $body = ['name' => (string) ($me['name'] ?? ''), 'email' => (string) ($me['email'] ?? '')];
        foreach (array_keys(self::FIELDS) as $name) {
            $body[$name] = $me[$name] ?? null;
        }
        $nick = Api::param('nick_name');
        if ($nick !== null && trim($nick) !== '') {
            $body['name'] = trim($nick);
        }
        $fields = Api::$request->input('fields');
        if (is_array($fields)) {
            foreach ($fields as $name => $value) {
                if (isset(self::FIELDS[$name]) && (is_string($value) || $value === null)) {
                    $body[$name] = $value === '' ? null : $value;
                }
            }
        }
        [$status, $json] = Engine::call('PUT', self::ME, $body);
        if ($status < 200 || $status >= 300) {
            $error = Engine::error($status, $json);
            throw new ApiError(400, 'update_failed', $error->getMessage(), [], $error->raw);
        }
        Api::$user->refresh();

        return Api::profile(Api::$user);
    }

    public static function password(): array
    {
        $new = (string) Api::param('new_password', '');
        [$status, $json] = Engine::call('PUT', self::ME.'/password', ['current_password' => (string) Api::param('current_password', ''), 'password' => $new, 'password_confirmation' => $new]);
        if ($status < 200 || $status >= 300) {
            $errors = is_array($json) ? (array) ($json['errors'] ?? []) : [];
            $error = Engine::error($status, $json);
            throw new ApiError(400, 'invalid_password', $error->getMessage(), [], $error->raw || isset($errors['password']));
        }
        // 비밀번호가 바뀌면 이 회원의 다른 앱 로그인을 끊고, 이 기기는 새 비밀번호로 다시 서명한다
        Api::$user->refresh();
        $deviceId = (int) Api::$token->id;
        Store::revokeUser((int) Api::$user->getKey(), $deviceId);

        return ['tokens' => self::reissue($deviceId)];
    }

    /** 이 기기의 새 토큰 (비밀번호를 바꾼 뒤) */
    private static function reissue(int $deviceId): array
    {
        [$tokens] = Store::issue(Api::$user, null, (string) (Api::$token->device_name ?? ''), (string) (Api::$token->platform ?? ''), (string) Api::$request->ip());
        Store::revoke($deviceId);

        return $tokens;
    }

    /** 프로필 사진: 엔진 /api/me/avatar (올리기·지우기, 형식·크기는 엔진) */
    public static function image(): array
    {
        $kind = (string) Api::param('kind', '');
        if ($kind !== '' && $kind !== 'profile') {
            throw new ApiError(403, 'not_permitted', '권한이 없습니다.');
        }
        if (Api::boolParam('delete')) {
            Engine::data('DELETE', self::ME.'/avatar');
        } else {
            $file = Api::$request->file('image');
            if (! $file) {
                throw new ApiError(400, 'invalid_request', '사진을 골라 주세요.');
            }
            [$status, $json] = Engine::call('POST', self::ME.'/avatar', [], ['avatar' => $file]);
            if ($status < 200 || $status >= 300) {
                throw Engine::error($status, $json);
            }
        }
        Api::$user->refresh();

        return Api::profile(Api::$user);
    }
}
