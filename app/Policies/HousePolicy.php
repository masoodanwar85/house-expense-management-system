<?php

namespace App\Policies;

use App\Models\House;
use App\Models\User;
use App\Services\House\HouseAccessService;
use Illuminate\Auth\Access\Response;

class HousePolicy
{
    public function __construct(
        private readonly HouseAccessService $access,
    ) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, House $house): Response
    {
        return $this->access->isMember($house, $user)
            ? Response::allow()
            : Response::deny('You are not an active member of this house.');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, House $house): Response
    {
        return $this->ownerResponse($user, $house);
    }

    public function manageMembers(User $user, House $house): Response
    {
        return $this->ownerResponse($user, $house);
    }

    public function manageCategories(User $user, House $house): Response
    {
        return $this->ownerResponse($user, $house);
    }

    public function createExpense(User $user, House $house): Response
    {
        return $this->view($user, $house);
    }

    public function manageAvailability(User $user, House $house): Response
    {
        return $this->view($user, $house);
    }

    public function viewSettlement(User $user, House $house): Response
    {
        return $this->view($user, $house);
    }

    public function closeMonth(User $user, House $house): Response
    {
        return $this->ownerResponse($user, $house);
    }

    public function reopenMonth(User $user, House $house): Response
    {
        return $this->ownerResponse($user, $house);
    }

    private function ownerResponse(User $user, House $house): Response
    {
        return $this->access->isOwner($house, $user)
            ? Response::allow()
            : Response::deny('Only the house owner can perform this action.');
    }
}
