<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\FileService;
use App\Services\ProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class FileController extends Controller
{
    public function __construct(
        protected FileService $fileService,
        protected ProjectService $projectService,
    ) {}

public function index(Request $request)
    {
        $activeProject = $this->projectService->getActiveProject();

        return view('panel.files', [
            'initialPath' => '/',
            'activeProject' => $activeProject ? [
                'name' => $activeProject['name'],
                'displayName' => $activeProject['display_name'] ?? $activeProject['name'],
                'path' => $activeProject['path'],
            ] : null,
        ]);
    }

    public function listFiles(Request $request)
    {
        $path = $request->get('path', '/');
        
        // Allow project override via query param to bypass session mismatch
        $projectOverride = $request->get('project');
        if ($projectOverride) {
            $this->projectService->switchProject($projectOverride);
        }
        
        $activeProject = $this->projectService->getActiveProject();

        $listing = $this->fileService->listDirectory($path);
        $listing['activeProject'] = $activeProject ? [
            'name' => $activeProject['name'],
            'displayName' => $activeProject['display_name'] ?? $activeProject['name'],
            'path' => $activeProject['path'],
        ] : null;

        return response()->json($listing);
    }

    public function getContext(Request $request)
    {
        $activeProject = $this->projectService->getActiveProject();
        $basePath = base_path(config('panel.projects_dir', 'Project'));

        return response()->json([
            'activeProject' => $activeProject ? [
                'name' => $activeProject['name'],
                'displayName' => $activeProject['display_name'] ?? $activeProject['name'],
                'path' => $activeProject['path'],
            ] : null,
            'basePath' => $basePath,
            'projectsDir' => config('panel.projects_dir', 'Project'),
        ]);
    }

    public function fileContent(Request $request)
    {
        $path = $request->input('path', '');
        $result = $this->fileService->getFileContent($path);

        return response()->json($result);
    }

    public function saveFile(Request $request)
    {
        $path = $request->input('path', '');
        $content = $request->input('content', '');

        return response()->json($this->fileService->saveFile($path, $content));
    }

    public function createFile(Request $request)
    {
        $path = $request->input('path', '/');
        $name = $request->input('name', '');
        $type = $request->input('type', 'file');

        return response()->json($this->fileService->create($path, $name, $type));
    }

    public function renameFile(Request $request)
    {
        $path = $request->input('path', '');
        $newName = $request->input('new_name', '');

        return response()->json($this->fileService->rename($path, $newName));
    }

    public function moveFile(Request $request)
    {
        $source = $request->input('source_path', '');
        $target = $request->input('target_path', '');

        return response()->json($this->fileService->move($source, $target));
    }

    public function copyFile(Request $request)
    {
        $source = $request->input('source_path', '');
        $target = $request->input('target_path', '');

        return response()->json($this->fileService->copy($source, $target));
    }

    public function deleteFile(Request $request)
    {
        $path = $request->input('path', '');

        return response()->json($this->fileService->delete($path));
    }

    public function uploadFile(Request $request)
    {
        $path = $request->input('path', '/');
        $file = $request->file('file');

        if (!$file) {
            return response()->json(['success' => false, 'error' => 'No file uploaded']);
        }

        return response()->json($this->fileService->upload($path, $file));
    }

    public function downloadFile(Request $request)
    {
        $path = $request->get('path', '');
        $result = $this->fileService->download($path);

        if (!$result) {
            return response()->json(['error' => 'File not found'], 404);
        }

        if ($result['is_directory']) {
            $zipPath = $this->fileService->zipDirectory($path);
            if (!$zipPath) {
                return response()->json(['error' => 'Failed to create zip'], 500);
            }

            return Response::download($zipPath, $result['name'] . '.zip')->deleteFileAfterSend(true);
        }

        return Response::download($result['path'], $result['name']);
    }

    public function updatePermissions(Request $request)
    {
        $path = $request->input('path', '');
        $permissions = octdec($request->input('permissions', '644'));

        return response()->json($this->fileService->updatePermissions($path, $permissions));
    }

    public function searchFiles(Request $request)
    {
        $path = $request->get('path', '/');
        $query = $request->get('query', '');
        $recursive = $request->boolean('recursive', false);

        if (empty($query)) {
            return response()->json(['results' => []]);
        }

        return response()->json(['results' => $this->fileService->search($path, $query, $recursive)]);
    }
}
