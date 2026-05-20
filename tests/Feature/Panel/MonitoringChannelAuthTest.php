<?php

namespace Tests\Feature\Panel;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Channel-callback semantics for `private-monitoring.host`.
 *
 * Mirrors the v3.1 TerminalChannelAuthTest pattern: invoke the
 * registered callback directly via Broadcaster reflection so we test
 * what the callback actually decides, not what /broadcasting/auth
 * returns under the test harness's lazy guard wiring.
 */
class MonitoringChannelAuthTest extends TestCase
{
    public function test_callback_rejects_in_trusted_network_bypass_mode(): void
    {
        config([
            'panel.auth_enabled' => false,
            'panel.dev_bypass' => true,
        ]);

        $result = $this->invokeChannelCallback('monitoring.host', user: null);

        $this->assertFalse($result, 'monitoring channel must reject in trusted-network bypass mode.');
    }

    public function test_callback_rejects_when_panel_auth_session_missing(): void
    {
        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
        ]);

        session()->forget('panel_auth');

        $result = $this->invokeChannelCallback('monitoring.host', user: null);

        $this->assertFalse($result, 'monitoring channel must reject without an active panel_auth session.');
    }

    public function test_callback_accepts_with_active_panel_auth_session(): void
    {
        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
        ]);

        session(['panel_auth' => true, 'panel_auth_time' => now()->timestamp]);

        $result = $this->invokeChannelCallback('monitoring.host', user: null);

        $this->assertIsArray($result);
        $this->assertSame('panel', $result['user']);
    }

    private function invokeChannelCallback(string $channelName, ?object $user): mixed
    {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();
        $reflection = new \ReflectionClass($broadcaster);
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);
        $channels = $property->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            $regex = '/^'.str_replace('.', '\\.', $pattern).'$/';
            $regex = preg_replace('/\\\\?\{[^}]+\}/', '([^.]+)', $regex);

            if (preg_match($regex, $channelName, $matches)) {
                array_shift($matches);

                return $callback($user, ...$matches);
            }
        }

        $this->fail("No channel callback matched: {$channelName}");
    }
}
