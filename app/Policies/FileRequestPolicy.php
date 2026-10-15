<?php

namespace App\Policies;

use App\Models\FileRequest;
use App\Models\User;

class FileRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->chairsAProgram($user) || $this->canOversee($user);
    }

    public function view(User $user, FileRequest $fileRequest): bool
    {
        if ($this->canOversee($user)) {
            return true;
        }

        return $this->chairsProgram($user, (int) $fileRequest->requester_program_id)
            || $this->chairsProgram($user, (int) $fileRequest->recipient_program_id);
    }

    public function create(User $user): bool
    {
        return $this->chairsAProgram($user);
    }

    public function cancel(User $user, FileRequest $fileRequest): bool
    {
        return $fileRequest->isPending()
            && $this->chairsProgram($user, (int) $fileRequest->requester_program_id);
    }

    public function fulfill(User $user, FileRequest $fileRequest): bool
    {
        return $fileRequest->isPending()
            && $this->chairsProgram($user, (int) $fileRequest->recipient_program_id);
    }

    public function decline(User $user, FileRequest $fileRequest): bool
    {
        return $this->fulfill($user, $fileRequest);
    }

    public function download(User $user, FileRequest $fileRequest): bool
    {
        return $fileRequest->status === FileRequest::STATUS_FULFILLED
            && $this->view($user, $fileRequest);
    }

    public function removeFile(User $user, FileRequest $fileRequest): bool
    {
        return $fileRequest->status === FileRequest::STATUS_FULFILLED
            && $this->chairsProgram($user, (int) $fileRequest->recipient_program_id);
    }

    public function browsePrograms(User $user): bool
    {
        return $this->chairsAProgram($user);
    }

    private function canOversee(User $user): bool
    {
        return $user->isQA() || $user->isVPAA() || $user->isSuperAdmin();
    }

    private function chairsAProgram(User $user): bool
    {
        return $user->isProgramChair() && $this->chairedId($user) !== null;
    }

    private function chairsProgram(User $user, int $programId): bool
    {
        return $user->isProgramChair() && (int) $this->chairedId($user) === $programId;
    }

    /**
     * programs.chair_id is queried once per request so list resources do not repeat it.
     */
    private function chairedId(User $user): ?int
    {
        $request = request();
        $userKey = 'file_request_chaired_program_user';
        $idKey = 'file_request_chaired_program_id';

        if ($request && (int) $request->attributes->get($userKey) === (int) $user->id && $request->attributes->has($idKey)) {
            $cached = $request->attributes->get($idKey);

            return $cached === null ? null : (int) $cached;
        }

        $id = $user->chairedProgramId();
        if ($request) {
            $request->attributes->set($userKey, $user->id);
            $request->attributes->set($idKey, $id);
        }

        return $id;
    }
}
