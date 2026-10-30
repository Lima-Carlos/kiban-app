<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiban Campo, "the technician's day": the travel time between services (estimated by the distance and an
 * average speed), "finish by" and the alert to leave, "I will be late", "pass to another technician", "on my
 * way", the customer's rating on the report, returns (a new order soon after a done one) and the phones that
 * receive alerts (web push).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fd_settings', function (Blueprint $table) {
            // Average speed in town (km/h) and how much longer the road is than the straight line.
            $table->unsignedSmallInteger('travel_speed_kmh')->default(25);
            $table->decimal('route_factor', 3, 2)->default(1.40);
            // Minutes before leaving for the next service when the technician is warned.
            $table->unsignedSmallInteger('leave_alert_minutes')->default(15);
            // A new order of the same customer within these days is a "return" of the previous one.
            $table->unsignedSmallInteger('return_days')->default(30);
            // Where the day starts (the company's base), for the suggested order of the day.
            $table->decimal('base_latitude', 9, 6)->nullable();
            $table->decimal('base_longitude', 9, 6)->nullable();
        });

        Schema::table('fd_orders', function (Blueprint $table) {
            $table->unsignedSmallInteger('delay_minutes')->nullable();
            $table->text('delay_reason')->nullable();
            $table->timestamp('on_the_way_at')->nullable();
            $table->text('handoff_reason')->nullable();
            $table->timestamp('handoff_at')->nullable();
            // The done order this one returns to (same customer, within the return days).
            $table->unsignedBigInteger('return_of_id')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('rating_comment')->nullable();
            $table->timestamp('rated_at')->nullable();
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64);
            $table->string('public_key');
            $table->string('auth_token');
            $table->timestamps();

            $table->unique(['tenant_id', 'endpoint_hash']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('fd_orders', function (Blueprint $table) {
            $table->dropColumn(['delay_minutes', 'delay_reason', 'on_the_way_at', 'handoff_reason', 'handoff_at', 'return_of_id', 'rating', 'rating_comment', 'rated_at']);
        });
        Schema::table('fd_settings', function (Blueprint $table) {
            $table->dropColumn(['travel_speed_kmh', 'route_factor', 'leave_alert_minutes', 'return_days', 'base_latitude', 'base_longitude']);
        });
    }
};
