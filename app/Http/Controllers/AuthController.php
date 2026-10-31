<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', 'unique:users'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $user = User::create([...$data, 'role' => 'student']);
        Auth::login($user);
        $request->session()->regenerate();
        return response()->json(['user' => $user->only('id', 'name', 'email', 'role')], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (!Auth::attempt($data, $request->boolean('remember'))) {
            return response()->json(['message' => 'The provided credentials are incorrect.'], 422);
        }
        $request->session()->regenerate();
        return response()->json(['user' => $request->user()->only('id', 'name', 'email', 'role')]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request) { return response()->json(['user' => $request->user()->only('id', 'name', 'email', 'role')]); }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));
        return response()->json(['message' => 'If an account matches that email, a reset link will be sent.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::defaults()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->setRememberToken(\Illuminate\Support\Str::random(60));
            $user->save();
        });
        return response()->json(['message' => __($status)], $status === Password::PASSWORD_RESET ? 200 : 422);
    }
}
