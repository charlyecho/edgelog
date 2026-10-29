<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesMemberAccess;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveMemberAccessRequest extends FormRequest
{
    use ValidatesMemberAccess;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('updateMember', [$this->team(), $this->member()]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->accessRules(),
            'access' => ['required', 'in:all,limited'],
        ];
    }

    /**
     * Get the team the member is in.
     */
    public function team(): Team
    {
        return $this->route('team');
    }

    /**
     * Get the member whose access changes.
     */
    public function member(): User
    {
        return $this->route('member');
    }
}
