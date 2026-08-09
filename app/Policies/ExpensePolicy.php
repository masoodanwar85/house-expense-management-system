<?php

namespace App\Policies;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Services\House\HouseAccessService;
use Illuminate\Auth\Access\Response;

class ExpensePolicy
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    public function view(User $user, Expense $expense): Response
    {
        $expense->loadMissing('house');

        return $this->access->isMember($expense->house, $user)
            ? Response::allow()
            : Response::deny('You are not an active member of this house.');
    }

    public function update(User $user, Expense $expense): Response
    {
        $expense->loadMissing('house');

        if ($expense->status === ExpenseStatus::Cancelled) {
            return Response::deny('Cancelled expenses cannot be edited.');
        }

        if (! $this->access->isMember($expense->house, $user)) {
            return Response::deny('You are not an active member of this house.');
        }

        if ($expense->status === ExpenseStatus::Confirmed) {
            return $this->access->isOwner($expense->house, $user)
                ? Response::allow()
                : Response::deny('Only the house owner can edit confirmed expenses.');
        }

        // Draft: payer, creator, or owner.
        if (
            $this->access->isOwner($expense->house, $user)
            || $expense->paid_by === $user->id
            || $expense->created_by === $user->id
        ) {
            return Response::allow();
        }

        return Response::deny('You cannot edit this expense.');
    }

    public function confirm(User $user, Expense $expense): Response
    {
        return $this->update($user, $expense);
    }

    public function cancel(User $user, Expense $expense): Response
    {
        $expense->loadMissing('house');

        if ($expense->status === ExpenseStatus::Cancelled) {
            return Response::deny('Expense is already cancelled.');
        }

        if (! $this->access->isMember($expense->house, $user)) {
            return Response::deny('You are not an active member of this house.');
        }

        return $this->access->isOwner($expense->house, $user)
            ? Response::allow()
            : Response::deny('Only the house owner can cancel expenses.');
    }

    public function reinstate(User $user, Expense $expense): Response
    {
        $expense->loadMissing('house');

        if ($expense->status !== ExpenseStatus::Cancelled) {
            return Response::deny('Only cancelled expenses can be reinstated.');
        }

        if (! $this->access->isMember($expense->house, $user)) {
            return Response::deny('You are not an active member of this house.');
        }

        return $this->access->isOwner($expense->house, $user)
            ? Response::allow()
            : Response::deny('Only the house owner can reinstate expenses.');
    }

    public function viewAllocations(User $user, Expense $expense): Response
    {
        return $this->view($user, $expense);
    }
}
