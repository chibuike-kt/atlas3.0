<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends BaseApiController
{
    /**
     * GET /api/admin/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['connectedAccounts', 'financialProfile'])
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")
                  ->orWhere('email', 'like', "%{$s}%")
                  ->orWhere('phone', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->boolean('admin_only')) {
            $query->where('is_admin', true);
        }

        $users = $query->paginate($request->input('per_page', 25));

        return $this->paginated(
            $users->through(fn($u) => $this->formatUser($u)),
            'Users retrieved.'
        );
    }

    /**
     * GET /api/admin/users/{id}
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with([
            'connectedAccounts',
            'financialProfile',
            'rules',
            'disputes',
            'salaryAdvances',
        ])->find($id);

        if (! $user) {
            return $this->notFound('User not found.');
        }

        return $this->success($this->formatUser($user, true));
    }

    /**
     * POST /api/admin/users/{id}/suspend
     */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found.');
        }

        if ($user->is_admin) {
            return $this->error('Cannot suspend an admin user.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $user->update([
            'is_active'       => false,
            'suspended_at'    => now(),
            'suspension_reason' => $validated['reason'],
        ]);

        return $this->success($this->formatUser($user->fresh()), 'User suspended.');
    }

    /**
     * POST /api/admin/users/{id}/unsuspend
     */
    public function unsuspend(string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found.');
        }

        $user->update([
            'is_active'         => true,
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);

        return $this->success($this->formatUser($user->fresh()), 'User unsuspended.');
    }

    /**
     * POST /api/admin/users/{id}/make-admin
     */
    public function makeAdmin(string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found.');
        }

        $user->update(['is_admin' => true]);

        return $this->success($this->formatUser($user->fresh()), 'User granted admin access.');
    }

    /**
     * DELETE /api/admin/users/{id}/make-admin
     */
    public function revokeAdmin(string $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return $this->notFound('User not found.');
        }

        $user->update(['is_admin' => false]);

        return $this->success($this->formatUser($user->fresh()), 'Admin access revoked.');
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function formatUser(User $user, bool $detailed = false): array
    {
        $base = [
            'id'           => $user->id,
            'full_name'    => $user->full_name,
            'email'        => $user->email,
            'phone'        => $user->phone,
            'is_active'    => $user->is_active,
            'is_admin'     => $user->is_admin,
            'is_verified'  => $user->is_verified,
            'accounts'     => $user->connectedAccounts?->count() ?? 0,
            'health_score' => $user->financialProfile?->financial_health_score,
            'created_at'   => $user->created_at,
            'suspended_at' => $user->suspended_at ?? null,
            'suspension_reason' => $user->suspension_reason ?? null,
        ];

        if ($detailed) {
            $base['connected_accounts'] = $user->connectedAccounts?->map(fn($a) => [
                'id'          => $a->id,
                'institution' => $a->institution,
                'balance'     => $a->balance,
                'formatted'   => '₦' . number_format($a->balance / 100, 2),
                'is_primary'  => $a->is_primary,
                'is_active'   => $a->is_active,
            ])->toArray();

            $base['financial_profile'] = $user->financialProfile ? [
                'health_score'    => $user->financialProfile->financial_health_score,
                'savings_rate'    => $user->financialProfile->savings_rate_percent,
                'salary_detected' => $user->financialProfile->salary_detected,
                'avg_salary'      => $user->financialProfile->average_salary,
                'income_type'     => $user->financialProfile->income_type,
            ] : null;

            $base['stats'] = [
                'rules'           => $user->rules?->count() ?? 0,
                'open_disputes'   => $user->disputes?->where('status', 'open')->count() ?? 0,
                'active_advances' => $user->salaryAdvances?->whereIn('status', ['pending', 'disbursed'])->count() ?? 0,
            ];
        }

        return $base;
    }
}
