<?php

namespace Tests\Feature\Panel;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * Channel-callback semantics for `private-terminal.{project}`.
 *
 * Laravel's broadcasting auth flow is layered: web middleware ↦ guard
 * resolution ↦ channel callback. The channel callback itself is the
 * piece this story owns, so we exercise it directly via the
 * Broadcaster instance rather than going through `/broadcasting/auth`
 * (which short-circuits with a 200 in the testing harness when no
 * guard is configured for the test environment).
 */
class TerminalChannelAuthTest extends TestCase
{
    public function test_callback_rejects_in_trusted_network_bypass_mode(): void
    {
        config([
            'panel.auth_enabled' => false,
            'panel.dev_bypass' => true,
        ]);

        $result = $this->invokeChannelCallback('terminal.desakta', user: null);

        $this->assertFalse($result, 'Channel must reject in trusted-network bypass mode.');
    }

    public function test_callback_rejects_when_panel_auth_session_missing(): void
    {
        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
        ]);

        // No panel_auth in session → callback returns false
        session()->forget('panel_auth');

        $result = $this->invokeChannelCallback('terminal.desakta', user: null);

        $this->assertFalse($result, 'Channel must reject without an active panel_auth session.');
    }

    public function test_callback_accepts_with_active_panel_auth_session(): void
    {
        config([
            'panel.auth_enabled' => true,
            'panel.dev_bypass' => false,
        ]);

        session(['panel_auth' => true, 'panel_auth_time' => now()->timestamp]);

        $result = $this->invokeChannelCallback('terminal.desakta', user: null);

        $this->assertIsArray($result);
        $this->assertSame('panel', $result['user']);
        $this->assertSame('desakta', $result['project']);
    }

    /**
     * Resolve the channel callback registered for a given private channel
     * name and invoke it with the supplied user. Returns whatever the
     * callback returns (false, true, or a presence-style array).
     */
    private function invokeChannelCallback(string $channelName, ?object $user): mixed
    {
        /** @var Broadcaster $broadcaster */
        $broadcaster = Broadcast::driver();

        $reflection = new \ReflectionClass($broadcaster);

        // Laravel stores callbacks in $channels (pattern → callback,
        // patterns like 'terminal.{project}').
        $property = $reflection->getProperty('channels');
        $property->setAccessible(true);
        $channels = $property->getValue($broadcaster);

        foreach ($channels as $pattern => $callback) {
            // Hand-rolled pattern matcher: escape literal dots, then
            // turn each {placeholder} into a non-greedy capture.
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
