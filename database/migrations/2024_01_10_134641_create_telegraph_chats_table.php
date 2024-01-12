<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('telegraph_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('name')->nullable();

            $table->integer('bot_state')->default(0);
            $table->string('search')->nullable();
            $table->json('params')->nullable();
            $table->string('category')->nullable();
            $table->json('collection')->nullable();
            $table->integer('last_message_id')->nullable();


            $table->foreignId('telegraph_bot_id')->constrained('telegraph_bots')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['chat_id', 'telegraph_bot_id']);
        });
    }
};
