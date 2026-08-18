<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_requester_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('key_id', 64);
            $table->string('token_digest', 64);
            $table->unsignedBigInteger('company_id');
            $table->string('profile', 96);
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['key_id', 'company_id', 'profile'], 'evidence_key_profile_unique');
            $table->unique(['token_digest', 'company_id', 'profile'], 'evidence_token_profile_unique');
        });
        Schema::create('evidence_profile_reviewers', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->string('profile', 96);
            $table->boolean('can_review')->default(false);
            $table->boolean('can_revoke')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['user_id', 'company_id', 'profile']);
        });
        Schema::create('evidence_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->string('profile', 96);
            $table->unsignedBigInteger('company_id');
            $table->uuid('suite_tenant_id');
            $table->uuid('customer_id');
            $table->string('requester_key_id', 64);
            $table->unsignedBigInteger('authority_binding_version');
            $table->uuid('request_id');
            $table->uuid('operation_id');
            $table->string('request_digest', 64);
            $table->json('request_json');
            $table->string('status', 16)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->json('receipt_json')->nullable();
            $table->string('receipt_digest', 64)->nullable();
            $table->string('signature', 128)->nullable();
            $table->string('key_id', 64)->nullable();
            $table->timestamp('retention_until');
            $table->timestamps();
            $table->unique(['company_id', 'profile', 'request_id'], 'evidence_profile_request_unique');
            $table->unique(['company_id', 'profile', 'operation_id'], 'evidence_profile_operation_unique');
            $table->index(['status', 'expires_at']);
        });
        Schema::create('evidence_authorization_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->constrained('evidence_authorizations')->restrictOnDelete();
            $table->uuid('nonce')->unique();
            $table->string('token_digest', 64)->unique();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique('authorization_id');
        });
        Schema::create('evidence_authorization_audit', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->nullable()->constrained('evidence_authorizations')->restrictOnDelete();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('profile', 96)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->string('reason_code', 64)->nullable();
            $table->string('previous_digest', 64)->nullable();
            $table->uuid('event_nonce')->unique();
            $table->timestamp('occurred_at');
            $table->longText('canonical_payload');
            $table->string('event_digest', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
        $this->createCredentialLifecycleTriggers();
    }

    public function down(): void
    {
        $this->dropCredentialLifecycleTriggers();
        Schema::dropIfExists('evidence_authorization_audit');
        Schema::dropIfExists('evidence_authorization_claims');
        Schema::dropIfExists('evidence_authorizations');
        Schema::dropIfExists('evidence_profile_reviewers');
        Schema::dropIfExists('evidence_requester_keys');
    }

    private function createCredentialLifecycleTriggers(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER evidence_lifecycle_audit_digest AFTER INSERT ON evidence_authorization_audit WHEN NEW.action='credential_revoked' AND NEW.reason_code='key_lifecycle' BEGIN UPDATE evidence_authorization_audit SET event_nonce=json_extract(NEW.canonical_payload,'$.event_nonce'),event_digest=sha256(NEW.canonical_payload) WHERE id=NEW.id; END");
            $audit = "INSERT INTO evidence_authorization_audit (authorization_id,company_id,profile,action,reason_code,previous_digest,event_nonce,occurred_at,canonical_payload,event_digest,created_at) SELECT q.id,q.company_id,q.profile,'credential_revoked','key_lifecycle',q.previous_digest,q.event_nonce,q.occurred_at,q.payload,q.event_nonce,q.occurred_at FROM (SELECT p.*,json_object('action','credential_revoked','actor_user_id',NULL,'authorization_id',p.id,'company_id',p.company_id,'detail_digest',p.request_digest,'event_nonce',p.event_nonce,'occurred_at',p.occurred_at,'previous_digest',p.previous_digest,'profile',p.profile) AS payload FROM (SELECT a.id,a.company_id,a.profile,a.request_digest,lower(hex(randomblob(16))) AS event_nonce,strftime('%Y-%m-%dT%H:%M:%fZ','now') AS occurred_at,(SELECT event_digest FROM evidence_authorization_audit x WHERE x.authorization_id=a.id ORDER BY id DESC LIMIT 1) AS previous_digest FROM evidence_authorizations a WHERE a.requester_key_id=OLD.key_id AND a.consumed_at IS NULL AND a.revoked_at IS NULL) p) q;";
            $updates = "UPDATE evidence_authorizations SET status='revoked', revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE requester_key_id=OLD.key_id AND consumed_at IS NULL; UPDATE evidence_authorization_claims SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP), updated_at=CURRENT_TIMESTAMP WHERE consumed_at IS NULL AND authorization_id IN (SELECT id FROM evidence_authorizations WHERE requester_key_id=OLD.key_id);";
            DB::unprepared("CREATE TRIGGER evidence_key_lifecycle_update AFTER UPDATE ON evidence_requester_keys WHEN OLD.active <> NEW.active OR OLD.key_id <> NEW.key_id OR OLD.token_digest <> NEW.token_digest OR COALESCE(OLD.expires_at, '') <> COALESCE(NEW.expires_at, '') BEGIN $audit $updates END");
            DB::unprepared("CREATE TRIGGER evidence_key_lifecycle_delete BEFORE DELETE ON evidence_requester_keys BEGIN $audit $updates END");

            return;
        }
        DB::unprepared("CREATE TRIGGER evidence_lifecycle_audit_digest BEFORE INSERT ON evidence_authorization_audit FOR EACH ROW BEGIN IF NEW.action='credential_revoked' AND NEW.reason_code='key_lifecycle' THEN SET NEW.event_nonce=JSON_UNQUOTE(JSON_EXTRACT(NEW.canonical_payload,'$.event_nonce')); SET NEW.event_digest=SHA2(CAST(NEW.canonical_payload AS CHAR),256); END IF; END");
        $audit = "INSERT INTO evidence_authorization_audit (authorization_id,company_id,profile,action,reason_code,previous_digest,event_nonce,occurred_at,canonical_payload,event_digest,created_at) SELECT q.id,q.company_id,q.profile,'credential_revoked','key_lifecycle',q.previous_digest,q.event_nonce,q.db_at,q.payload,SHA2(q.payload,256),q.db_at FROM (SELECT p.*,CONCAT('{\"action\":\"credential_revoked\",\"actor_user_id\":null,\"authorization_id\":',p.id,',\"company_id\":',p.company_id,',\"detail_digest\":\"',p.request_digest,'\",\"event_nonce\":\"',p.event_nonce,'\",\"occurred_at\":\"',p.iso_at,'\",\"previous_digest\":',IF(p.previous_digest IS NULL,'null',CONCAT('\"',p.previous_digest,'\"')),',\"profile\":\"',p.profile,'\"}') AS payload FROM (SELECT a.id,a.company_id,a.profile,a.request_digest,UUID() AS event_nonce,UTC_TIMESTAMP() AS db_at,DATE_FORMAT(UTC_TIMESTAMP(3),'%Y-%m-%dT%H:%i:%s.%fZ') AS iso_at,(SELECT event_digest FROM evidence_authorization_audit x WHERE x.authorization_id=a.id ORDER BY id DESC LIMIT 1) AS previous_digest FROM evidence_authorizations a WHERE a.requester_key_id=OLD.key_id AND a.consumed_at IS NULL AND a.revoked_at IS NULL) p) q;";
        $updates = "UPDATE evidence_authorizations SET status='revoked', revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()), updated_at=UTC_TIMESTAMP() WHERE requester_key_id=OLD.key_id AND consumed_at IS NULL; UPDATE evidence_authorization_claims SET revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP()), updated_at=UTC_TIMESTAMP() WHERE consumed_at IS NULL AND authorization_id IN (SELECT id FROM evidence_authorizations WHERE requester_key_id=OLD.key_id);";
        DB::unprepared("CREATE TRIGGER evidence_key_lifecycle_update AFTER UPDATE ON evidence_requester_keys FOR EACH ROW BEGIN IF OLD.active <> NEW.active OR OLD.key_id <> NEW.key_id OR OLD.token_digest <> NEW.token_digest OR NOT (OLD.expires_at <=> NEW.expires_at) THEN $audit $updates END IF; END");
        DB::unprepared("CREATE TRIGGER evidence_key_lifecycle_delete BEFORE DELETE ON evidence_requester_keys FOR EACH ROW BEGIN $audit $updates END");
    }

    private function dropCredentialLifecycleTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS evidence_key_lifecycle_update');
        DB::unprepared('DROP TRIGGER IF EXISTS evidence_key_lifecycle_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS evidence_lifecycle_audit_digest');
    }
};
