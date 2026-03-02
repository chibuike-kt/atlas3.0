<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;

abstract class BaseApiController extends Controller
{
  use ApiResponse;
}
