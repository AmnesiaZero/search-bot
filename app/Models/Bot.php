<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bot extends Model
{
    use HasFactory;

    const NEUTRAL_STATE = 0;
    const ORGANIZATION_STATE = 1;
    const TOKEN_STATE = 2;

    const SEARCH_STATE = 3;

    const NAMES_STATE=4;

    const PARAM_STATE = 5;

}
