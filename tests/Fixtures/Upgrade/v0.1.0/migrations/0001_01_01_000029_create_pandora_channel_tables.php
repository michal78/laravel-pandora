<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Pandora\Support\Concerns\ResolvesPandoraSchema;

return new class extends Migration
{
    use ResolvesPandoraSchema;

    public function up(): void
    {
        $this->schema()->create($this->table('channel_accounts'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // The tenant EVERY identity, message and run under this account
            // belongs to. It is written here, by an operator, and nothing in an
            // inbound payload can select or change it -- the message is the
            // least trustworthy thing in the request (ADR-0015).
            $table->string('tenant_id')->nullable()->index();

            // The registered adapter key: 'slack', 'fake'. An account for a
            // channel no extension registered is inert.
            $table->string('channel', 64)->index();

            $table->string('name', 191);
            $table->string('slug', 191);

            // What the remote system calls this workspace/team/space. Inbound
            // messages carry it, and it is how an adapter finds the account --
            // a lookup key, never a permission.
            $table->string('external_id', 191);

            // The agent that answers here. Null means the account is
            // registered and answers nothing, which is what a freshly created
            // account is until somebody binds an agent to it.
            $table->char('agent_id', 26)->nullable();

            // NO credential column, for the same reason `pandora_mcp_servers`
            // has none: the secret lives in `pandora_provider_credentials`,
            // encrypted, resolved by the Phase 3 resolver.
            $table->string('credential_key', 100)->nullable();

            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'pandora_channel_accounts_slug_uq');
            $table->unique(['channel', 'external_id'], 'pandora_channel_accounts_external_uq');
        });

        $this->schema()->create($this->table('channel_identities'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('account_id', 26)->index();

            // What the channel told us. All of it is a fact about a remote
            // system and none of it is a credential: `external_id` identifies
            // the participant TO SLACK, and `display_name` is a string the
            // participant chose. Neither is ever consulted to find a host user
            // -- that is the whole of ADR-0015.
            $table->string('external_id', 191);
            $table->string('display_name', 191)->nullable();
            $table->json('metadata')->nullable();

            // The ONLY path from a channel identity to a host user. Null until
            // a human completes the linking flow: a code issued into the
            // channel, redeemed inside an authenticated host session.
            $table->string('linked_user_type', 191)->nullable();
            $table->string('linked_user_id')->nullable();
            $table->timestamp('linked_at')->nullable();

            // Incremented on every link. It participates in the session
            // isolation key, so a re-linked identity is a NEW conversation
            // boundary rather than an inherited one -- a reassigned Slack
            // handle must not inherit the previous holder's history.
            $table->unsignedInteger('link_epoch')->default(0);

            // Continuity between messages, reset whenever the link changes.
            $table->char('conversation_id', 26)->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'external_id'], 'pandora_channel_identities_uq');
            $table->index(['linked_user_type', 'linked_user_id'], 'pandora_channel_identities_user_ix');
        });

        $this->schema()->create($this->table('channel_link_codes'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('identity_id', 26)->index();

            // Hashed, because a link code is a credential that grants an
            // identity. Database read access must not be the same thing as the
            // ability to become somebody.
            $table->string('code_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Who redeemed it, kept so a link can be explained later.
            $table->string('redeemed_by_type', 191)->nullable();
            $table->string('redeemed_by_id')->nullable();

            $table->timestamps();
        });

        $this->schema()->create($this->table('channel_deliveries'), function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('tenant_id')->nullable()->index();
            $table->char('account_id', 26)->index();
            $table->char('identity_id', 26)->nullable()->index();
            $table->char('run_id', 26)->nullable()->index();

            // 'inbound' | 'outbound'
            $table->string('direction', 16);

            // The remote system's ID for the message. On the inbound side it
            // is the idempotency key: Slack retries, and a retry must produce
            // one run rather than two.
            $table->string('external_message_id', 191)->nullable();

            // 'received' | 'refused' | 'sent' | 'failed'
            $table->string('status', 16);
            $table->text('error')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['account_id', 'direction', 'external_message_id'],
                'pandora_channel_deliveries_uq',
            );
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->table('channel_deliveries'));
        $this->schema()->dropIfExists($this->table('channel_link_codes'));
        $this->schema()->dropIfExists($this->table('channel_identities'));
        $this->schema()->dropIfExists($this->table('channel_accounts'));
    }
};
