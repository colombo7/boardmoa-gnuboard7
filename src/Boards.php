<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Helpers\PermissionHelper;
use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 사이트·메뉴·게시판·글·댓글 (읽기). 글 목록·글·댓글·첨부는 게시판 모듈의 공개 API 를 그대로 실행해 받는다.
 * mid: 게시판은 slug, 페이지(sirsoft-page)는 'page-<slug>', 첫 화면은 'home-latest'(게시판별 최근 글).
 */
class Boards
{
    const BOARD_API = '/api/modules/sirsoft-board/boards';

    const HOME_MID = 'home-latest';

    // ---- 공통

    /** 사이트 주소 (APP_URL, 끝 / 없음) */
    public static function base(): string
    {
        $url = (string) (g7_core_settings('general.site_url') ?: config('app.url'));

        return rtrim($url, '/');
    }

    /** 사이트 안 주소 → 절대 주소 */
    public static function absolute(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        return self::base().'/'.ltrim($url, '/');
    }

    /** 엔진 응답 시각('Y-m-d H:i:s', 응답의 시간대) → ISO 8601 */
    public static function iso(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }
        try {
            return Carbon::parse($time, Engine::$timezone ?: 'UTC')->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 회원 uuid → 회원 번호 (없으면 0). 같은 요청 안에서는 기억한다 */
    public static function memberSrl(?string $uuid): int
    {
        static $cache = [];
        if ($uuid === null || $uuid === '') {
            return 0;
        }
        if (! array_key_exists($uuid, $cache)) {
            $cache[$uuid] = (int) (User::where('uuid', $uuid)->value('id') ?? 0);
        }

        return $cache[$uuid];
    }

    public static function slugParam(string $name = 'mid'): string
    {
        $mid = trim((string) Api::param($name, ''));
        if ($mid === '' || ! preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $mid)) {
            throw new ApiError(400, 'invalid_mid', 'invalid mid');
        }

        return $mid;
    }

    /** 게시판 모델 (활성, 없으면 404). 설정값(한도 등)을 읽을 때만 쓴다 — 권한은 엔진 API 가 본다 */
    public static function boardModel(string $slug): Board
    {
        $board = Board::where('slug', $slug)->first();
        if (! $board || ! $board->is_active) {
            throw new ApiError(404, 'board_not_found', '없는 게시판입니다.');
        }

        return $board;
    }

