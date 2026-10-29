<?php

namespace App\Http\Requests;

use App\Enums\TeamRole;
use App\Http\Requests\Concerns\ValidatesMemberAccess;
use App\Models\Team;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InviteTeamMemberRequest extends FormRequest
{
    use ValidatesMemberAccess;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->team());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    $isMember = $this->team()->members()->where('email', Str::lower($value))->exists();

                    if ($isMember) {
                        $fail(__('This person is already a member of the team.'));
                    }
                },
            ],
            'role' => [
                'required',
                Rule::enum(TeamRole::class)->only($this->user()->roleIn($this->team())?->assignableRoles() ?? []),
            ],
            ...$this->accessRules(),
        ];
    }

    /**
     * Get the team the invitation is for.
     */
    public function team(): Team
    {
        return $this->route('team');
    }

    /**
     * Get the role to give the invited person.
     */
    public function role(): TeamRole
    {
        return $this->enum('role', TeamRole::class);
    }
}
