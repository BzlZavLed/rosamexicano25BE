<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mailer');

        Schema::create('mailer', function (Blueprint $table) {
            $table->increments('id');
            $table->string('mail', 255);
            $table->string('asunto', 150);
            $table->text('mensaje');
            $table->string('status', 50);
            $table->date('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailer');
    }
};

