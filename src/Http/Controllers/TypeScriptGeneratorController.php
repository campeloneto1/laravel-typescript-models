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

        $typescript = $this->service->generate();

        return response($typescript, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="models.d.ts"')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
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
}
