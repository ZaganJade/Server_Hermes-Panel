<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ProjectService;
use App\Services\TerminalService;
use App\Services\TerminalSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP surface for the terminal.
 *
 * Two execution paths:
 *   - POST /execute       async — spawns a session, returns immediately.
 *                         Output streams via the Reverb private channel
 *                         `terminal.{project}` (story v3.1-04 dispatches
 *                         TerminalOutput from the tick-loop).
 *   - POST /execute-sync  legacy synchronous fallback. Used when the
 *                         panel runs in trusted-network bypass mode
 *                         (no auth, no broadcasting). Returns the full
 *                         output in the response body.
 *
 * The async endpoint is rate-limited at 30/min per IP. The sync endpoint
 * inherits the standard panel session/auth chain only.
 */
class TerminalController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
        protected TerminalSessionService $sessions,
        protected ProjectService $projectService,
    ) {}

    /**
     * Snapshot of the current terminal state for a project: cwd, display
     * path, active session metadata if any, and the last N commands from
     * the per-project history.
     */
    public function state(Request $request): JsonResponse
    {
        $project = $this->resolveProjectName((string) $request->query('project', ''));
        $cwd = $this->terminalService->getCwd();
        $display = $this->terminalService->getDisplayPath($cwd);

        $sessionId = $project ? $this->sessions->activeSessionId($project) : null;
        $session = $sessionId ? $this->sessions->getActive($sessionId) : null;

        return response()->json([
            'cwd' => $cwd,
            'display' => $display,
            'project' => $project,
            'session' => $session?->toArray(),
            'history' => $project ? $this->sessions->history($project) : [],
        ]);
    }

    /**
     * Spawn a new terminal session and return its id. Output streams via
     * the Reverb private channel; the client must already be subscribed.
     */
    public function execute(Request $request): JsonResponse
    {
        $request->validate([
            'project' => 'required|string|max:200',
            'command' => 'required|string|max:4000',
        ]);

        $project = (string) $request->input('project');
        $command = (string) $request->input('command');

        // Conflict: a session is already running for this project.
        if ($existing = $this->sessions->activeSessionId($project)) {
            $existingSession = $this->sessions->getActive($existing);
            if ($existingSession && $existingSession->status !== 'done') {
                return response()->json([
                    'error' => 'Session already running for this project.',
                    'session_id' => $existing,
                ], 409);
            }
        }

        try {
            $session = $this->sessions->spawn(
                project: $project,
                command: $command,
                cwd: $this->terminalService->getCwd(),
                clientIp: $request->ip(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => '[hermes] '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'session_id' => $session->sessionId,
            'started_at' => $session->startedAt,
            'cwd' => $session->cwd,
            'display' => $this->terminalService->getDisplayPath($session->cwd),
        ], 202);
    }

    /**
     * Synchronous legacy execution. Kept so trusted-network bypass mode
     * (real-time broadcasting disabled) still has a working terminal,
     * and so external scripts that don't speak WebSocket can drive it.
     */
    public function executeSync(Request $request): JsonResponse
    {
        $command = (string) $request->input('command', '');

        $result = $this->terminalService->execute($command);
        $result['display'] = $this->terminalService->getDisplayPath($result['cwd']);

        return response()->json($result);
    }

    /**
     * Force-stop a running session (SIGTERM, then SIGKILL after grace).
     */
    public function stop(string $session): JsonResponse
    {
        $stopped = $this->sessions->stop($session);

        return response()->json([
            'success' => $stopped,
            'session_id' => $session,
            'status' => 'exiting',
        ]);
    }

    /**
     * Replay buffered chunks for a session. Used on page reload to rehydrate
     * the xterm view without losing what already happened.
     */
    public function replay(string $session): JsonResponse
    {
        $payload = $this->sessions->replay($session);

        return response()->json($payload);
    }

    /**
     * Reset terminal cwd to active project / panel root. Also clears any
     * lingering session for this project.
     */
    public function reset(Request $request): JsonResponse
    {
        $project = $this->resolveProjectName((string) $request->input('project', ''));
        $cwd = $this->terminalService->resetCwd();

        if ($project && $existing = $this->sessions->activeSessionId($project)) {
            $this->sessions->stop($existing);
        }

        return response()->json([
            'cwd' => $cwd,
            'display' => $this->terminalService->getDisplayPath($cwd),
            'project' => $project,
        ]);
    }

    /**
     * Clear per-project command history.
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $project = $this->resolveProjectName((string) $request->input('project', ''));

        if (! $project) {
            return response()->json(['error' => 'project is required'], 422);
        }

        $this->sessions->clearHistory($project);

        return response()->json(['success' => true, 'project' => $project]);
    }

    /**
     * Falls back to the active project name when caller didn't pass one.
     */
    protected function resolveProjectName(string $supplied): ?string
    {
        $supplied = trim($supplied);

        if ($supplied !== '') {
            return $supplied;
        }

        $active = $this->projectService->getActiveProject();

        return $active['name'] ?? $active['folder'] ?? null;
    }
}
