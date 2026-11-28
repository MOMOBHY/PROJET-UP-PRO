<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('etudiant')->after('password');
            $table->string('student_number')->nullable()->unique()->after('role');
        });

        Schema::create('orientation_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('student_number')->unique();
            $table->string('name');
            $table->json('s1')->nullable();
            $table->json('s2')->nullable();
            $table->json('choices')->nullable();
            $table->string('estimate')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orientation_students');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'student_number']);
        });
    }
};
