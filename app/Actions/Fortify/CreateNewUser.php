<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(private CreateTeam $createTeam) {}

    /**
     * Validate and create a newly registered user, with a team they own.
     * With registration closed, only for an email holding a pending invitation.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $input['email'] = Str::lower($input['email'] ?? '');

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => [...$this->passwordRules(), 'different:email'],
            'team' => ['nullable', 'string', 'max:255'],
        ])->after(function ($validator) use ($input) {
            if (! self::isOpenTo($input['email'])) {
                $validator->errors()->add('email', __('Sign-up is by invitation only: use the email address your invitation was sent to.'));
            }
        })->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $this->createTeam->handle($user, filled($input['team'] ?? null) ? $input['team'] : __(":name's Team", ['name' => $user->name]));

            return $user;
        });
    }

    /**
     * Determine if an account may be created: registration is open, or the
     * email holds a pending invitation.
     */
    public static function isOpenTo(?string $email): bool
    {
        return config('auth.registration_open') || ($email !== null && $email !== '' && TeamInvitation::isPendingFor($email));
    }
}
