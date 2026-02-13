<?php

namespace Campelo\LaravelTypescriptModels\Http\Controllers;

use Campelo\LaravelTypescriptModels\Services\ModelToTypeScriptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use ZipArchive;

class TypeScriptGeneratorController extends Controller
{
    public function __construct(
        protected ModelToTypeScriptService $service
    ) {}

    /**
     * Generate TypeScript interfaces from Laravel models.
     */
    public function __invoke(Request $request): Response
    {
        if (!config('typescript-models.enabled')) {
            abort(404, 'TypeScript models endpoint is disabled.');
        }

        if (!$this->validateIp($request)) {
            abort(403, 'Access denied: IP not allowed.');
        }

        if (!$this->validateToken($request)) {
            abort(401, 'Access denied: Invalid or missing token.');
        }

        // Apply query parameter overrides
        $this->applyQueryOverrides($request);

        // Check if split mode is enabled
        $splitMode = $request->query('split');

        if ($splitMode && in_array($splitMode, ['subdirectory', 'class'])) {
            return $this->generateZipResponse($splitMode);
        }

        $typescript = $this->service->generate();

        return response($typescript, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="models.d.ts"')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Generate a ZIP file with split TypeScript files.
     */
    protected function generateZipResponse(string $mode): Response
    {
        $files = $this->service->generateSplitByDomain($mode);

        $zipPath = tempnam(sys_get_temp_dir(), 'typescript_') . '.zip';
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Failed to create ZIP file.');
        }

        foreach ($files as $filename => $content) {
            $zip->addFromString("typescript/{$filename}", $content);
        }

        $zip->close();

        $zipContent = file_get_contents($zipPath);
        unlink($zipPath);

        return response($zipContent, 200)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="typescript-models.zip"')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Show the configurator page.
     */
    public function configurator(Request $request): Response
    {
        if (!config('typescript-models.enabled')) {
            abort(404, 'TypeScript models endpoint is disabled.');
        }

        if (!$this->validateIp($request)) {
            abort(403, 'Access denied: IP not allowed.');
        }

        if (!$this->validateToken($request)) {
            abort(401, 'Access denied: Invalid or missing token.');
        }

        $html = $this->getConfiguratorHtml();

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Apply query parameter overrides to config.
     */
    protected function applyQueryOverrides(Request $request): void
    {
        // Models
        if ($request->has('models')) {
            config(['typescript-models.include_models' => $request->query('models') === '1']);
        }

        // Resources
        if ($request->has('resources')) {
            config(['typescript-models.include_resources' => $request->query('resources') === '1']);
        }

        // Requests
        if ($request->has('requests')) {
            config(['typescript-models.include_requests' => $request->query('requests') === '1']);
        }

        // Yup schemas
        if ($request->has('yup')) {
            config(['typescript-models.generate_yup_schemas' => $request->query('yup') === '1']);
        }

        // Zod schemas
        if ($request->has('zod')) {
            config(['typescript-models.generate_zod_schemas' => $request->query('zod') === '1']);
        }

        // Relations
        if ($request->has('relations')) {
            config(['typescript-models.include_relations' => $request->query('relations') === '1']);
        }

        // Accessors
        if ($request->has('accessors')) {
            config(['typescript-models.include_accessors' => $request->query('accessors') === '1']);
        }
    }

    /**
     * Validate the request IP against allowed IPs.
     */
    protected function validateIp(Request $request): bool
    {
        $allowedIps = config('typescript-models.allowed_ips', []);

        if (empty($allowedIps)) {
            return true;
        }

        return in_array($request->ip(), $allowedIps);
    }

    /**
     * Validate the authentication token.
     */
    protected function validateToken(Request $request): bool
    {
        if (!config('typescript-models.require_token')) {
            return true;
        }

        $configToken = config('typescript-models.token');

        if (empty($configToken)) {
            return false;
        }

        $requestToken = $request->header('X-TypeScript-Token')
            ?? $request->header('Authorization')
            ?? $request->query('token');

        if (str_starts_with($requestToken ?? '', 'Bearer ')) {
            $requestToken = substr($requestToken, 7);
        }

        return hash_equals($configToken, $requestToken ?? '');
    }

    /**
     * Get the configurator HTML page.
     */
    protected function getConfiguratorHtml(): string
    {
        $route = config('typescript-models.route', '/api/typescript-models');
        $token = request()->query('token', '');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TypeScript Generator - Configurator</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f0f1a;
            --bg-secondary: #1a1a2e;
            --bg-card: rgba(255, 255, 255, 0.03);
            --bg-card-hover: rgba(255, 255, 255, 0.06);
            --border-color: rgba(255, 255, 255, 0.08);
            --border-active: rgba(99, 179, 237, 0.5);
            --text-primary: #f0f0f0;
            --text-secondary: #a0a0a0;
            --text-muted: #666;
            --accent-blue: #63b3ed;
            --accent-purple: #9f7aea;
            --accent-green: #48bb78;
            --accent-orange: #ed8936;
            --gradient-primary: linear-gradient(135deg, #63b3ed 0%, #9f7aea 100%);
            --gradient-bg: linear-gradient(180deg, #0f0f1a 0%, #1a1a2e 100%);
            --shadow-glow: 0 0 40px rgba(99, 179, 237, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            text-align: center;
            padding: 3rem 0;
            position: relative;
        }

        .header::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(99, 179, 237, 0.1) 0%, transparent 70%);
            pointer-events: none;
        }

        .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: var(--gradient-primary);
            border-radius: 20px;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-glow);
        }

        .logo svg {
            width: 48px;
            height: 48px;
            fill: white;
        }

        h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .subtitle {
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .card:hover {
            border-color: var(--border-active);
            box-shadow: var(--shadow-glow);
        }

        .card.full-width {
            grid-column: 1 / -1;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .card-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .card-icon.blue { background: rgba(99, 179, 237, 0.15); }
        .card-icon.purple { background: rgba(159, 122, 234, 0.15); }
        .card-icon.green { background: rgba(72, 187, 120, 0.15); }
        .card-icon.orange { background: rgba(237, 137, 54, 0.15); }

        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .options {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .option {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem 1rem;
            background: var(--bg-secondary);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .option:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-active);
        }

        .option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .checkbox-wrapper {
            position: relative;
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        .checkbox-wrapper input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .checkbox-wrapper input:disabled {
            cursor: not-allowed;
        }

        .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            width: 22px;
            height: 22px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 6px;
            transition: all 0.2s ease;
        }

        .checkbox-wrapper input:checked ~ .checkmark {
            background: var(--gradient-primary);
            border-color: transparent;
        }

        .checkmark::after {
            content: '';
            position: absolute;
            display: none;
            left: 7px;
            top: 3px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .checkbox-wrapper input:checked ~ .checkmark::after {
            display: block;
        }

        .option-content {
            flex: 1;
        }

        .option-label {
            font-weight: 500;
            font-size: 0.95rem;
            color: var(--text-primary);
            display: block;
        }

        .option-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        /* Radio buttons for split mode */
        .radio-group {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem 1rem;
            background: var(--bg-secondary);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }

        .radio-option:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-active);
        }

        .radio-option.active {
            border-color: var(--accent-blue);
            background: rgba(99, 179, 237, 0.08);
        }

        .radio-wrapper {
            position: relative;
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        .radio-wrapper input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .radio-mark {
            position: absolute;
            top: 0;
            left: 0;
            width: 22px;
            height: 22px;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: 50%;
            transition: all 0.2s ease;
        }

        .radio-wrapper input:checked ~ .radio-mark {
            border-color: var(--accent-blue);
        }

        .radio-mark::after {
            content: '';
            position: absolute;
            display: none;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 10px;
            height: 10px;
            background: var(--gradient-primary);
            border-radius: 50%;
        }

        .radio-wrapper input:checked ~ .radio-mark::after {
            display: block;
        }

        /* URL Display */
        .url-section {
            margin-top: 1.5rem;
        }

        .url-label {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .url-display {
            background: var(--bg-primary);
            border-radius: 10px;
            padding: 1rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            color: var(--accent-blue);
            word-break: break-all;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .copy-btn {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.4rem 0.75rem;
            color: var(--text-secondary);
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .copy-btn:hover {
            background: var(--bg-card-hover);
            color: var(--text-primary);
        }

        /* Buttons */
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            flex: 1;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-family: 'Inter', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 4px 15px rgba(99, 179, 237, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(99, 179, 237, 0.4);
        }

        .btn-secondary {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--bg-card-hover);
            border-color: var(--border-active);
        }

        .btn-icon {
            font-size: 1.2rem;
        }

        .download-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.75rem;
            justify-content: center;
        }

        /* Preview Section */
        .preview {
            margin-top: 2rem;
            display: none;
        }

        .preview.active {
            display: block;
        }

        .preview-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .preview h2 {
            font-size: 1.1rem;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .preview-tabs {
            display: flex;
            gap: 0.5rem;
        }

        .preview-tab {
            padding: 0.4rem 0.8rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 6px;
            color: var(--text-secondary);
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .preview-tab:hover, .preview-tab.active {
            background: var(--bg-card-hover);
            color: var(--text-primary);
            border-color: var(--border-active);
        }

        .preview-content {
            background: var(--bg-primary);
            border-radius: 12px;
            padding: 1.25rem;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            max-height: 500px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }

        .preview-content pre {
            margin: 0;
            white-space: pre-wrap;
            color: var(--text-secondary);
        }

        .preview-content .keyword { color: #c792ea; }
        .preview-content .type { color: #82aaff; }
        .preview-content .string { color: #c3e88d; }

        /* Loading */
        .loading {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .spinner {
            display: inline-block;
            width: 24px;
            height: 24px;
            border: 2px solid var(--border-color);
            border-radius: 50%;
            border-top-color: var(--accent-blue);
            animation: spin 0.8s linear infinite;
            margin-bottom: 0.75rem;
        }

        /* File list for split mode */
        .file-list {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-primary);
            border-radius: 10px;
            border: 1px solid var(--border-color);
            display: none;
        }

        .file-list.active {
            display: block;
        }

        .file-list-title {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.75rem;
        }

        .file-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.8rem;
            color: var(--accent-green);
        }

        /* Badge */
        .badge {
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-blue { background: rgba(99, 179, 237, 0.15); color: var(--accent-blue); }
        .badge-green { background: rgba(72, 187, 120, 0.15); color: var(--accent-green); }
    </style>
</head>
<body>
    <div class="container">
        <header class="header">
            <div class="logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 3h18v18H3V3zm16.525 13.707c-.131-.821-.666-1.511-2.252-2.155-.552-.259-.872-.455-.872-.937 0-.354.225-.821.928-.821.664 0 1.144.267 1.5.543l.55-.851c-.5-.389-1.2-.653-2.009-.653-1.573 0-2.536.884-2.536 2.055 0 1.01.644 1.626 2.118 2.247.584.247.908.455.908.936 0 .389-.299.892-1.053.892-.72 0-1.329-.308-1.78-.694l-.584.883c.533.461 1.322.779 2.309.779 1.764 0 2.756-.941 2.756-2.224h.017zm-7.164 1.536l1.582-7.993H12.2l-1.007 5.089-1.178-5.089H8.391l-1.178 5.089L6.206 10.25H4.464l1.582 7.993h1.723l1.203-5.129 1.203 5.129h1.186z"/>
                </svg>
            </div>
            <h1>TypeScript Generator</h1>
            <p class="subtitle">Configure and download your TypeScript definitions</p>
        </header>

        <div class="grid">
            <!-- Interfaces Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon blue">📦</div>
                    <h2>Interfaces</h2>
                </div>
                <div class="options">
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="models">
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Models</span>
                            <span class="option-desc">Eloquent model interfaces</span>
                        </div>
                    </label>
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="resources" checked>
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Resources</span>
                            <span class="option-desc">API Resource interfaces</span>
                        </div>
                    </label>
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="requests" checked>
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Form Requests</span>
                            <span class="option-desc">Request validation interfaces</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Model Options Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon purple">⚙️</div>
                    <h2>Model Options</h2>
                </div>
                <div class="options">
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="relations" checked>
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Relations</span>
                            <span class="option-desc">Include model relationships</span>
                        </div>
                    </label>
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="accessors">
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Accessors</span>
                            <span class="option-desc">Include computed attributes</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Validation Schemas Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon green">✅</div>
                    <h2>Validation Schemas</h2>
                </div>
                <div class="options">
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="yup" checked>
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Yup Schemas</span>
                            <span class="option-desc">Generate Yup validation</span>
                        </div>
                    </label>
                    <label class="option">
                        <div class="checkbox-wrapper">
                            <input type="checkbox" id="zod">
                            <span class="checkmark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Zod Schemas</span>
                            <span class="option-desc">Generate Zod validation</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Output Format Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-icon orange">📁</div>
                    <h2>Output Format</h2>
                </div>
                <div class="radio-group">
                    <label class="radio-option active" data-value="">
                        <div class="radio-wrapper">
                            <input type="radio" name="split" value="" checked>
                            <span class="radio-mark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Single File <span class="badge badge-blue">.d.ts</span></span>
                            <span class="option-desc">All interfaces in one file</span>
                        </div>
                    </label>
                    <label class="radio-option" data-value="subdirectory">
                        <div class="radio-wrapper">
                            <input type="radio" name="split" value="subdirectory">
                            <span class="radio-mark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Split by Folder <span class="badge badge-green">.zip</span></span>
                            <span class="option-desc">Grouped by subdirectory structure</span>
                        </div>
                    </label>
                    <label class="radio-option" data-value="class">
                        <div class="radio-wrapper">
                            <input type="radio" name="split" value="class">
                            <span class="radio-mark"></span>
                        </div>
                        <div class="option-content">
                            <span class="option-label">Split by Entity <span class="badge badge-green">.zip</span></span>
                            <span class="option-desc">Grouped by entity name (User, Product...)</span>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- URL Section -->
        <div class="url-section">
            <div class="url-label">
                <span>🔗</span> Generated URL
            </div>
            <div class="url-display">
                <span id="urlDisplay"></span>
                <button class="copy-btn" onclick="copyUrl()">Copy</button>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="btn-group">
            <button class="btn btn-secondary" onclick="preview()">
                <span class="btn-icon">👁️</span>
                Preview
            </button>
            <button class="btn btn-primary" onclick="download()">
                <span class="btn-icon">⬇️</span>
                <span id="downloadText">Download .d.ts</span>
            </button>
        </div>
        <div class="download-info" id="downloadInfo">
            <span>📄</span> Single TypeScript definition file
        </div>

        <!-- Preview Section -->
        <div class="preview" id="previewSection">
            <div class="preview-header">
                <h2>
                    <span>📝</span> Preview
                </h2>
            </div>
            <div class="preview-content">
                <pre id="previewContent"></pre>
            </div>
        </div>
    </div>

    <script>
        const baseUrl = '{$route}';
        const token = '{$token}';

        function getSplitMode() {
            const checked = document.querySelector('input[name="split"]:checked');
            return checked ? checked.value : '';
        }

        function buildUrl() {
            const params = new URLSearchParams();

            if (token) params.set('token', token);
            params.set('models', document.getElementById('models').checked ? '1' : '0');
            params.set('resources', document.getElementById('resources').checked ? '1' : '0');
            params.set('requests', document.getElementById('requests').checked ? '1' : '0');
            params.set('relations', document.getElementById('relations').checked ? '1' : '0');
            params.set('accessors', document.getElementById('accessors').checked ? '1' : '0');
            params.set('yup', document.getElementById('yup').checked ? '1' : '0');
            params.set('zod', document.getElementById('zod').checked ? '1' : '0');

            const split = getSplitMode();
            if (split) {
                params.set('split', split);
            }

            return baseUrl + '?' + params.toString();
        }

        function updateUrl() {
            document.getElementById('urlDisplay').textContent = buildUrl();
            updateDownloadButton();
        }

        function updateDownloadButton() {
            const split = getSplitMode();
            const downloadText = document.getElementById('downloadText');
            const downloadInfo = document.getElementById('downloadInfo');

            if (split) {
                downloadText.textContent = 'Download .zip';
                downloadInfo.innerHTML = '<span>📦</span> ZIP archive with multiple TypeScript files';
            } else {
                downloadText.textContent = 'Download .d.ts';
                downloadInfo.innerHTML = '<span>📄</span> Single TypeScript definition file';
            }
        }

        function updateRadioStyles() {
            document.querySelectorAll('.radio-option').forEach(option => {
                const radio = option.querySelector('input[type="radio"]');
                if (radio.checked) {
                    option.classList.add('active');
                } else {
                    option.classList.remove('active');
                }
            });
        }

        function copyUrl() {
            navigator.clipboard.writeText(buildUrl()).then(() => {
                const btn = document.querySelector('.copy-btn');
                const original = btn.textContent;
                btn.textContent = 'Copied!';
                setTimeout(() => btn.textContent = original, 1500);
            });
        }

        function download() {
            window.location.href = buildUrl();
        }

        async function preview() {
            const split = getSplitMode();

            if (split) {
                alert('Preview is only available for single file mode. In split mode, please download the ZIP file.');
                return;
            }

            const previewSection = document.getElementById('previewSection');
            const previewContent = document.getElementById('previewContent');

            previewSection.classList.add('active');
            previewContent.innerHTML = '<div class="loading"><div class="spinner"></div><br>Loading preview...</div>';

            try {
                const response = await fetch(buildUrl());
                const text = await response.text();
                previewContent.textContent = text;
            } catch (error) {
                previewContent.textContent = 'Error loading preview: ' + error.message;
            }
        }

        // Event listeners
        document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', updateUrl);
        });

        document.querySelectorAll('input[name="split"]').forEach(radio => {
            radio.addEventListener('change', () => {
                updateUrl();
                updateRadioStyles();
            });
        });

        // Initial setup
        updateUrl();
        updateRadioStyles();
    </script>
</body>
</html>
HTML;
    }
}
