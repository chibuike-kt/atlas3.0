<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
  protected function success(mixed $data = null, string $message = 'Request successful.', int $status = 200): JsonResponse
  {
    return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
  }

  protected function created(mixed $data = null, string $message = 'Resource created successfully.'): JsonResponse
  {
    return $this->success($data, $message, 201);
  }

  protected function noContent(string $message = 'Resource deleted.'): JsonResponse
  {
    return response()->json(['success' => true, 'message' => $message, 'data' => null], 200);
  }

  protected function error(string $message = 'Something went wrong.', mixed $data = null, int $status = 400): JsonResponse
  {
    return response()->json(['success' => false, 'message' => $message, 'data' => $data], $status);
  }

  protected function notFound(string $message = 'Resource not found.'): JsonResponse
  {
    return $this->error($message, null, 404);
  }

  protected function unauthorized(string $message = 'Unauthorized.'): JsonResponse
  {
    return $this->error($message, null, 401);
  }

  protected function forbidden(string $message = 'You do not have permission to perform this action.'): JsonResponse
  {
    return $this->error($message, null, 403);
  }

  protected function unprocessable(string $message = 'Validation failed.', mixed $data = null): JsonResponse
  {
    return $this->error($message, $data, 422);
  }

  protected function serverError(string $message = 'An internal error occurred. Please try again.'): JsonResponse
  {
    return $this->error($message, null, 500);
  }

  protected function paginated(mixed $paginator, string $message = 'Request successful.'): JsonResponse
  {
    return response()->json([
      'success' => true,
      'message' => $message,
      'data'    => $paginator->items(),
      'meta'    => [
        'current_page' => $paginator->currentPage(),
        'last_page'    => $paginator->lastPage(),
        'per_page'     => $paginator->perPage(),
        'total'        => $paginator->total(),
        'from'         => $paginator->firstItem(),
        'to'           => $paginator->lastItem(),
      ],
    ], 200);
  }
}
