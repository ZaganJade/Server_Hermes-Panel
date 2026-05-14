<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\TerminalService;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    public function __construct(
        protected TerminalService $terminalService,
    ) {}

    /**
     * Get current terminal state (cwd + display path).
     */
    public function state()
    {
        $cwd = $this->terminalService->getCwd();

        return response()->json([
            'cwd' => $cwd,
            'display' => $this->terminalService->getDisplayPath($cwd),
        ]);
    }

    /**
     * Execute a command in the current terminal session.
     */
    public function execute(Request $request)
    {
        $command = (string) $request->input('command', '');

        $result = $this->terminalService->execute($command);
        $result['display'] = $this->terminalService->getDisplayPath($result['cwd']);

        return response()->json($result);
    }

    /**
     * Reset terminal cwd to active project / panel root.
     */
    public function reset()
    {
        $cwd = $this->terminalService->resetCwd();

        return response()->json([
            'cwd' => $cwd,
            'display' => $this->terminalService->getDisplayPath($cwd),
        ]);
    }
}
