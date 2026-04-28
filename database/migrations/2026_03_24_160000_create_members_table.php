<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 50)->comment('名字');
            $table->string('last_name', 50)->comment('姓氏');
            $table->char('national_id_number', 10)->unique()->comment('身分證字號');
            $table->string('email', 100)->unique()->comment('電子郵件');
            $table->string('phone', 20)->unique()->comment('手機號碼');
            $table->string('password', 255)->comment('密碼');
            $table->date('birth_date')->comment('生日');
            $table->text('address')->comment('住址');
            $table->unsignedTinyInteger('gender')->comment('性別');
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};