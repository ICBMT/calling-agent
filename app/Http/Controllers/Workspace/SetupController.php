<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Services\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function __invoke(Request $request, SystemHealth $health): View
    {
        return view('workspace.setup', [
            'checks' => $health->checks($request->attributes->get('organization')),
            'hasAgent' => $request->attributes->get('organization')->agents()->exists(),
        ]);
    }
}
