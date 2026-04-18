<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\Course;
use App\Models\Location;
use Illuminate\Http\Request;

class BootstrapController extends Controller
{
    public function index()
    {
        return response()->json([
            'programs' => Program::all(),
            'courses' => Course::all(),
            'locations' => Location::all(),
        ]);
    }
}
