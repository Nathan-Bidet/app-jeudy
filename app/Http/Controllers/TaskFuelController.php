<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class TaskFuelController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Tasks/Fuel/Index');
    }
}
