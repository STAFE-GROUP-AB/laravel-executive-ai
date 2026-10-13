<?php

use App\Support\Data\SensitivityClass;
use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `push_subscriptions` — **not** in the §3.1 list: added by ADR-006 and picked up
     * here through ADR-002 **D9.5**, which names the columns (`endpoint`, `p256dh`,
     * `auth`, `user_id`, `created_at`, `last_seen`, `revoked_at`; unique on
     * `endpoint`) and says it lives in the same database file on the D10 portable
     * rules.
     *
     * One row per browser that opted in to Web Push (RFC 8030/8291/8292). The
     * endpoint is the push service URL the browser handed us and is the natural key:
     * re-subscribing the same browser must update the row rather than add a second
     * one, so `push_subscriptions_endpoint_unique` covers it. 500 characters holds
     * the real endpoints with room to spare and stays inside MySQL's 3072-byte
     * unique-key limit at `utf8mb4`, which a `text` column could not.
     *
     * `p256dh` and `auth` are the subscription's own key material — the client public
     * key and the auth secret the payload encryption uses. They are the browser's
     * values, not ours, so ADR-003 D1's "never a credential in a table" does not
     * send them to the Keychain (the **VAPID** private key is the Keychain item,
     * `se.stafe.executive-ai.webpush`). They are classified `confidential` and
     * ADR-006 asks for them encrypted at rest; the `encrypted:` cast is **S2-04's**
     * (#87) to add, which is why this migration leaves them plain and says so rather
     * than half-applying two items' work.
     *
     * `last_seen` is both the liveness signal and the retention clock: the
     * `push_devices` rule drops a subscription 180 days after the device was last
     * seen, because a subscription nobody uses is a secret kept for nothing.
     * `revoked_at` is set when the push service reports the subscription gone (404 /
     * 410) or the user turns notifications off, and the row is kept so the Devices
     * view can say what happened.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table, nullable: true);
            $table->char('user_id', 26);
            $table->string('endpoint', 500);
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('content_encoding', 20)->default('aes128gcm');
            $table->string('device_label', 200)->nullable();
            $table->string('rp_id', 253)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_seen')->nullable();
            $table->dateTime('last_delivery_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 200)->nullable();
            BusinessObjectColumns::lineage($table, SensitivityClass::Confidential);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('user_id', 'push_subscriptions_user_fk')->references('id')->on('users');
            $table->unique('endpoint', 'push_subscriptions_endpoint_unique');
            $table->index(['user_id', 'revoked_at'], 'push_subscriptions_user_active_index');
            $table->index('last_seen', 'push_subscriptions_last_seen_index');
        });
    }
};
