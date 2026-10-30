<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 게시판모아 앱의 기기별 로그인 표를 만든다.
     * 접근 토큰은 엔진의 Sanctum 토큰(personal_access_tokens)이고, 이 표는 기기마다 갱신 토큰(해시만)을 둔다.
     */
    public function up(): void
    {
        if (Schema::hasTable('boardmoa_tokens')) {
            return;
        }
        Schema::create('boardmoa_tokens', function (Blueprint $table) {
            $table->id()->comment('기기 번호');
            $table->unsignedBigInteger('user_id')->index()->comment('회원 번호(users.id)');
            $table->unsignedBigInteger('access_token_id')->nullable()->index()->comment('지금 접근 토큰(personal_access_tokens.id)');
            $table->char('refresh_hash', 64)->unique()->comment('갱신 토큰 SHA-256');
            $table->char('prev_refresh_hash', 64)->nullable()->index()->comment('직전 갱신 토큰 SHA-256(응답을 놓쳤을 때 한 번 더 받는다)');
            $table->unsignedInteger('refresh_expires')->comment('갱신 토큰 만료(유닉스 시각)');
            $table->char('password_sig', 64)->comment('발급 때 비밀번호 해시의 서명(비밀번호를 바꾸면 끊는다)');
            $table->string('device_name', 100)->default('')->comment('기기 이름');
            $table->string('platform', 20)->default('')->comment('ios·android');
            $table->string('ip_address', 64)->default('')->comment('마지막 IP');
            $table->unsignedInteger('created')->comment('로그인 시각');
            $table->unsignedInteger('last_used')->comment('마지막 사용 시각');
        });
    }

    /**
     * 표를 지운다.
     */
    public function down(): void
    {
        Schema::dropIfExists('boardmoa_tokens');
    }
};
