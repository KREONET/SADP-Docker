<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An application points at a git repository or container image that is not on
 * the administrator's allow-list (Platform Admin → Sources).
 */
class DisallowedSourceException extends Exception
{
    public static function forGitRepository(string $repository): self
    {
        return new self("The git repository '{$repository}' is not an allowed source. Ask a platform administrator to add it to the allowed sources list.");
    }

    public static function forDockerImage(string $image): self
    {
        return new self("The Docker image '{$image}' is not from an allowed registry. Ask a platform administrator to add the registry to the allowed sources list.");
    }

    public function render(Request $request): ?JsonResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return null;
    }
}
