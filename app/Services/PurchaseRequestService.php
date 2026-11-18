<?php

namespace App\Services;

use App\Enums\PurchaseRequestStatus;
use App\Enums\Role;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/** Alur status Purchase Request. Persetujuan hanya untuk Owner / Manager. */
class PurchaseRequestService
{
    public function canApprove(?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $role = $user->role instanceof Role ? $user->role : Role::tryFrom((string) $user->role);

        return in_array($role, [Role::Owner, Role::Manager], true);
    }

    public function submit(PurchaseRequest $pr): PurchaseRequest
    {
        $this->assertStatus($pr, PurchaseRequestStatus::Draft);

        return tap($pr)->update(['status' => PurchaseRequestStatus::Submitted]);
    }

    public function approve(PurchaseRequest $pr, User $by): PurchaseRequest
    {
        $this->assertCanApprove($by);
        $this->assertStatus($pr, PurchaseRequestStatus::Submitted);

        return tap($pr)->update([
            'status' => PurchaseRequestStatus::Approved, 'approved_by' => $by->id, 'approved_at' => now(),
        ]);
    }

    public function reject(PurchaseRequest $pr, User $by): PurchaseRequest
    {
        $this->assertCanApprove($by);
        $this->assertStatus($pr, PurchaseRequestStatus::Submitted);

        return tap($pr)->update([
            'status' => PurchaseRequestStatus::Rejected, 'approved_by' => $by->id, 'approved_at' => now(),
        ]);
    }

    private function assertCanApprove(User $user): void
    {
        if (! $this->canApprove($user)) {
            throw new AuthorizationException('Hanya Owner atau Manager yang boleh menyetujui/menolak Purchase Request.');
        }
    }

    private function assertStatus(PurchaseRequest $pr, PurchaseRequestStatus $expected): void
    {
        if ($pr->status !== $expected) {
            throw new RuntimeException("Status Purchase Request harus {$expected->value}.");
        }
    }
}
