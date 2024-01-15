<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bot extends Model
{
    use HasFactory;

    const NEUTRAL_STATE = 0;

    const SEARCH_STATE = 1;

    const COLLECTION_STATE = 2;

    const NAMES_STATE=3;

    const PARAM_STATE = 4;



}
