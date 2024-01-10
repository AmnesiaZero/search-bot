<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Chat extends Model
{
    use HasFactory;

    protected $table = 'telegraph_chats';

    protected $casts = [
        'collection' => 'array',
        'params' => 'array'
    ];

    /**
     * @param int $chatId
     * @param int $botState
     * @return bool
     */
    public static function setBotState(int $chatId,int $botState): bool
    {
        $chat = self::get($chatId);
        if (!$chat) {
            return false;
        }
        $chat->bot_state = $botState;
        $chat->save();
        return true;
    }

    public static function get(int $chatId):mixed
    {
        $chat = Chat::query()->where('chat_id',$chatId)->first();
        if($chat==null){
            return false;
        }
        return $chat;
    }

    public static function getBotState()
    {

    }

    public static function getCollection(int $chatId):array|bool
    {
        $chat = self::get($chatId);
        if($chat==null){
            return false;
        }
        return $chat->collection;
    }

    public static function setContent(int $chatId,array $collection): bool
    {
        $chat = self::get($chatId);
        if($chat==null){
            return false;
        }
        $chat->collection = $collection;
        $chat->save();
        return true;
    }

}
