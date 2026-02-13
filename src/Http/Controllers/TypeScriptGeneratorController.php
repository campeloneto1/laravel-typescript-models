<?php

namespace Campelo\LaravelTypescriptModels\Http\Controllers;

use Campelo\LaravelTypescriptModels\Services\ModelToTypeScriptService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

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

        $typescript = $this->service->generate();

        return response($typescript, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="models.d.ts"')
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
        // Models (always included unless explicitly disabled)
        if ($request->has('models') && $request->query('models') === '0') {
            // Models can't be fully disabled, but we could add logic here if needed
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            color: #e0e0e0;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 0.5rem;
            color: #4fc3f7;
            font-size: 2rem;
        }
        .subtitle {
            text-align: center;
            color: #888;
            margin-bottom: 2rem;
        }
        .card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .card h2 {
            color: #4fc3f7;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        .option {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .option:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(79, 195, 247, 0.3);
        }
        .option input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #4fc3f7;
            cursor: pointer;
        }
        .option label {
            cursor: pointer;
            flex: 1;
        }
        .option .desc {
            font-size: 0.8rem;
            color: #888;
            display: block;
        }
        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .btn {
            flex: 1;
            padding: 1rem 2rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #4fc3f7 0%, #29b6f6 100%);
            color: #1a1a2e;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(79, 195, 247, 0.4);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #e0e0e0;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        .preview {
            margin-top: 2rem;
        }
        .preview h2 {
            color: #4fc3f7;
            margin-bottom: 1rem;
        }
        .preview-content {
            background: #0d1117;
            border-radius: 8px;
            padding: 1rem;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 0.85rem;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .preview-content pre {
            margin: 0;
            white-space: pre-wrap;
        }
        .url-display {
            background: #0d1117;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 0.85rem;
            color: #4fc3f7;
            word-break: break-all;
            margin-top: 1rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .loading {
            text-align: center;
            padding: 2rem;
            color: #888;
        }
        .icon {
            font-size: 1.2rem;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #4fc3f7;
            animation: spin 0.8s linear infinite;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔷 TypeScript Generator</h1>
        <p class="subtitle">Configure and download your TypeScript definitions</p>

        <div class="card">
            <h2>📦 Interfaces</h2>
            <div class="options">
                <div class="option">
                    <input type="checkbox" id="models" checked disabled>
                    <label for="models">
                        Models
                        <span class="desc">Eloquent model interfaces</span>
                    </label>
                </div>
                <div class="option">
                    <input type="checkbox" id="resources" checked>
                    <label for="resources">
                        Resources
                        <span class="desc">API Resource interfaces</span>
                    </label>
                </div>
                <div class="option">
                    <input type="checkbox" id="requests" checked>
                    <label for="requests">
                        Form Requests
                        <span class="desc">Request validation interfaces</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>⚙️ Model Options</h2>
            <div class="options">
                <div class="option">
                    <input type="checkbox" id="relations" checked>
                    <label for="relations">
                        Relations
                        <span class="desc">Include model relationships</span>
                    </label>
                </div>
                <div class="option">
                    <input type="checkbox" id="accessors">
                    <label for="accessors">
                        Accessors
                        <span class="desc">Include computed attributes</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="card">
            <h2>✅ Validation Schemas</h2>
            <div class="options">
                <div class="option">
                    <input type="checkbox" id="yup" checked>
                    <label for="yup">
                        Yup Schemas
                        <span class="desc">Generate Yup validation</span>
                    </label>
                </div>
                <div class="option">
                    <input type="checkbox" id="zod">
                    <label for="zod">
                        Zod Schemas
                        <span class="desc">Generate Zod validation</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="url-display" id="urlDisplay"></div>

        <div class="btn-group">
            <button class="btn btn-secondary" onclick="preview()">
                👁️ Preview
            </button>
            <button class="btn btn-primary" onclick="download()">
                ⬇️ Download .d.ts
            </button>
        </div>

        <div class="preview" id="previewSection" style="display: none;">
            <h2>Preview</h2>
            <div class="preview-content">
                <pre id="previewContent"></pre>
            </div>
        </div>
    </div>

    <script>
        const baseUrl = '{$route}';
        const token = '{$token}';

        function buildUrl() {
            const params = new URLSearchParams();

            if (token) params.set('token', token);
            params.set('resources', document.getElementById('resources').checked ? '1' : '0');
            params.set('requests', document.getElementById('requests').checked ? '1' : '0');
            params.set('relations', document.getElementById('relations').checked ? '1' : '0');
            params.set('accessors', document.getElementById('accessors').checked ? '1' : '0');
            params.set('yup', document.getElementById('yup').checked ? '1' : '0');
            params.set('zod', document.getElementById('zod').checked ? '1' : '0');

            return baseUrl + '?' + params.toString();
        }

        function updateUrl() {
            document.getElementById('urlDisplay').textContent = buildUrl();
        }

        function download() {
            window.location.href = buildUrl();
        }

        async function preview() {
            const previewSection = document.getElementById('previewSection');
            const previewContent = document.getElementById('previewContent');

            previewSection.style.display = 'block';
            previewContent.innerHTML = '<div class="loading"><span class="spinner"></span> Loading preview...</div>';

            try {
                const response = await fetch(buildUrl());
                const text = await response.text();
                previewContent.textContent = text;
            } catch (error) {
                previewContent.textContent = 'Error loading preview: ' + error.message;
            }
        }

        // Add event listeners to all checkboxes
        document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
            checkbox.addEventListener('change', updateUrl);
        });

        // Initial URL update
        updateUrl();
    </script>
</body>
</html>
HTML;
    }
}
