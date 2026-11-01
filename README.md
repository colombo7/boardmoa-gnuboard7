# 게시판모아(BoardMoa) 앱 연결 — 그누보드7 플러그인

> **시험판입니다.** 그누보드7 연동은 아직 시험 중입니다.

스마트폰 게시판 앱 **게시판모아**가 그누보드7 사이트의 게시판을 읽고 쓸 수 있게 하는 플러그인입니다.

- 앱 받기: [iPhone·iPad (App Store)](https://apps.apple.com/app/id6826114071) · [Android (Google Play)](https://play.google.com/store/apps/details?id=com.easyapplabs.boardmoa)
- 그누보드5 는 [boardmoa-gnuboard](https://github.com/easyapplabs/boardmoa-gnuboard), XE 1.11·Rhymix 는 [boardmoa-appapi](https://github.com/easyapplabs/boardmoa-appapi), XE3 는 [boardmoa-appapi-xe3](https://github.com/easyapplabs/boardmoa-appapi-xe3), WordPress 는 [boardmoa-wordpress](https://github.com/easyapplabs/boardmoa-wordpress) 를 설치합니다.

그누보드7 용어로 이것은 **플러그인**입니다(게시판처럼 큰 기능 묶음은 '모듈', 덧붙여 설치하는 것은 '플러그인'). 아래의 '게시판 모듈'은 그누보드7 에 든 게시판(sirsoft-board)을 말합니다.

누가 무엇을 볼 수 있는지는 그누보드7 게시판의 권한(역할별 읽기·쓰기·댓글·첨부 권한, 비밀글, 회원 전용 게시판)과 같습니다.
앱의 요청은 그누보드7 게시판 모듈의 API(웹 화면이 쓰는 것)를 그대로 거치므로 권한·금지어·도배 방지·글자 수·첨부 한도·알림이 웹과 같습니다.

| 기능 | 앱에서 |
|---|---|
| 읽기 | 게시판 메뉴(읽을 수 있는 게시판), 목록·분류·검색, 글(에디터 사진)·첨부 내려받기, 댓글·대댓글, 답변 글, 페이지 |
| 쓰기 | 글쓰기(사진·파일 첨부)·고치기·지우기, 답글, 댓글·대댓글·고치기·지우기, 추천·비추천(반응), 댓글 추천, 스크랩, 신고 |
| 비회원 | 비회원 글쓰기·댓글(게시판이 허락할 때), 비밀번호로 비밀글 보기·고치기·지우기 |
| 내 활동 | 내가 쓴 글·댓글, 스크랩, 알림(그누보드7 알림 센터) |
| 내 정보 | 이름·홈페이지·전화번호·서명·자기소개, 프로필 사진, 비밀번호 바꾸기, 로그인한 기기 |

| 가입 | 웹 가입 화면과 같은 칸(메일·이름·닉네임·휴대폰·전화)과 약관 두 개(사이트의 이용약관·개인정보 처리방침 페이지), 가입하면 바로 로그인 |

웹에서 하는 것: 탈퇴(그누보드7 이 메일 본인 확인을 요구), 2단계 인증을 쓰는 계정의 로그인, 본인인증이 필요한 동작.

## 필요한 것
- 그누보드7 7.1.1 이상, 게시판 모듈(sirsoft-board) 1.2.0 이상
- 게시판모아 앱 2.0.8 이상

## 설치
그누보드7 관리자 화면에서 설치합니다. 둘 중 하나로 합니다.

- **GitHub 주소로:** 관리자 → 플러그인 → 설치 → GitHub 에 `https://github.com/easyapplabs/boardmoa-gnuboard7` 를 넣습니다.
- **ZIP 으로:** [Releases](https://github.com/easyapplabs/boardmoa-gnuboard7/releases) 에서 `appapi-g7-<버전>.zip` 을 받아 관리자 → 플러그인 → 설치 → ZIP 파일로 올립니다.

설치한 뒤 플러그인을 **활성화**합니다. 확인: 브라우저에서 `https://<사이트>/api/plugins/easyapplabs-boardmoa/api?r=site` 를 열어
`"ok":true` 와 `"engine":"gnuboard7"` 가 보이면 됩니다. 그 뒤 앱에서 사이트 주소를 추가하면 됩니다.

SSH 를 쓸 수 있으면 이 저장소를 `plugins/_bundled/easyapplabs-boardmoa` 에 두고 `php artisan plugin:install easyapplabs-boardmoa` → `php artisan plugin:activate easyapplabs-boardmoa` 로도 설치할 수 있습니다.

### 앞단에서 HTTPS 를 처리하는 사이트
nginx·Cloudflare 같은 앞단이 HTTPS 를 받고 그누보드7 에는 http 로 넘기는 구성이면 그누보드7 `.env` 의 `TRUSTED_PROXIES` 를 그 앞단 주소로 정해 주세요(그누보드7 안내 그대로). 정하지 않으면 그누보드7 이 접속을 https 로 알아보지 못하고, 모든 방문자가 같은 IP 로 보여 로그인 실패 제한 같은 IP 별 제한이 앱 사용자 모두에게 함께 걸립니다.

## 업데이트
그누보드7 의 확장 업데이트가 이 저장소의 최신 릴리스를 봅니다. 관리자 → 플러그인 에서 업데이트가 보이면 [업데이트] 를 누릅니다.

## 설정
관리자 → 플러그인 → 게시판모아 앱 연결 → 설정

- **개발자에게 알리기**(기본 켬): 설치하거나 새 판으로 바뀐 뒤 사이트 주소와 플러그인 버전을 게시판모아 개발자에게 한 번 알립니다. 알려 준 사이트는 앱의 추천 사이트에 들어갈 수 있습니다. 끄면 알리지 않고 이미 알린 사이트도 목록에서 빠집니다.

## 앱 로그인
- 앱 로그인은 웹 로그인과 같은 확인(실패 횟수 제한·계정 잠금·휴면)을 거칩니다. 앱에는 그누보드7 의 로그인 토큰(Sanctum, 이름 `boardmoa`, 1시간)과 기기별 갱신 토큰을 줍니다.
- 비밀번호를 바꾸거나 회원이 차단·탈퇴되면 앱도 다시 로그인해야 합니다. 앱의 '로그인한 기기'에서 기기마다 로그아웃할 수 있습니다.
- 기기별 로그인은 표 `boardmoa_tokens` 에 둡니다(갱신 토큰은 해시만). 플러그인을 지우면 함께 지워집니다.

## 문의
easyapplabs@gmail.com
