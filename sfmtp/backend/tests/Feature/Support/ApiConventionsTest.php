<?php

namespace Tests\Feature\Support;

use App\Modules\Traceability\Domain\Models\TraceBatch;
use Tests\TestCase;

class ApiConventionsTest extends TestCase
{
    public function test_errors_are_problem_json_with_a_request_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'test-request-0001')->postJson('/api/v1/auth/login', []);

        $this->assertProblem($response, 422, 'validation_failed');
        $response->assertJsonPath('request_id', 'test-request-0001')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('type', 'https://docs.sfmtp.app/errors/validation-failed')
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertHeader('X-Request-Id', 'test-request-0001');

        $this->assertProblem($this->getJson('/api/v1/nothing-here'), 404, 'not_found');
        $this->assertProblem($this->getJson('/api/v1/me'), 401, 'unauthenticated');
    }

    public function test_request_ids_are_generated_when_missing_or_malformed(): void
    {
        $id = $this->withHeader('X-Request-Id', 'bad id with spaces')->getJson('/up')->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id);
    }

    public function test_idempotency_key_replays_the_first_response(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);
        $url = "/api/v1/farms/{$farm->id}/traceability/batches";

        $first = $this->asUser($owner)->withHeader('Idempotency-Key', 'key-00000001')->postJson($url, ['kind' => 'processed']);
        $second = $this->asUser($owner)->withHeader('Idempotency-Key', 'key-00000001')->postJson($url, ['kind' => 'processed']);

        $first->assertCreated();
        $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, $this->inFarm($farm, fn () => TraceBatch::count()));

        $this->assertProblem(
            $this->asUser($owner)->withHeader('Idempotency-Key', 'key-00000001')->postJson($url, ['kind' => 'packaged']),
            422, 'idempotency_key_reused',
        );
    }

    public function test_mobile_clients_must_send_an_idempotency_key(): void
    {
        $farm = $this->farm();
        $owner = $this->ownerOf($farm);
        $url = "/api/v1/farms/{$farm->id}/traceability/batches";

        $this->assertProblem($this->asUser($owner, 'mobile')->postJson($url, ['kind' => 'processed']), 422, 'idempotency_key_required');
        $this->asUser($owner, 'mobile')->withHeader('Idempotency-Key', 'mobile-key-0001')->postJson($url, ['kind' => 'processed'])->assertCreated();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope']);
        }

        $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope']), 429, 'rate_limited');
    }
}
