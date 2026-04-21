<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * 會員登入
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('web')->attempt($credentials)) {
            // 登入成功後，重新產生 Session ID 以防止 Session Fixation 攻擊
            $request->session()->regenerate();

            return response()->json([
                'message' => '登入成功',
            ]);
        }

        return response()->json([
            'message' => '登入失敗'
        ], 401);
    }

    /**
     * 會員登出
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        // 將使用者登出 web guard
        Auth::guard('web')->logout();

        // 使目前的 Session 失效
        $request->session()->invalidate();

        // 重新產生 CSRF Token，確保後續請求的安全
        $request->session()->regenerateToken();

        return response()->json([
            'message' => '登出成功'
        ]);
    }

    /**
     * 取得目前登入的會員資訊
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}
