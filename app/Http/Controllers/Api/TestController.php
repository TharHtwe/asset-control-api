<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TestController extends Controller
{
    public function index(Request $request)
    {
        return response([
            'message' => 'Get request success!',
        ]);
    }

    public function index2(Request $request)
    {
        return response([
            'message' => 'Post request success!',
        ]);
    }
}
