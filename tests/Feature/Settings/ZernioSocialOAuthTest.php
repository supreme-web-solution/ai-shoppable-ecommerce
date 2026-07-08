<?php

namespace Tests\Feature\Settings;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZernioSocialOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_redirect_passes_redirect_url_to_zernio(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
            'app.url' => 'http://127.0.0.1:8000',
        ]);

        [$team, $user] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_team_a',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://zernio.com/api/v1/connect/instagram*' => function ($request) {
                $query = [];
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

                $this->assertSame('prof_team_a', $query['profileId'] ?? null);
                $this->assertSame(
                    'http://localhost:8000/settings/integrations/zernio/instagram/callback',
                    $query['redirect_url'] ?? null,
                );

                return Http::response([
                    'authUrl' => 'https://facebook.com/oauth/dialog?state=test',
                ]);
            },
        ]);

        $response = $this->actingAs($user)
            ->get('http://localhost:8000/settings/integrations/zernio/instagram/redirect?team_id='.$team->id);

        $response->assertRedirect('https://facebook.com/oauth/dialog?state=test');
    }

    public function test_oauth_callback_exchanges_code_and_redirects_to_integrations(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $user] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_team_a',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://zernio.com/api/v1/connect/instagram' => Http::response([
                'account' => [
                    '_id' => 'acc_new',
                    'platform' => 'instagram',
                    'username' => 'new_ig',
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'zernio_oauth_team_id' => $team->id,
                'zernio_oauth_return_origin' => 'http://localhost:8000',
            ])
            ->get('http://localhost:8000/settings/integrations/zernio/instagram/callback?code=oauth_code&state=oauth_state');

        $response->assertRedirect('http://localhost:8000/dashboard');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/connect/instagram') || $request->method() !== 'POST') {
                return false;
            }

            $body = $request->data();

            return ($body['code'] ?? null) === 'oauth_code'
                && ($body['state'] ?? null) === 'oauth_state'
                && ($body['profileId'] ?? null) === 'prof_team_a';
        });
    }

    public function test_oauth_callback_handles_zernio_pre_connected_flow(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $user] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => '6a350f2872105cf78d046c2c',
                    ],
                ],
            ],
        ]);

        Http::fake();

        $response = $this->actingAs($user)
            ->withSession([
                'zernio_oauth_team_id' => $team->id,
                'zernio_oauth_return_origin' => 'http://localhost:8000',
            ])
            ->get('http://localhost:8000/settings/integrations/zernio/instagram/callback?'.http_build_query([
                'connected' => 'instagram',
                'profileId' => '6a350f2872105cf78d046c2c',
                'accountId' => '6a3578fc5f7d1751ab11ca26',
                'username' => 'vickenconcept',
                'connect_token' => '2821039840a1d65855924df2497c070c012d9739442fa94a',
            ]));

        $response->assertRedirect('http://localhost:8000/dashboard');
        Http::assertNothingSent();

        $this->assertDatabaseHas('social_accounts', [
            'team_id' => $team->id,
            'platform' => 'instagram',
            'zernio_account_id' => '6a3578fc5f7d1751ab11ca26',
            'platform_username' => 'vickenconcept',
        ]);
    }

    public function test_oauth_callback_handles_facebook_without_account_id(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        [$team, $user] = $this->createTeamWithOwner();
        $team->update([
            'settings' => [
                'integrations' => [
                    'zernio' => [
                        'profile_id' => 'prof_team_a',
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://zernio.com/api/v1/accounts*' => Http::response([
                'accounts' => [
                    [
                        '_id' => 'acc_fb_page',
                        'platform' => 'facebook',
                        'username' => 'Vickenconcept',
                        'profileId' => 'prof_team_a',
                    ],
                ],
            ]),
        ]);

        $response = $this->actingAs($user)
            ->withSession([
                'zernio_oauth_team_id' => $team->id,
                'zernio_oauth_return_origin' => 'http://localhost:8000',
            ])
            ->get('http://localhost:8000/settings/integrations/zernio/facebook/callback?'.http_build_query([
                'connected' => 'facebook',
                'profileId' => 'prof_team_a',
                'username' => 'Vickenconcept',
            ]));

        $response->assertRedirect('http://localhost:8000/dashboard');

        $this->assertDatabaseHas('social_accounts', [
            'team_id' => $team->id,
            'platform' => 'facebook',
            'zernio_account_id' => 'acc_fb_page',
            'platform_username' => 'Vickenconcept',
        ]);
    }

    public function test_oauth_callback_without_session_shows_error(): void
    {
        config([
            'services.zernio.enabled' => true,
            'services.zernio.api_key' => 'test-zernio-key',
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('http://localhost:8000/settings/integrations/zernio/instagram/callback?code=oauth_code&state=oauth_state');

        $response->assertRedirect('http://localhost:8000/dashboard');
    }

    /**
     * @return array{0: Team, 1: User}
     */
    protected function createTeamWithOwner(): array
    {
        $user = User::factory()->create();
        $team = Team::query()->create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.uniqid(),
            'owner_id' => $user->id,
        ]);
        $team->users()->attach($user->id, ['role' => 'owner']);

        return [$team, $user];
    }
}
