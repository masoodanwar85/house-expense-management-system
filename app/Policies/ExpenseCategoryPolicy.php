<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\House\HouseAccessService;
use Illuminate\Auth\Access\Response;

class ExpenseCategoryPolicy
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    public function view(User $user, ExpenseCategory $category): Response
    {
        $category->loadMissing('house');

        return $this->access->isMember($category->house, $user)
            ? Response::allow()
            : Response::deny('You are not an active member of this house.');
    }

    public function update(User $user, ExpenseCategory $category): Response
    {
        return $this->ownerResponse($user, $category);
    }

    public function manageRules(User $user, ExpenseCategory $category): Response
    {
        return $this->ownerResponse($user, $category);
    }

    private function ownerResponse(User $user, ExpenseCategory $category): Response
    {
        $category->loadMissing('house');

        if (! $this->access->isMember($category->house, $user)) {
            return Response::deny('You are not an active member of this house.');
        }

        return $this->access->isOwner($category->house, $user)
            ? Response::allow()
            : Response::deny('Only the house owner can perform this action.');
    }
}
