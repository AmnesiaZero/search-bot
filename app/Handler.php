<?php

namespace App;

use App\Models\Bot;
use App\Models\Chat;
use DefStudio\Telegraph\Exceptions\KeyboardException;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Models\TelegraphChat;
use DefStudio\Telegraph\Telegraph;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Search\Sdk\Client;
use Search\Sdk\collections\AudioCollection;
use Search\Sdk\collections\BookCollection;
use Search\Sdk\Models\Audio;
use Search\Sdk\Models\Book;
use function Symfony\Component\String\b;

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
            case Bot::NAMES_STATE:
                $this->getModel($text,$chatId);
                break;
            case Bot::NEUTRAL_STATE:
                $this->reply('Неизвестная команда');
                break;
            default:
                $this->reply('Неизвестная команда');
                break;
        }
    }

    public function start(): void
    {
        Log::debug('Вошёл в функцию');
        $chatId = $this->getChatId();
        Chat::setBotState($this->getChatId(),Bot::NEUTRAL_STATE);
        $this->telegraph->message('Привет, я IPRBOT, твой личный помощник по поиску учебников в цифровых библиотеках экосистемы IPR SMART от компании IPR MEDIA.
        Ты сможешь найти книги по названию книги, издательству или автору. Найденные учебники доступны в рамках подписки твоего университета.
        Для работы в экосистеме IPR SMART необходимо авторизоваться на ресурсе (https://www.iprbookshop.ru). Логин и пароль можно взять в библиотеке.')
            ->send();
        $this->telegraph->message('Что желаете сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Регистрация')->action('auth')->param('chat_id',$chatId),
            Button::make('Поиск')->action('search')->param('chat_id',$chatId),
            Button::make('Помощь')->action('help')->param('chat_id',$chatId)
        ]))->send();
    }

    public function help()
    {

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

    public function setOrganization(int $organizationId): void
    {
        $this->chat->organization_id = $organizationId;
        $this->chat->save();
        Chat::setBotState($this->getChatId(),Bot::TOKEN_STATE);
        $this->telegraph->message('Укажите ваш секретный ключ')->send();
    }

    /**
     * @param string $secretKey
     * @return void
     */
    public function setSecretKey(string $secretKey): void
    {
        Log::debug('secret key = '.$secretKey);
        $chat = Chat::get($this->getChatId());
        $chat->secret_key = $secretKey;
        $chat->save();
        Chat::setBotState($this->getChatId(),Bot::NEUTRAL_STATE);
        $this->telegraph->message('Вы успешно зарегестрировались')->keyboard(Keyboard::make()->buttons([
            Button::make('Поиск')->action('search')->param('chat_id',$this->getChatId()),
            Button::make('К началу')->action('start'),
        ]))->send();
    }

    public function search(): void
    {
        $chatId = $this->getChatId();
        Log::debug('Request info - '.json_encode($this->request->request->all()));
        $this->telegraph->message('Что вы хотите искать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Книги')->action('books')->param('chat_id',$chatId),
            Button::make('Аудио')->action('audios')->param('chat_id',$chatId)
        ]))->send();
    }

    public function books(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $chat->category = 'books';
        $chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить поисковое выражение')->action('searchWord')->param('chat_id',$chatId),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id',$chatId)
        ]))->send();
    }

    public function searchWord(): void
    {
        Chat::setBotState($this->getChatId(),Bot::SEARCH_STATE);
        $this->telegraph->message('Введите поисковое выражение')->send();
    }

    /**
     * @return void
     */
    public function params(): void
    {
        $chatId = $this->getChatId();
        Chat::setBotState($chatId,Bot::PARAM_STATE);
        $this->telegraph->message('Выберите параметры')->send();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function getCollection():void
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
        $content =  $collection->search($chat->search,['available' => 0,'limit' => 20]);
        if(!$content){
            $this->telegraph->message('Ошибка - '.$collection->getMessage())->send();
            return;
        }
        Chat::setContent($this->getChatId(),$content);
        $names = $collection->getNames();
        $this->telegraph->message("Найдено:\n".$names)->send();
        Chat::setBotState($chatId,Bot::NAMES_STATE);
    }

    public function getModel(int $number,int $chatId): void
    {
        $collection = Chat::getCollection($chatId);
        $content = $collection[$number-1];
        Log::debug(json_encode($content,JSON_UNESCAPED_UNICODE));
        $chat = Chat::get($this->getChatId());
        $category = $chat->category;
        $model = '';
        switch ($category) {
            case 'books':
                $model = new Book($content);
                break;
            case 'audios':
                $model = new Audio($content);
                break;
        }
        $this->telegraph->message($model->toString())->keyboard(Keyboard::make()->buttons([
            Button::make('Искать ещё раз')->action('search')->param('chat_id',$this->getChatId()),
            Button::make('К началу')->action('start')->param('chat_id',$this->getChatId())
        ]))->send();
    }

    public function finalSearch(string $text): void
    {
        Log::debug('chat id = '.$this->getChatId());
        $chat = Chat::get($this->getChatId());
        $chat->search = $text;
        $chat->save();
        $this->telegraph->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Отправить поиск')->action('getCollection')->param('chat_id',$this->getChatId()),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id',$this->getChatId()),
        ]))->send();
    }

    public function setParams(string $text): void
    {
        $chat = Chat::get($this->getChatId());
        $params = $chat->params;
        if(!is_array($params)){
            $params=[];
        }
        $array = explode('=',$text);
        $params[$array[0]]=$array[2];
        $chat->params = $params;
        $chat->save();
        $this->telegraph->message('Параметры успешно настроены')->send();
    }

    public function getChatId()
    {
        if ($this->message!=null){
            return $this->message->chat()->id();
        }
        return $this->data->get('chat_id');
    }

    public function getClient(): Client
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        return new Client($chat->organization_id,$chat->secret_key);
    }


}