    /** 지금 회원(비회원)이 그 게시판 권한을 가졌는가 — 엔진의 권한 판단 그대로 */
    public static function can(string $slug, string $action): bool
    {
        try {
            return PermissionHelper::check("sirsoft-board.{$slug}.{$action}", Api::$user);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---- site · menu

    public static function site(): array
    {
        $name = (string) (g7_core_settings('general.site_name') ?: config('app.name'));

        return [
            'name' => $name,
            'names' => null,
            'base_url' => self::base().'/',
            'theme_color' => null,
            'logo_url' => self::siteLogo(),
            'icon_url' => self::absolute('/favicon.ico'),
            'home_mid' => self::HOME_MID,
            'engine' => 'gnuboard7',
            'engine_version' => (string) config('app.version'),
            'api_version' => Api::VERSION,
            'lang' => app()->getLocale(),
            'share_site' => Registry::shareSite(),
            'features' => ['html_write', 'guest_write', 'guest_comment', 'guest_manage', 'comment_edit', 'edit_photos', 'file_upload', 'comment_vote', 'devices', 'reply_write', 'resolve_path'],
            'member' => Api::profile(Api::$user),
        ];
    }

    private static function siteLogo(): ?string
    {
        $logo = g7_core_settings('general.site_logo');
        if (is_array($logo)) {
            $logo = $logo['url'] ?? ($logo[0]['url'] ?? null);
        }

        return is_string($logo) && $logo !== '' ? self::absolute($logo) : null;
    }

    /** 이 회원이 읽을 수 있는 게시판 (웹 메뉴 board-menu 와 같은 엔진 판단) [{slug, name}] */
    public static function menuBoards(): array
    {
        static $boards = null;
        if ($boards === null) {
            $boards = [];
            foreach (Engine::data('GET', self::BOARD_API.'/board-menu') as $row) {
                if (is_array($row) && isset($row['slug'])) {
                    $boards[] = ['slug' => (string) $row['slug'], 'name' => (string) ($row['name'] ?? $row['slug'])];
                }
            }
        }

        return $boards;
    }

    public static function menu(): array
    {
        $items = [['title' => Text::t('최근 글'), 'mid' => self::HOME_MID, 'module' => 'page', 'grant' => null, 'url' => self::base().'/', 'open_window' => false, 'children' => []]];
        foreach (self::menuBoards() as $b) {
            $items[] = [
                'title' => $b['name'],
                'mid' => $b['slug'],
                'module' => 'board',
                'grant' => [
                    'view' => true,
                    'write_document' => self::can($b['slug'], 'posts.write'),
                    'write_comment' => self::can($b['slug'], 'comments.write'),
                ],
                'url' => self::base().'/board/'.$b['slug'],
                'open_window' => false,
                'children' => [],
            ];
        }
        foreach (self::pages() as $p) {
            $items[] = ['title' => $p['title'], 'mid' => 'page-'.$p['slug'], 'module' => 'page', 'grant' => null,
                'url' => self::base().'/page/'.$p['slug'], 'open_window' => false, 'children' => []];
        }

        return ['menus' => [['id' => 'main', 'title' => (string) (g7_core_settings('general.site_name') ?: ''), 'items' => $items]]];
    }

    /** 공개 페이지(sirsoft-page, 발행된 것) [{slug, title}] — 엔진 공개 API 로 열리는 것만 */
    private static function pages(): array
    {
        if (! class_exists(\Modules\Sirsoft\Page\Models\Page::class)) {
            return [];
        }
        $out = [];
        try {
            foreach (\Modules\Sirsoft\Page\Models\Page::query()->where('published', true)->orderBy('id')->get() as $page) {
                $title = $page->title ?? $page->slug;
                if (is_array($title)) {
                    $title = $title[app()->getLocale()] ?? ($title[config('app.locale')] ?? reset($title));
                }
                $out[] = ['slug' => (string) $page->slug, 'title' => (string) $title];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    // ---- 목록

    /** 엔진 글 목록 한 쪽 */
    private static function engineList(string $slug, array $query): array
    {
        [$status, $json] = Engine::call('GET', self::BOARD_API.'/'.rawurlencode($slug).'/posts', $query);
        if ($status === 404) {
            throw new ApiError(404, 'board_not_found', '없는 게시판입니다.');
        }
        if ($status === 401 || $status === 403) {
            throw Api::$user ? new ApiError(403, 'not_permitted', self::engineMessage($json, '권한이 없습니다.'), [], true)
                : new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }
        if ($status < 200 || $status >= 300 || ! is_array($json)) {
            throw Engine::error($status, $json);
        }

        return (array) ($json['data'] ?? []);
    }

    private static function engineMessage(?array $json, string $fallback): string
    {
        $m = is_array($json) ? (string) ($json['message'] ?? '') : '';

        return $m !== '' ? $m : Text::t($fallback);
    }

    /** 앱 분류 번호 ↔ 엔진 분류 이름 (게시판 categories 순서, 1부터) */
    public static function categoryNames(array $board): array
    {
        $names = [];
        foreach ((array) ($board['categories'] ?? []) as $c) {
            $name = is_array($c) ? (string) ($c['name'] ?? $c['label'] ?? $c['value'] ?? '') : (string) $c;
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function board(): array
    {
        $slug = self::slugParam();
        $page = max(1, Api::intParam('page', 1));
        $query = ['page' => $page];
        $keyword = trim((string) Api::param('search_keyword', ''));
        if ($keyword !== '') {
            $target = (string) Api::param('search_target', 'title_content');
            $map = ['title' => 'title', 'content' => 'content', 'title_content' => 'title_content', 'nick_name' => 'author', 'user_id' => 'author'];
            $query['search'] = $keyword;
            $query['search_field'] = $map[$target] ?? 'title_content';
        }
        $model = self::boardModel($slug);
        $categories = self::categoryNames(['categories' => $model->categories ?? []]);
        $categorySrl = Api::intParam('category');
        if ($categorySrl > 0 && isset($categories[$categorySrl - 1])) {
            $query['category'] = $categories[$categorySrl - 1];
        }
        $data = self::engineList($slug, $query);
        $boardInfo = (array) ($data['board'] ?? []);
        $abilities = (array) ($data['abilities'] ?? []);
        $settings = (array) ($boardInfo['settings'] ?? []);
        $notices = [];
        $documents = [];
        foreach ((array) ($data['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $item = self::summary($row, $slug, $settings, $categories);
            if (($row['row_type'] ?? '') === 'notice') {
                $notices[] = $item;
            } else {
                $documents[] = $item;
            }
        }
        $pagination = (array) ($data['pagination'] ?? []);

        return [
            'board' => self::boardInfo($slug, $model, $boardInfo, $abilities, $categories),
            'notices' => $page === 1 ? $notices : [],
            'documents' => $documents,
            'page' => (int) ($pagination['current_page'] ?? $page),
            'total_pages' => max(1, (int) ($pagination['last_page'] ?? 1)),
            'total_count' => (int) ($pagination['total'] ?? count($documents)),
        ];
    }

    /** 앱의 board 객체 */
    public static function boardInfo(string $slug, Board $model, array $boardInfo, array $abilities, array $categories): array
    {
        $settings = (array) ($boardInfo['settings'] ?? []);
        $type = (string) ($boardInfo['type'] ?? $model->type ?? 'basic');
        $guest = Api::$user === null;
        $canWrite = ! empty($abilities['can_write']);
        $canComment = ! empty($abilities['can_write_comments']) && ($settings['use_comment'] ?? true);
        $upload = self::upload($model, ! empty($abilities['can_upload']) && $canWrite);
        $cats = [];
        foreach ($categories as $i => $name) {
            $cats[] = ['category_srl' => $i + 1, 'parent_srl' => 0, 'title' => $name, 'document_count' => 0, 'writable' => true];
        }

        return [
            'mid' => $slug,
            'title' => (string) ($boardInfo['name'] ?? $slug),
            'style' => $type === 'gallery' ? 'gallery' : ($type === 'card' ? 'webzine' : 'list'),
            'columns' => ['read_count' => (bool) ($settings['show_view_count'] ?? true), 'vote_count' => (bool) ($settings['use_reaction'] ?? false)],
            'categories' => $cats,
            'grant' => [
                'list' => ! empty($abilities['can_read']),
                'view' => ! empty($abilities['can_read']),
                'write_document' => $canWrite,
                'write_comment' => $canComment,
                'manager' => ! empty($abilities['can_manage']),
            ],
            'upload' => $upload,
            'category_required' => false,
            'extra_keys' => [],
            'captcha' => ['document' => false, 'comment' => false],
            'captcha_widget' => null,
            'labels' => (object) [],
            'use_secret' => ($settings['secret_mode'] ?? 'disabled') !== 'disabled',
            'write_url' => self::base().'/board/'.$slug.'/write',
            'guest_fields' => $guest && $canWrite ? ['nick_name', 'password'] : null,
            'guest_comment_fields' => $guest && $canComment ? ['nick_name', 'password'] : null,
            'guest_certify_fields' => $guest ? ['password'] : null,
        ];
    }

    /** 글쓰기 사진·파일 첨부 (웹 글쓰기의 첨부와 같은 판단: 첨부 사용 + 업로드 권한, 확장자·크기·개수는 게시판 설정) */
    public static function upload(Board $model, bool $allowed): array
    {
        $defaults = (array) g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $use = (bool) ($model->use_file_upload ?? $defaults['use_file_upload'] ?? false);
        $maxMb = (int) ($model->max_file_size ?? $defaults['max_file_size'] ?? 10);
        $maxCount = (int) ($model->max_file_count ?? $defaults['max_file_count'] ?? 5);
        $ext = $model->allowed_extensions ?: ($defaults['allowed_extensions'] ?? null);
        $ext = is_string($ext) ? array_filter(array_map('trim', explode(',', $ext))) : (array) $ext;
        $ext = array_values(array_map(fn ($e) => strtolower(ltrim((string) $e, '.')), $ext));
        $ok = $allowed && $use;
        $photo = $ok && (! $ext || array_intersect(['jpg', 'jpeg'], $ext));

        return [
            'allowed' => (bool) $photo,
            'max_file_size' => $maxMb * 1024 * 1024,
            'max_total_size' => $maxMb * 1024 * 1024 * max(1, $maxCount),
            'files' => ['allowed' => $ok, 'max_file_size' => $maxMb * 1024 * 1024, 'max_count' => $maxCount, 'extensions' => $ext ?: null],
        ];
    }

    /** 문서요약 (목록 한 줄) */
    public static function summary(array $row, string $slug, array $settings, array $categories): array
    {
        $author = (array) ($row['author'] ?? []);
        $category = $row['category'] ?? null;
        $categorySrl = 0;
        if (is_string($category) && $category !== '') {
            $i = array_search($category, $categories, true);
            $categorySrl = $i === false ? 0 : $i + 1;
        }
        $secretHidden = ! empty($row['is_secret']) && ($row['content_preview'] ?? '') === '' && empty($row['is_author']);
        $item = [
            'document_srl' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'author' => (string) ($author['name'] ?? ''),
            'author_image' => self::absolute($author['avatar'] ?? null),
            'author_srl' => self::memberSrl($author['uuid'] ?? null),
            'is_mine' => ! empty($row['is_author']),
            'regdate' => self::iso($row['created_at'] ?? null),
            'last_update' => self::iso($row['updated_at'] ?? $row['created_at'] ?? null),
            'category_srl' => $categorySrl,
            'comment_count' => (int) ($row['comment_count'] ?? 0),
            'read_count' => ($settings['show_view_count'] ?? true) ? (int) ($row['view_count'] ?? 0) : null,
            'vote_count' => ($settings['use_reaction'] ?? false) ? (int) ($row['reaction_total'] ?? 0) : null,
            'is_notice' => ! empty($row['is_notice']),
            'is_secret' => ! empty($row['is_secret']),
            'has_files' => ! empty($row['has_attachment']),
            'thumbnail' => $secretHidden ? null : self::absolute($row['thumbnail'] ?? null),
            'summary' => $secretHidden ? '' : mb_substr(trim((string) ($row['content_preview'] ?? '')), 0, 200),
            'mid' => $slug,
        ];
        if ((int) ($row['depth'] ?? 0) > 0) {
            $item['reply_depth'] = (int) $row['depth'];
        }

        return $item;
    }

    // ---- 모아 보기

    public static function feed(): array
    {
        $count = min(50, max(5, Api::intParam('list_count', 20)));
        $page = max(1, Api::intParam('page', 1));
        $want = array_filter(array_map('trim', explode(',', (string) Api::param('mids', ''))));
        $boards = self::menuBoards();
        if ($want) {
            $boards = array_values(array_filter($boards, fn ($b) => in_array($b['slug'], $want, true)));
        }
        $need = min(100, $page * $count);
        $all = [];
        $total = 0;
        foreach ($boards as $b) {
            try {
                $data = self::engineList($b['slug'], ['page' => 1, 'per_page' => $need]);
            } catch (ApiError $e) {
                continue;
            }
            $settings = (array) ($data['board']['settings'] ?? []);
            $categories = self::categoryNames((array) ($data['board'] ?? []));
            $total += (int) ($data['pagination']['total'] ?? 0);
            foreach ((array) ($data['data'] ?? []) as $row) {
                if (! is_array($row) || ($row['row_type'] ?? '') === 'notice') {
                    continue;
                }
                $item = self::summary($row, $b['slug'], $settings, $categories);
                $item['board_title'] = $b['name'];
                $all[] = $item;
            }
        }
        usort($all, fn ($a, $b) => strcmp((string) $b['regdate'], (string) $a['regdate']));

        return [
            'documents' => array_slice($all, ($page - 1) * $count, $count),
            'page' => $page,
            'total_pages' => max(1, (int) ceil(min($total, 100) / $count)),
        ];
    }

    // ---- 글

    private static function documentSrl(): int
    {
        $srl = Api::intParam('document_srl');
        if ($srl <= 0) {
            throw new ApiError(400, 'invalid_document', 'invalid document_srl');
        }

        return $srl;
    }

    /** 엔진 글 상세 (비회원 비밀글은 password 로 연다: 엔진의 비밀번호 확인 → 열람 토큰) */
    public static function enginePost(string $slug, int $id): array
    {
        $password = (string) Api::param('password', '');
        if ($password !== '' && Api::$request->isMethod('POST')) {
            Write::unlockSecret($slug, $id, $password);
        }
        [$status, $json] = Engine::call('GET', self::BOARD_API.'/'.rawurlencode($slug).'/posts/'.$id);
        if ($status === 404) {
            throw new ApiError(404, 'document_not_found', '없는 글입니다.');
        }
        if ($status === 401 || $status === 403) {
            throw Api::$user ? new ApiError(403, 'not_permitted', self::engineMessage($json, '권한이 없습니다.'), [], true)
                : new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }
        if ($status < 200 || $status >= 300 || ! is_array($json)) {
            throw Engine::error($status, $json, 'document_not_found');
        }

        return (array) ($json['data'] ?? []);
    }

    public static function document(): array
    {
        $slug = self::slugParam();
        $id = self::documentSrl();
        $model = self::boardModel($slug);
        $post = self::enginePost($slug, $id);
        $board = (array) ($post['board'] ?? []);
        $abilities = (array) ($post['abilities'] ?? []);
        $categories = self::categoryNames(['categories' => $model->categories ?? []]);
        $settings = ['show_view_count' => $board['show_view_count'] ?? true, 'use_reaction' => $board['use_reaction'] ?? false];
        $item = self::summary($post + ['content_preview' => strip_tags((string) ($post['content'] ?? ''))], $slug, $settings, $categories);
        $readable = self::contentReadable($post);
        $item['content_html'] = $readable ? self::contentHtml($post) : null;
        if (! $readable) {
            $item['thumbnail'] = null;
            $item['summary'] = '';
        }
        $item['files'] = $readable ? self::files($slug, (array) ($post['attachments'] ?? [])) : [];
        $item['extra_vars'] = [];
        $item['permanent_url'] = self::base().'/board/'.$slug.'/'.$id;
        $isOwner = ! empty($post['is_owner']) || ! empty($post['is_author']);
        $manage = ! empty($abilities['can_manage']);
        $guestPost = ! empty($post['is_guest_post']);
        $item['can_comment'] = $readable && ! empty($abilities['can_write_comments']) && ($board['use_comment'] ?? true);
        $item['can_edit'] = $readable && ($manage || ($isOwner && ! $guestPost));
        $item['can_delete'] = $item['can_edit'];
        $item['password_edit'] = $guestPost && Api::$user === null && ! $item['can_edit'];
        $item['password_view'] = ! $readable && $guestPost && Api::$user === null;
        $item['can_reply'] = $readable && ! empty($board['use_reply']) && ! empty($abilities['can_write']) && empty($post['is_notice'])
            && empty($post['reply_locked_by_secret_ancestor']) && (int) ($post['depth'] ?? 0) < (int) ($board['max_reply_depth'] ?? 1);
        if ($item['can_reply']) {
            $item['reply_title'] = 'Re: '.(string) ($post['title'] ?? '');
            $item['reply_secret'] = ! empty($post['is_secret']);
        }
        $item['board'] = self::boardInfo($slug, $model, ['name' => $board['name'] ?? $slug, 'type' => $board['type'] ?? null,
            'settings' => ['use_comment' => $board['use_comment'] ?? true, 'show_view_count' => $board['show_view_count'] ?? true,
                'use_reaction' => $board['use_reaction'] ?? false, 'secret_mode' => $model->secret_mode->value ?? (string) ($model->secret_mode ?? 'disabled')]],
            $abilities, $categories);
        $item['actions'] = self::actions($post, $board);
        $item['comments'] = self::commentPage($slug, $id, $post, $readable ? (array) ($post['comments'] ?? []) : []);

        return $item;
    }

    /** 본문을 볼 수 있는가 (엔진이 비밀글 본문을 비우거나 가렸으면 아니다) */
    private static function contentReadable(array $post): bool
    {
        if (empty($post['is_secret'])) {
            return ($post['status'] ?? 'published') !== 'blinded' || ! empty($post['abilities']['can_manage']);
        }

        return ! empty($post['is_owner']) || ! empty($post['is_author']) || ! empty($post['abilities']['can_read_secret'])
            || (array_key_exists('content', $post) && $post['content'] !== null && $post['content'] !== '' && empty($post['secret_locked']));
    }

    /** 본문 HTML: html 글은 엔진 HTML(같은 사이트 주소는 절대 주소로), 글자 글은 이스케이프 + 줄바꿈 */
    public static function contentHtml(array $post): string
    {
        $content = (string) ($post['content'] ?? '');
        if (($post['content_mode'] ?? 'html') !== 'html') {
            $html = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        } else {
            $html = Html::absolutize($content, self::base());
        }

        return $html;
    }

    /** files: 첨부 [{file_srl, name, size, is_image, image_url, download}] */
    public static function files(string $slug, array $attachments): array
    {
        $out = [];
        foreach ($attachments as $a) {
            if (! is_array($a) || ($a['collection'] ?? 'attachments') !== 'attachments') {
                continue;
            }
            $out[] = [
                'file_srl' => (int) $a['id'],
                'name' => (string) ($a['original_filename'] ?? ''),
                'size' => (int) ($a['size'] ?? 0),
                'is_image' => ! empty($a['is_image']),
                'image_url' => ! empty($a['is_image']) ? self::absolute($a['preview_url'] ?? null) : null,
                'download' => ['r' => 'file', 'mid' => $slug, 'file_srl' => (int) $a['id'], 'sid' => (string) ($a['hash'] ?? '')],
            ];
        }

        return $out;
    }

    /** 글 아래 기능: 추천·비추천(반응 like·dislike), 스크랩, 신고 */
    private static function actions(array $post, array $board): array
    {
        $types = [];
        foreach ((array) ($board['reaction_type_options'] ?? []) as $t) {
            if (is_array($t) && isset($t['code'], $t['id'])) {
                $types[(string) $t['code']] = (int) $t['id'];
            }
        }
        $counts = (array) ($post['reaction_counts'] ?? []);
        $mine = $post['my_reaction_type_id'] ?? null;
        $use = ! empty($board['use_reaction']);
        $up = $types['like'] ?? null;
        $down = $types['dislike'] ?? null;
        $member = Api::$user !== null;

        return [
            'vote_up' => ['enabled' => $use && $up !== null, 'count' => $up !== null ? (int) ($counts[$up] ?? 0) : 0, 'public' => false],
            'vote_down' => ['enabled' => $use && $down !== null, 'count' => $down !== null ? (int) ($counts[$down] ?? 0) : 0, 'public' => false],
            'my_vote' => $mine !== null && (int) $mine === $up ? 1 : ($mine !== null && (int) $mine === $down ? -1 : 0),
            'can_cancel_vote' => true,
            'is_mine' => ! empty($post['is_author']),
            'can_scrap' => $member && ! empty($board['use_scrap']),
            'can_declare' => $member && ! empty($board['use_report']) && empty($post['is_author']) && empty($post['is_already_reported']),
            'scrapped' => ! empty($post['is_scraped']),
        ];
    }

    // ---- 댓글

    public static function comments(): array
    {
        $slug = self::slugParam();
        $id = self::documentSrl();
        self::boardModel($slug);
        $post = self::enginePost($slug, $id);
        $rows = self::contentReadable($post) ? (array) ($post['comments'] ?? []) : [];
        if (! empty($post['comments_truncated'])) {
            [$status, $json] = Engine::call('GET', self::BOARD_API.'/'.rawurlencode($slug).'/posts/'.$id.'/comments');
            if ($status === 200 && is_array($json)) {
                $rows = (array) ($json['data'] ?? []);
            }
        }

        return self::commentPage($slug, $id, $post, $rows);
    }

    /** 댓글 한 쪽 (엔진은 한 번에 모두 준다) */
    private static function commentPage(string $slug, int $id, array $post, array $rows): array
    {
        $board = (array) ($post['board'] ?? []);
        $types = [];
        foreach ((array) ($board['reaction_type_options'] ?? []) as $t) {
            if (is_array($t) && isset($t['code'], $t['id'])) {
                $types[(string) $t['code']] = (int) $t['id'];
            }
        }
        $useVote = ! empty($board['use_comment_reaction']);
        $canWrite = ! empty($post['abilities']['can_write_comments']);
        $manage = ! empty($post['abilities']['can_manage']);
        $items = [];
        foreach ($rows as $c) {
            if (! is_array($c)) {
                continue;
            }
            $author = (array) ($c['author'] ?? []);
            $mine = ! empty($c['is_author']) || ! empty($c['is_owner']);
            $deleted = ! empty($c['deleted_at']);
            $guest = ! empty($c['is_guest_comment']);
            $counts = (array) ($c['reaction_counts'] ?? []);
            $up = $types['like'] ?? null;
            $down = $types['dislike'] ?? null;
            $my = $c['my_reaction_type_id'] ?? null;
            $items[] = [
                'comment_srl' => (int) $c['id'],
                'parent_srl' => (int) ($c['parent_id'] ?? 0),
                'depth' => (int) ($c['depth'] ?? 0),
                'author' => (string) ($author['name'] ?? ''),
                'author_image' => self::absolute($author['avatar'] ?? null),
                'author_srl' => self::memberSrl($author['uuid'] ?? null),
                'is_mine' => $mine,
                'regdate' => self::iso($c['created_at'] ?? null),
                'is_secret' => ! empty($c['is_secret']),
                'content_html' => $deleted ? '' : nl2br(htmlspecialchars((string) ($c['content'] ?? ''), ENT_QUOTES, 'UTF-8')),
                'vote_count' => $up !== null ? (int) ($counts[$up] ?? 0) : 0,
                'vote' => $useVote && ! $deleted ? [
                    'up' => ['enabled' => $up !== null, 'count' => $up !== null ? (int) ($counts[$up] ?? 0) : 0],
                    'down' => ['enabled' => $down !== null, 'count' => $down !== null ? (int) ($counts[$down] ?? 0) : 0],
                    'my_vote' => $my !== null && (int) $my === $up ? 1 : ($my !== null && (int) $my === $down ? -1 : 0),
                    'can_cancel' => true,
                ] : null,
                'can_delete' => ! $deleted && (($mine && ! $guest) || $manage),
                'can_edit' => ! $deleted && $canWrite && (($mine && ! $guest) || $manage),
                'password_edit' => ! $deleted && $guest && Api::$user === null,
                'files' => [],
            ];
        }

        return ['items' => $items, 'page' => 1, 'total_pages' => 1, 'total_count' => count($items)];
    }

    // ---- 페이지 · 첫 화면

    public static function page(): array
    {
        $mid = trim((string) Api::param('mid', ''));
        if ($mid === self::HOME_MID) {
            return self::homePage();
        }
        if (! str_starts_with($mid, 'page-')) {
            throw new ApiError(400, 'not_page', 'not a page');
        }
        $slug = substr($mid, 5);
        [$status, $json] = Engine::call('GET', '/api/modules/sirsoft-page/pages/'.rawurlencode($slug));
        if ($status !== 200 || ! is_array($json)) {
            throw Engine::error($status, $json);
        }
        $page = (array) ($json['data'] ?? []);
        $title = $page['title'] ?? $slug;
        $content = $page['content'] ?? '';
        if (is_array($title)) {
            $title = $title[app()->getLocale()] ?? reset($title);
        }
        if (is_array($content)) {
            $content = $content[app()->getLocale()] ?? reset($content);
        }

        return ['mid' => $mid, 'title' => (string) $title, 'page_type' => 'article',
            'content_html' => Html::absolutize((string) $content, self::base()), 'web_url' => self::base().'/page/'.$slug];
    }

    /** 첫 화면: 읽을 수 있는 게시판마다 최근 글 5개 (권한은 엔진 목록 API 가 판단) */
    private static function homePage(): array
    {
        $html = '';
        foreach (self::menuBoards() as $b) {
            try {
                $data = self::engineList($b['slug'], ['page' => 1, 'per_page' => 5]);
            } catch (ApiError $e) {
                continue;
            }
            $lines = '';
            foreach ((array) ($data['data'] ?? []) as $row) {
                if (! is_array($row) || ($row['row_type'] ?? '') === 'notice') {
                    continue;
                }
                $url = self::base().'/board/'.$b['slug'].'/'.(int) $row['id'].'?mid='.rawurlencode($b['slug']).'&document_srl='.(int) $row['id'];
                $lines .= '<li><a href="'.htmlspecialchars($url, ENT_QUOTES).'">'.htmlspecialchars((string) $row['title'], ENT_QUOTES).'</a></li>';
            }
            if ($lines === '') {
                continue;
            }
            $boardUrl = self::base().'/board/'.$b['slug'].'?mid='.rawurlencode($b['slug']);
            $html .= '<section class="bh-latest"><h3><a href="'.htmlspecialchars($boardUrl, ENT_QUOTES).'">'.htmlspecialchars($b['name'], ENT_QUOTES).'</a></h3><ul>'.$lines.'</ul></section>';
        }

        return ['mid' => self::HOME_MID, 'title' => Text::t('최근 글'), 'page_type' => 'widget', 'content_html' => $html, 'web_url' => self::base().'/'];
    }

    // ---- 링크 풀이 · 첨부

    public static function resolve(): array
    {
        $path = (string) Api::param('path', '');
        $url = (string) Api::param('url', '');
        if ($path === '' && $url !== '') {
            $path = (string) parse_url($url, PHP_URL_PATH);
        }
        if ($path !== '') {
            if (preg_match('#^/board/([A-Za-z0-9_\-]+)/(\d+)/?$#', $path, $m)) {
                return ['mid' => $m[1], 'module' => 'board', 'title' => null, 'document_srl' => (int) $m[2]];
            }
            if (preg_match('#^/board/([A-Za-z0-9_\-]+)/?$#', $path, $m)) {
                return ['mid' => $m[1], 'module' => 'board', 'title' => null, 'document_srl' => null];
            }
            if (preg_match('#^/page/([A-Za-z0-9_\-]+)/?$#', $path, $m)) {
                return ['mid' => 'page-'.$m[1], 'module' => 'page', 'title' => null, 'document_srl' => null];
            }
            throw new ApiError(404, 'not_found', '없는 페이지입니다.');
        }
        $mid = trim((string) Api::param('mid', ''));
        $srl = Api::intParam('document_srl');
        if ($mid === '' && $srl > 0 && class_exists(\Modules\Sirsoft\Board\Models\Post::class)) {
            $boardId = \Modules\Sirsoft\Board\Models\Post::whereKey($srl)->value('board_id');
            $mid = $boardId ? (string) Board::whereKey($boardId)->value('slug') : '';
        }
        if ($mid === '') {
            throw new ApiError(404, 'not_found', '없는 페이지입니다.');
        }
        if ($mid === self::HOME_MID || str_starts_with($mid, 'page-')) {
            return ['mid' => $mid, 'module' => 'page', 'title' => null, 'document_srl' => null];
        }
        $board = Board::where('slug', $mid)->first();
        if (! $board) {
            throw new ApiError(404, 'not_found', '없는 페이지입니다.');
        }

        return ['mid' => $mid, 'module' => 'board', 'title' => null, 'document_srl' => $srl > 0 ? $srl : null];
    }

    /** 첨부 받기: 엔진 다운로드 API 를 그대로(권한·비밀글·다운로드 기록은 엔진) */
    public static function file()
    {
        $slug = self::slugParam();
        $hash = (string) Api::param('sid', '');
        if (! preg_match('/^[A-Za-z0-9]{4,64}$/', $hash)) {
            throw new ApiError(400, 'invalid_file', 'invalid file');
        }
        [$status, $json, $response] = Engine::call('GET', self::BOARD_API.'/'.rawurlencode($slug).'/attachment/'.$hash);
        if ($status >= 200 && $status < 400 && ! is_array($json)) {
            return $response;
        }
        if ($status === 401 && ! Api::$user) {
            throw new ApiError(401, 'login_required', '로그인이 필요합니다.');
        }
        throw Engine::error($status, $json);
    }
}
