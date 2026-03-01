<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
  protected function success(
    mixed $data = null,
    string $message = 'Request successful.',
    int $status = 200
  ): JsonResponse {
    return response()->json([
      'success' => true,
      'message' => $message,
      'data'    => $data,
    ], $status);
  }

  protected function created(
    mixed $data = null,
    string $message = 'Resource created successfully.'
  ): JsonResponse {
    return $this->success($data, $message, 201);
  }

  protected function error(
    string $message = 'An error occurred.',
    int $status = 400,
    mixed $errors = null
  ): JsonResponse {
    $payload = [
      'success' => false,
      'message' => $message,
    ];

    if ($errors !== null) {
      $payload['errors'] = $errors;
    }

    return response()->json($payload, $status);
  }

  protected function validationError(mixed $errors): JsonResponse
  {
    return $this->error('The given data was invalid.', 422, $errors);
  }

  protected function notFound(string $message = 'Resource not found.'): JsonResponse
  {
    return $this->error($message, 404);
  }

  protected function unauthorized(string $message = 'Unauthorized.'): JsonResponse
  {
    return $this->error($message, 401);
  }

  protected function forbidden(string $message = 'You do not have permission to perform this action.'): JsonResponse
  {
    return $this->error($message, 403);
  }

  protected function paginated(
    LengthAwarePaginator $paginator,
    string $message = 'Request successful.'
  ): JsonResponse {
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
      'links' => [
        'first' => $paginator->url(1),
        'last'  => $paginator->url($paginator->lastPage()),
        'prev'  => $paginator->previousPageUrl(),
        'next'  => $paginator->nextPageUrl(),
      ],
    ]);
  }
}
