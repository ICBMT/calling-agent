<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'organization_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'password' => [
                'required', 'string', 'confirmed', Password::min(12)->letters()->numbers(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ]);

        $user = DB::transaction(function () use ($data, $audit): User {
            $organization = Organization::create([
                'name' => $data['organization_name'],
                'slug' => (Str::slug($data['organization_name']) ?: 'workspace').'-'.Str::lower((string) Str::ulid()),
            ]);
            $user = User::create(collect($data)->only(['name', 'email', 'password'])->all());
            $organization->users()->attach($user, ['role' => 'owner']);
            $user->current_organization_id = $organization->id;
            $user->save();
            $audit->record($organization, $user, 'workspace.created', $organization);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('workspace.overview')->with('status', 'Your workspace is ready. Let’s give it a voice.');
    }
}
