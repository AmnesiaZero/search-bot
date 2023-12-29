<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Chat;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Telegraph;
use Exception;
use Illuminate\Support\Facades\Log;
use Search\Sdk\Client;
use Search\Sdk\collections\BookCollection;

class BotService
{

    public Telegraph $telegraph;

    public Chat $chat;



    public function __construct(Telegraph $telegraph)
    {
        $this->telegraph = $telegraph;
    }


    public function setOrganization(int $organizationId): void
    {
        Log::debug('chat id = '.$this->chat->chat_id);
        $this->chat->organization_id = $organizationId;
        $this->chat->save();
        Chat::setBotState($this->chat->chat_id,Bot::TOKEN_STATE);
        $this->telegraph->message('Укажите ваш секретный ключ')->send();
    }

    /**
     * @throws Exception
     */
    public function setSecretKey(string $secretKey): void
    {
        Log::debug('secret key = '.$secretKey);
        $this->chat->secret_key = $secretKey;
        $this->chat->save();
        Chat::setBotState($this->chat->chat_id,Bot::NEUTRAL_STATE);
        $this->telegraph->message('Вы успешно зарегестрировались')->send();
    }

    public function search(string $text): void
    {
        $this->chat->search = $text;
        $this->chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Отправить поиск')->action('sendRequest'),
            Button::make('Настроить параметры поиска')->action('params'),
        ]))->send();
    }

//    public function setParams(Chat $this->>chat)
//    {
//        $this->>chat->
//    }

    public function setChat(Chat $chat): void
    {
        $this->chat = $chat ;
    }

}
