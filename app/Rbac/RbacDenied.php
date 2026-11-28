<?php

namespace App\Rbac;

use Exception;
use Illuminate\Http\JsonResponse;

/** A refused RBAC operation. Rendered as a safe JSON body with a stable code. */
class RbacDenied extends Exception
{
    /** @param list<string> $permissions @param array<string, string> $meta Extra safe scalar hints (for example earliest_start) merged into the body. */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $permissions = [], public readonly array $meta = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode];
        if ($this->permissions) {
            $body['permissions'] = $this->permissions;
        }

        foreach ($this->meta as $k => $v) {
            $body[$k] = $v;
        }

        return response()->json($body, $this->status);
    }
}
