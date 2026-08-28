<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Services\Auth\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(private readonly JwtService $jwt) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminUser::where('email', $data['email'])->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['email' => 'Those credentials don\'t match an account.']);
        }

        return response()->json([
            'token' => $this->jwt->issue($admin, 'admin')['token'],
            'admin' => $admin->only('id', 'name', 'email'),
        ]);
    }

    public function destroy(Request $request)
    {
        $this->jwt->revoke($request->attributes->get('jwt_claims'));

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json(['admin' => $request->user()->only('id', 'name', 'email')]);
    }

    public function update(Request $request)
    {
        $admin = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:admin_users,email,'.$admin->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ]);

        if (! empty($data['password'])) {
            $admin->password = $data['password']; // the model's cast hashes it
        }
        $admin->name = $data['name'];
        $admin->email = $data['email'];
        $admin->save();

        return response()->json(['admin' => $admin->only('id', 'name', 'email')]);
    }
}
