<?php

namespace App\Rbac;

use Exception;
use Illuminate\Http\JsonResponse;

/** A refused RBAC operation. Rendered as a safe JSON body with a stable code. */
class RbacDenied extends Exception
{
    /** @param list<string> $permissions */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $permissions = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode];
        if ($this->permissions) {
            $body['permissions'] = $this->permissions;
        }

        return response()->json($body, $this->status);
    }
}
