<?php

namespace Tests\Feature\Panel;

use Tests\TestCase;

/**
 * Tests the WhatsApp gateway bypass hardening introduced in MED-6.
 *
 * The X-WA-Sender header is user-controllable, so on a public domain we
 * gate it behind:
 *
 *   1. PANEL_GATEWAY_IPS allowlist (defaults to loopback only when empty)
 *   2. PANEL_GATEWAY_SECRET HMAC signature (only when configured)
 *
 * Each layer must reject the request independently.
 */
class OwnerAccessGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
            'panel.password' => 'unused-in-these-tests',
            'panel.owner_numbers' => ['62895341414271'],
            'panel.gateway_ips' => [],
            'panel.gateway_secret' => '',
        ]);
    }

    public function test_loopback_request_with_known_sender_is_authorised(): void
    {
        // Default config has no gateway_ips; that means loopback only.
        $this->getJson('/panel/api/terminal/state?project=desakta', [
            'X-WA-Sender' => '62895341414271',
        ])->assertOk();
    }

    public function test_request_from_non_loopback_ip_is_rejected_when_allowlist_empty(): void
    {
        $response = $this->call(
            'GET',
            '/panel/api/terminal/state?project=desakta',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '203.0.113.7',
                'HTTP_X_WA_SENDER' => '62895341414271',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response->assertStatus(401);
    }

    public function test_request_from_explicit_allowed_ip_passes(): void
    {
        config(['panel.gateway_ips' => ['203.0.113.7']]);

        $response = $this->call(
            'GET',
            '/panel/api/terminal/state?project=desakta',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '203.0.113.7',
                'HTTP_X_WA_SENDER' => '62895341414271',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response->assertOk();
    }

    public function test_request_from_disallowed_ip_is_rejected_even_when_allowlist_set(): void
    {
        config(['panel.gateway_ips' => ['203.0.113.7']]);

        $response = $this->call(
            'GET',
            '/panel/api/terminal/state?project=desakta',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '203.0.113.99',
                'HTTP_X_WA_SENDER' => '62895341414271',
                'HTTP_ACCEPT' => 'application/json',
            ],
        );

        $response->assertStatus(401);
    }

    public function test_unsigned_request_is_rejected_when_gateway_secret_set(): void
    {
        config(['panel.gateway_secret' => 'topsecret']);

        $this->getJson('/panel/api/terminal/state?project=desakta', [
            'X-WA-Sender' => '62895341414271',
        ])->assertStatus(401);
    }

    public function test_request_with_invalid_signature_is_rejected(): void
    {
        config(['panel.gateway_secret' => 'topsecret']);

        $this->getJson('/panel/api/terminal/state?project=desakta', [
            'X-WA-Sender' => '62895341414271',
            'X-WA-Signature' => str_repeat('0', 64),
        ])->assertStatus(401);
    }

    public function test_request_with_valid_signature_is_authorised(): void
    {
        config(['panel.gateway_secret' => 'topsecret']);

        $sender = '62895341414271';
        $signature = hash_hmac('sha256', $sender, 'topsecret');

        $this->getJson('/panel/api/terminal/state?project=desakta', [
            'X-WA-Sender' => $sender,
            'X-WA-Signature' => $signature,
        ])->assertOk();
    }

    public function test_unknown_sender_number_is_rejected_even_with_valid_signature(): void
    {
        config(['panel.gateway_secret' => 'topsecret']);

        $sender = '62000000000000';     // not in panel.owner_numbers
        $signature = hash_hmac('sha256', $sender, 'topsecret');

        $this->getJson('/panel/api/terminal/state?project=desakta', [
            'X-WA-Sender' => $sender,
            'X-WA-Signature' => $signature,
        ])->assertStatus(401);
    }
}
