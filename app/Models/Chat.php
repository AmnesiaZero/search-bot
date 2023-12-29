<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use function Webmozart\Assert\Tests\StaticAnalysis\null;

class Chat extends Model
{
    use HasFactory;

    protected $table = 'telegraph_chats';

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

}
