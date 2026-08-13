<?php

namespace Tests\Feature;

use App\Identity\IdentityException;
use App\Identity\IdentityService;
use App\Identity\OidcConfig;
use App\Identity\OidcIdentity;
use App\Identity\StubOidcClient;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OidcIdentityTest extends TestCase
{
    use RefreshDatabase;

    private StubOidcClient $oidc;

    private IdentityService $identity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oidc = new StubOidcClient;
        $config = new OidcConfig(
            enabled: true,
            issuer: 'https://idp.fynix.test/realms/hq',
            clientId: 'cyber-audit',
            clientSecret: 'secret',
            redirectUri: 'https://audit.test/auth/sso/callback',
            authorizeEndpoint: 'https://idp.fynix.test/authorize',
            tokenEndpoint: 'https://idp.fynix.test/token',
            scopes: ['openid', 'email', 'profile'],
            autoProvision: true,
            enforceSsoOnly: false,
            defaultRole: 'Regular User',
        );

        $this->app->instance(StubOidcClient::class, $this->oidc);
        $this->app->instance(OidcConfig::class, $config);
        $this->identity = new IdentityService($this->oidc, $config);
        $this->app->instance(IdentityService::class, $this->identity);
    }

    public function test_sso_login_redirects_to_idp_with_state(): void
    {
        $response = $this->get(route('auth.sso.login'));

        $response->assertRedirect();
        $this->assertStringContainsString('https://stub-idp.test/authorize', $response->headers->get('Location'));
        $this->assertNotEmpty(session('oidc.state'));
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $this->withSession(['oidc.state' => 'expected-state'])
            ->get(route('auth.sso.callback', ['code' => 'x', 'state' => 'wrong']))
            ->assertRedirect(route('filament.app.auth.login'));

        $this->assertGuest();
        $this->assertSame(
            IdentityException::invalidState()->getMessage(),
            session('oidc_error')
        );
    }

    public function test_matches_existing_user_by_sso_subject(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'is_sso' => true,
            'sso_subject' => 'sub-1',
            'sso_issuer' => 'https://idp.fynix.test/realms/hq',
            'password_reset_required' => false,
        ]);

        $result = $this->identity->complete(new OidcIdentity(
            subject: 'sub-1',
            email: 'new-address@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: true,
        ));

        $this->assertFalse($result->newlyProvisioned);
        $this->assertSame($user->id, $result->user->id);
        $this->assertSame('old@example.com', $result->user->email);
    }

    public function test_unverified_email_cannot_provision_or_link(): void
    {
        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage(IdentityException::emailUnverified()->getMessage());

        $this->identity->complete(new OidcIdentity(
            subject: 'sub-new',
            email: 'fresh@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: false,
        ));
    }

    public function test_local_password_account_is_not_adopted_by_email(): void
    {
        User::factory()->create([
            'email' => 'local@example.com',
            'is_sso' => false,
        ]);

        $this->expectException(IdentityException::class);
        $this->expectExceptionMessage(IdentityException::localAccountExists()->getMessage());

        $this->identity->complete(new OidcIdentity(
            subject: 'sub-attacker',
            email: 'local@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: true,
        ));
    }

    public function test_existing_sso_account_can_be_linked_by_verified_email(): void
    {
        $user = User::factory()->create([
            'email' => 'sso@example.com',
            'is_sso' => true,
            'sso_subject' => 'old-sub',
        ]);

        $result = $this->identity->complete(new OidcIdentity(
            subject: 'new-sub',
            email: 'sso@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: true,
        ));

        $this->assertSame($user->id, $result->user->id);
        $this->assertSame('new-sub', $result->user->sso_subject);
    }

    public function test_new_verified_user_is_provisioned(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $result = $this->identity->complete(new OidcIdentity(
            subject: 'sub-new',
            email: 'fresh@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: true,
            name: 'Fresh User',
        ));

        $this->assertTrue($result->newlyProvisioned);
        $this->assertDatabaseHas('users', [
            'email' => 'fresh@example.com',
            'is_sso' => true,
            'sso_subject' => 'sub-new',
        ]);
        $this->assertTrue($result->user->hasRole('Regular User'));
    }

    public function test_signed_callback_logs_the_user_in(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->oidc->register('good-code', new OidcIdentity(
            subject: 'sub-login',
            email: 'hq@example.com',
            issuer: 'https://idp.fynix.test/realms/hq',
            emailVerified: true,
            name: 'HQ User',
        ));

        $this->withSession([
            'oidc.state' => 'state-1',
            'oidc.redirect' => 'https://audit.test/auth/sso/callback',
        ])->get(route('auth.sso.callback', [
            'code' => 'good-code',
            'state' => 'state-1',
        ]))->assertRedirect('/app');

        $this->assertAuthenticated();
        $this->assertSame('hq@example.com', Auth::user()->email);
        $this->assertTrue(Auth::user()->is_sso);
    }

    public function test_sso_user_cannot_use_local_password(): void
    {
        $user = User::factory()->create([
            'is_sso' => true,
            'is_break_glass' => false,
        ]);

        $this->expectException(IdentityException::class);
        $this->identity->assertLocalPasswordAllowed($user);
    }

    public function test_break_glass_can_use_local_password_when_sso_only(): void
    {
        $config = new OidcConfig(
            enabled: true,
            issuer: 'https://idp.fynix.test/realms/hq',
            clientId: 'cyber-audit',
            clientSecret: 'secret',
            redirectUri: 'https://audit.test/auth/sso/callback',
            authorizeEndpoint: null,
            tokenEndpoint: null,
            scopes: ['openid'],
            autoProvision: true,
            enforceSsoOnly: true,
            defaultRole: null,
        );
        $service = new IdentityService($this->oidc, $config);

        $breakGlass = User::factory()->create([
            'is_sso' => false,
            'is_break_glass' => true,
        ]);
        $ordinary = User::factory()->create([
            'is_sso' => false,
            'is_break_glass' => false,
        ]);

        $service->assertLocalPasswordAllowed($breakGlass);

        $this->expectException(IdentityException::class);
        $service->assertLocalPasswordAllowed($ordinary);
    }

    public function test_id_token_rejects_wrong_issuer_or_audience(): void
    {
        $client = new \App\Identity\RealOidcClient(
            issuer: 'https://idp.fynix.test/realms/hq',
            clientId: 'cyber-audit',
            clientSecret: 'secret',
            authorizeEndpoint: 'https://idp.fynix.test/authorize',
            tokenEndpoint: 'https://idp.fynix.test/token',
        );

        $token = $this->unsignedJwt([
            'iss' => 'https://evil.test',
            'aud' => 'cyber-audit',
            'sub' => 'sub-1',
            'email' => 'a@example.com',
        ]);

        $this->expectException(IdentityException::class);
        $client->identityFromIdToken($token);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function unsignedJwt(array $claims): string
    {
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'none']).'.'.$encode($claims).'.';
    }
}
