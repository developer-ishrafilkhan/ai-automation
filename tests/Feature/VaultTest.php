<?php

namespace Tests\Feature;

use App\Models\AiCredential;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_keys_are_encrypted_at_rest_and_never_sent_to_the_browser()
    {
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-image-1'], ['id' => 'gpt-5']]])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/keys', ['provider' => 'openai', 'label' => 'Main', 'key' => 'sk-test-secret-1234'])->assertRedirect();

        $credential = AiCredential::first();
        $this->assertStringNotContainsString('sk-test-secret', $credential->encrypted_key);
        $this->assertSame('1234', $credential->key_last4);
        $this->assertSame('valid', $credential->status);
        $this->assertSame(['gpt-5', 'gpt-image-1'], $credential->models);
        $this->assertSame('sk-test-secret-1234', $credential->secret());

        $this->withoutVite();
        $this->actingAs($user)->get('/keys')->assertOk()
            ->assertDontSee('sk-test-secret')
            ->assertDontSee($credential->encrypted_key);
    }

    public function test_a_failing_key_is_stored_as_invalid_with_the_key_redacted()
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad key sk-ant-wrong-key-999']], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)->post('/keys', ['provider' => 'anthropic', 'label' => 'Claude', 'key' => 'sk-ant-wrong-key-999']);

        $credential = AiCredential::first();
        $this->assertSame('invalid', $credential->status);
        $this->assertStringNotContainsString('sk-ant-wrong', $credential->status_message);
    }

    public function test_workspaces_cannot_touch_each_others_credentials()
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/keys', ['provider' => 'openai', 'label' => 'Mine', 'key' => 'sk-owner-key-0000']);
        $credential = AiCredential::first();

        $this->actingAs(User::factory()->create())->delete("/keys/{$credential->id}")->assertNotFound();
        $this->assertSame(1, AiCredential::count());
    }

    public function test_each_workspace_has_its_own_data_key()
    {
        $a = Workspace::createFor(User::factory()->create(), 'A');
        $b = Workspace::createFor(User::factory()->create(), 'B');

        $this->assertNotSame($a->encrypted_data_key, $b->encrypted_data_key);
    }
}
