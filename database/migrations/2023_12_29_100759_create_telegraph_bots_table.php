<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('telegraph_bots', function (Blueprint $table) {
            $table->id();
            $table->string('token')->unique();
            $table->string('name')->nullable();
            $table->integer('organization_id');
            $table->string('secret_key')->nullable();
            $table->string('search')->nullable();
            $table->string('params')->nullable();
            $table->timestamps();
        });
    }
};
