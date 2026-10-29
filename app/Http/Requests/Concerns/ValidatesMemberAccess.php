<?php

namespace App\Http\Requests\Concerns;

use App\Models\Team;
use Illuminate\Validation\Rule;

/**
 * The access a viewer is given: everything in the team, or only the sites
 * and servers ticked, by their uuid.
 */
trait ValidatesMemberAccess
{
    /**
     * Get the team the access is in.
     */
    abstract public function team(): Team;

    /**
     * @return array<string, array<mixed>>
     */
    protected function accessRules(): array
    {
        return [
            'access' => ['sometimes', Rule::in(['all', 'limited'])],
            'sites' => ['sometimes', 'array'],
            'sites.*' => ['string', Rule::exists('sites', 'uuid')->where('team_id', $this->team()->getKey())],
            'servers' => ['sometimes', 'array'],
            'servers.*' => ['string', Rule::exists('servers', 'uuid')->where('team_id', $this->team()->getKey())],
        ];
    }

    /**
     * Determine if the member sees only what is ticked.
     */
    public function isLimited(): bool
    {
        return $this->validated('access', 'all') === 'limited';
    }

    /**
     * The ids of the sites ticked.
     *
     * @return list<int>
     */
    public function siteIds(): array
    {
        return $this->team()->sites()->whereIn('uuid', $this->validated('sites', []))->pluck('id')->all();
    }

    /**
     * The ids of the servers ticked.
     *
     * @return list<int>
     */
    public function serverIds(): array
    {
        return $this->team()->servers()->whereIn('uuid', $this->validated('servers', []))->pluck('id')->all();
    }
}
