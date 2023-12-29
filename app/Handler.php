<?php

namespace App;

use App\Models\Bot;
use App\Models\Chat;
use App\Services\BotService;
use DefStudio\Telegraph\Exceptions\KeyboardException;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Telegraph;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Search\Sdk\Client;
use Search\Sdk\collections\AudioCollection;
use Search\Sdk\collections\BookCollection;

class Handler extends WebhookHandler
{
    public BotService $botService;

    public Telegraph $telegraph;


    const DOMAIN = 'http://dev.api.search.ipr-smart.ru/api';

    public function __construct()
    {
        parent::__construct();
        $this->telegraph = new Telegraph();
        $this->botService = new BotService($this->telegraph);
    }

    public function hello(): void
    {
        $this->reply('Привет');
    }

    public function search(): void
    {
        $this->telegraph->message('Что вы хотите искать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Книги')->action('books')->param('chat_id',$this->message->chat()->id()),
            Button::make('Аудио')->action('audios')
        ]))->send();
    }

    public function books(): void
    {
        $chat = Chat::get($chatId);
        $chat->category = 'books';
        $chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить поисковое выражение')->action('searchWord'),
            Button::make('Настроить параметры поиска')->action('params'),
        ]))->send();
    }

    public function params(): void
    {
        Chat::setBotState($this->message->chat()->id(),Bot::PARAM_STATE);
        $this->reply('Введите параметры поиска');
    }


    public function searchWord(): void
    {
        Chat::setBotState($this->message->chat()->id(),Bot::SEARCH_STATE);
        $this->reply('Введите поисковое выражение');
    }

    /**
     * @throws KeyboardException
     */
    public function auth(): void
    {
        $chatId = $this->message->chat()->id();
        if(Chat::setBotState($chatId,Bot::ORGANIZATION_STATE)){
            $this->telegraph->message('Укажите номер организации')->
            forceReply('reply')->send();
        }
        else{
            $this->reply('Такого чата не существует');
        }
    }

    /**
     * @param Stringable $text
     * @return void
     * @throws Exception
     */
    public function handleChatMessage(Stringable $text): void
    {
        $text = $text->toString();
        Log::debug('Text - '.$text);
        $chatId = $this->message->chat()->id();
        $chat = Chat::get($chatId);
        $this->botService->setChat($chat);
        Log::debug($chat);
        $botState = $chat->bot_state;
        switch ($botState){
            case Bot::ORGANIZATION_STATE:
                $organizationId = (int) $text;
                $this->botService->setOrganization($organizationId);
                break;
            case Bot::TOKEN_STATE:
                $this->botService->setSecretKey($text);
                break;
            case Bot::SEARCH_STATE:
                $this->botService->search($text);
                break;
            case Bot::NEUTRAL_STATE:
                $this->reply('Неизвестная команда');
                break;
        }
    }

    public function getClient(): Client
    {
        $chatId = $this->message->chat()->id();
        $chat = Chat::get($chatId);
        return new Client($chat->organization_id,$chat->secret_key);
    }

    public function sendRequest()
    {
        $client = $this->getClient();
        $chat = Chat::get($this->message->chat()->id());
        $category = $chat->category;
        $collection = '';
        switch ($category){
            case 'books':
                $collection = new BookCollection($client);
                break;
            case 'audios':
                $collection = new AudioCollection($client);
                break;
        }
        $content =  $collection->search($chat->search,[]);
        $this->reply($content);
    }

}
