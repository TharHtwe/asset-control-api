<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $login = $request->validate([
            'email' => 'required',
            'password' => 'required',
        ]);

        if (!Auth::attempt($login)) {
            return response(['message' => __('auth.failed')], 401);
        }
        $token = Auth::user()->createToken('api')->accessToken;
        return response(['token' => $token]);
    }

    public function me()
    {
        return response(['user' => Auth::user()]);
    }
}
