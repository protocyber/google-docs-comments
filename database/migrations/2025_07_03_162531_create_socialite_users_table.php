<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up()
    {
        Schema::create('socialite_users', function (Blueprint $table)
        {
            $table->id();

            $table->foreignId('user_id');
            $table->string('provider');
            $table->string('provider_id');

            // tambahan untuk akses google docs
            $table->text('google_access_token')->nullable();
            $table->text('google_refresh_token')->nullable(); // Penting untuk memperbarui token
            $table->timestamp('google_expires_at')->nullable(); // Waktu kadaluarsa token akses

            $table->timestamps();

            $table->unique([
                'provider',
                'provider_id',
            ]);
        });
    }

    public function down()
    {
        Schema::dropIfExists('socialite_users');
    }
};
