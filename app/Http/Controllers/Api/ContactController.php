<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Contact;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends BaseApiController
{
  public function __construct(private readonly ContactService $contactService) {}

  /**
   * GET /api/contacts
   */
  public function index(Request $request): JsonResponse
  {
    $query = $request->user()
      ->contacts()
      ->orderByDesc('is_favourite')
      ->orderBy('name');

    if ($request->filled('search')) {
      $search = $request->search;
      $query->where(function ($q) use ($search) {
        $q->where('name', 'like', "%{$search}%")
          ->orWhere('account_number', 'like', "%{$search}%")
          ->orWhere('bank_name', 'like', "%{$search}%");
      });
    }

    if ($request->boolean('favourites_only')) {
      $query->where('is_favourite', true);
    }

    $contacts = $query->paginate($request->input('per_page', 20));

    return $this->paginated(
      $contacts->through(fn($c) => $this->formatContact($c)),
      'Contacts retrieved.'
    );
  }

  /**
   * POST /api/contacts
   */
  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'name'           => ['required', 'string', 'max:100'],
      'label'          => ['sometimes', 'nullable', 'string', 'max:50'],
      'account_number' => ['required', 'string', 'size:10'],
      'bank_code'      => ['required', 'string'],
      'bank_name'      => ['sometimes', 'nullable', 'string'],
      'account_name'   => ['sometimes', 'nullable', 'string'],
      'is_favourite'   => ['sometimes', 'boolean'],
    ]);

    $contact = $this->contactService->save($request->user(), $validated);

    return $this->created($this->formatContact($contact), 'Contact saved.');
  }

  /**
   * GET /api/contacts/{id}
   */
  public function show(Request $request, string $id): JsonResponse
  {
    $contact = $this->findContact($request, $id);

    if (! $contact) {
      return $this->notFound('Contact not found.');
    }

    return $this->success($this->formatContact($contact));
  }

  /**
   * PUT /api/contacts/{id}
   */
  public function update(Request $request, string $id): JsonResponse
  {
    $contact = $this->findContact($request, $id);

    if (! $contact) {
      return $this->notFound('Contact not found.');
    }

    $validated = $request->validate([
      'name'         => ['sometimes', 'string', 'max:100'],
      'label'        => ['sometimes', 'nullable', 'string', 'max:50'],
      'is_favourite' => ['sometimes', 'boolean'],
    ]);

    $contact->update($validated);

    return $this->success($this->formatContact($contact->fresh()), 'Contact updated.');
  }

  /**
   * DELETE /api/contacts/{id}
   */
  public function destroy(Request $request, string $id): JsonResponse
  {
    $contact = $this->findContact($request, $id);

    if (! $contact) {
      return $this->notFound('Contact not found.');
    }

    $contact->delete();

    return $this->noContent('Contact deleted.');
  }

  /**
   * POST /api/contacts/{id}/favourite
   */
  public function toggleFavourite(Request $request, string $id): JsonResponse
  {
    $contact = $this->findContact($request, $id);

    if (! $contact) {
      return $this->notFound('Contact not found.');
    }

    $contact = $this->contactService->toggleFavourite($contact);
    $label   = $contact->is_favourite ? 'added to' : 'removed from';

    return $this->success($this->formatContact($contact), "Contact {$label} favourites.");
  }

  /**
   * POST /api/contacts/resolve
   * Look up an account name by account number + bank code.
   */
  public function resolve(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'account_number' => ['required', 'string', 'size:10'],
      'bank_code'      => ['required', 'string'],
    ]);

    $name = $this->contactService->resolveAccountName(
      $validated['account_number'],
      $validated['bank_code']
    );

    return $this->success([
      'account_number' => $validated['account_number'],
      'bank_code'      => $validated['bank_code'],
      'account_name'   => $name,
    ], 'Account name resolved.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function findContact(Request $request, string $id): ?Contact
  {
    return $request->user()->contacts()->find($id);
  }

  private function formatContact(Contact $contact): array
  {
    return [
      'id'             => $contact->id,
      'name'           => $contact->name,
      'label'          => $contact->label,
      'account_number' => $contact->account_number,
      'bank_code'      => $contact->bank_code,
      'bank_name'      => $contact->bank_name,
      'account_name'   => $contact->account_name,
      'is_favourite'   => $contact->is_favourite,
      'created_at'     => $contact->created_at,
    ];
  }
}
