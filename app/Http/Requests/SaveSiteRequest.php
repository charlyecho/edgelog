<?php

namespace App\Http\Requests;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSiteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $site = $this->site();

        return $site === null
            ? $this->user()->can('create', [Site::class, $this->team()])
            : $this->user()->can('update', $site);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('url')) {
            $this->merge(['url' => Site::normalizeUrl($this->string('url'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'url' => [
                'required',
                'string',
                'max:255',
                'url:http,https',
                Rule::unique(Site::class, 'url')->ignore($this->site()),
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.unique' => __('This site is already registered. Ask the team that has it for an invitation.'),
        ];
    }

    /**
     * Get the site's attributes, naming it after its host when no name is given.
     *
     * @return array{name: string, url: string}
     */
    public function siteAttributes(): array
    {
        $url = $this->validated('url');

        return [
            'name' => $this->validated('name') ?: parse_url($url, PHP_URL_HOST),
            'url' => $url,
        ];
    }

    /**
     * Get the team the site belongs to.
     */
    public function team(): Team
    {
        return $this->route('team');
    }

    /**
     * Get the site being changed, or null when one is being added.
     */
    public function site(): ?Site
    {
        return $this->route('site');
    }
}
