<?php

namespace App;

use App\Models\Bot;
use App\Models\Chat;
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

    public Telegraph $telegraph;


    const DOMAIN = 'http://dev.api.search.ipr-smart.ru/api';

    public function __construct()
    {
        parent::__construct();
        $this->telegraph = new Telegraph();
    }

    public function hello(): void
    {
        $this->reply('Привет');
    }

    public function start(): void
    {
        $this->telegraph->message('Добро пожаловать в поисковый бот. Что желаете сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Регистрация')->action('auth')->param('chat_id',$this->getChatId()),
            Button::make('Поиск')->action('search')->param('chat_id',$this->getChatId()),
            Button::make('Помощь')->action('help')->param('chat_id',$this->getChatId()),
        ]))->send();
    }

    public function help()
    {

    }

    public function search(): void
    {
        $chatId = $this->getChatId();
        $this->telegraph->message('Что вы хотите искать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Книги')->action('books')->param('chat_id',$chatId),
            Button::make('Аудио')->action('audios')->param('chat_id',$chatId)
        ]))->send();
    }

    public function books(): void
    {
        $chatId = $this->getChatId();
        Log::debug("Chat id = ".$chatId);
        $chat = Chat::get($chatId);
        $chat->category = 'books';
        $chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить поисковое выражение')->action('searchWord')->param('chat_id',$chatId),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id',$chatId)
        ]))->send();
    }

    public function params(): void
    {
        Chat::setBotState($this->getChatId(),Bot::PARAM_STATE);
        $this->telegraph->message('Введите параметры')->send();
    }


    public function searchWord(): void
    {
        Log::debug($this->data);
        Chat::setBotState($this->getChatId(),Bot::SEARCH_STATE);
        $this->telegraph->message('Введите поисковое выражение')->send();
    }

    /**
     * @throws KeyboardException
     */
    public function auth(): void
    {
        $chatId = $this->getChatId();
        if(Chat::setBotState($chatId,Bot::ORGANIZATION_STATE)){
            $this->telegraph->message('Укажите номер организации')->
            forceReply('reply')->send();
        }
        else{
            $this->telegraph->message('Такого чата не существует')->send();
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
        $botState = $chat->bot_state;
        switch ($botState){
            case Bot::ORGANIZATION_STATE:
                $organizationId = (int) $text;
                $this->setOrganization($organizationId);
                break;
            case Bot::TOKEN_STATE:
                $this->setSecretKey($text);
                break;
            case Bot::SEARCH_STATE:
                $this->finalSearch($text);
                break;
            case Bot::PARAM_STATE:
                $this->setParams($text);
                break;
            case Bot::NEUTRAL_STATE:
                $this->reply('Неизвестная команда');
                break;
        }
    }

    public function getClient(): Client
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        return new Client($chat->organization_id,$chat->secret_key);
    }

    /**
     * @return void
     * @throws Exception
     */
    public function sendRequest():void
    {
        $client = $this->getClient();
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
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
        $content =  $collection->search($chat->search,['available' => 0]);
        if(!$content){
            $this->telegraph->message('Ошибка - '.$collection->getMessage())->send();
            return;
        }
        $string = '';
        foreach (array_keys($content[0]) as $key){
            $string.=$key.":".$content[0][$key]."\n";
        }

        $this->telegraph->photo('C:\Users\iprsm\Pictures\gf_eCagTh6Y.jpg')->message($string)->send();
        Chat::setBotState($chatId,Bot::NEUTRAL_STATE);
    }

    public function getChatId()
    {
        if ($this->message!=null){
            return $this->message->chat()->id();
        }
        return $this->data->get('chat_id');
    }

    public function setOrganization(int $organizationId): void
    {
        $this->chat->organization_id = $organizationId;
        $this->chat->save();
        Chat::setBotState($this->getChatId(),Bot::TOKEN_STATE);
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
        Chat::setBotState($this->getChatId(),Bot::NEUTRAL_STATE);
        $this->telegraph->message('Вы успешно зарегестрировались')->send();
    }

    public function finalSearch(string $text): void
    {
        Log::debug('chat id = '.$this->getChatId());
        $this->chat->search = $text;
        $this->chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Отправить поиск')->action('sendRequest')->param('chat_id',$this->getChatId()),
            Button::make('Настроить параметры поиска')->action('params'),
        ]))->send();
    }

    public function setParams(string $text): void
    {
        $this->chat->params = $text;
        $this->chat->save();
        $this->telegraph->message('Параметры успешно настроены')->send();
    }


}
