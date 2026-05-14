<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\ProjectService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
    ) {}

    public function index()
    {
        $activeProject = $this->projectService->getActiveProject();
        $allProjects = $this->projectService->getAllProjects();
        $hiddenProjects = $this->projectService->getHiddenProjects();

        return view('panel.projects', [
            'activeProject' => $activeProject,
            'allProjects' => $allProjects,
            'hiddenProjects' => $hiddenProjects,
        ]);
    }

    public function switchProject(Request $request)
    {
        $name = $request->input('project');

        $success = $this->projectService->switchProject($name ?: null);

        return response()->json(['success' => $success]);
    }

    public function addProject(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'path' => 'required|string',
        ]);

        $success = $this->projectService->addManualProject(
            $request->input('name'),
            $request->input('path')
        );

        return response()->json([
            'success' => $success,
            'error' => $success ? null : 'Project path does not exist or is invalid.',
        ]);
    }

    public function hideProject(Request $request)
    {
        $name = $request->input('name');

        return response()->json([
            'success' => $this->projectService->hideProject($name),
        ]);
    }

    public function unhideProject(Request $request)
    {
        $name = $request->input('name');

        return response()->json([
            'success' => $this->projectService->unhideProject($name),
        ]);
    }

    public function deleteProject(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'confirm_name' => 'required|string',
        ]);

        $name = $request->input('name');
        $confirmName = $request->input('confirm_name');

        if ($name !== $confirmName) {
            return response()->json([
                'success' => false,
                'error' => 'Project name does not match. Deletion cancelled.',
            ]);
        }

        $success = $this->projectService->deleteProject($name);

        if (!$success) {
            return response()->json([
                'success' => false,
                'error' => 'Project not found or cannot be deleted.',
            ]);
        }

        // Clear active project if it was the deleted one
        if (session('active_project') === $name) {
            session()->forget('active_project');
        }

        return response()->json(['success' => true]);
    }

    public function listProjects()
    {
        $projects = $this->projectService->getAllProjects();
        $active = session('active_project');

        return response()->json([
            'projects' => $projects,
            'active_project' => $active,
        ]);
    }
}
