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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Search\Sdk\Clients\BasicClient;
use Search\Sdk\Clients\MasterClient;
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
            Button::make('Поиск')->action('search')->param('chat_id',$chatId),
            Button::make('Помощь')->action('help')->param('chat_id',$chatId)
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

    /**
     * @return void
     * @throws Exception
     */
    public function getCollection():void
    {
        Log::debug('Вошёл в getCollection');
        $client = $this->getClient();
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $collection = match ($category) {
            'books' => new BookCollection($client),
            'audios' => new AudioCollection($client),
             default => new Collection($client),
        };
        if($chat->collection!=null){
            Log::debug('Подгрузка content с БД');
            $content = $chat->collection;
        }
        else{
            $content =  $collection->searchMaster($chat->search,['available' => 0]);
            $chat->collection = $content;
            $chat->save();
        }
        if(!$content){
            $this->telegraph->message('Ошибка - '.$collection->getMessage())->send();
            return;
        }
        $messageId = $chat->last_message_id;

        Log::debug("Message id = ".$messageId);
        $totalPages = intdiv(count($content),10);
        $pageId = $this->data->get('page_id');
        Log::debug("Page id = ".$pageId);
        if ($messageId!=null and $pageId!=null){
            $buttons = [
                Button::make($pageId.'/'.$totalPages)->action(''),
                Button::make('>')->action('getCollection')->param('page_id',$pageId+1)->param('chat_id',$chatId)
            ];
            Log::debug('Вошёл в условие');
            $chat = Chat::get($chatId);
            $names = $collection->getNames($content,$pageId);
            $chat->edit($messageId)->message($names)->send();
            if($pageId>1){
                array_unshift($buttons,
                    Button::make('<')->action('getCollection')->param('page_id',$pageId-1)->param('chat_id',$chatId)
                );
            }
            $this->telegraph->replaceKeyboard(
                messageId: $messageId,
                newKeyboard: Keyboard::make()->buttons($buttons)
            )->send();
        }
        else{
            $pageId = 1;
            $names = $collection->getNames($pageId);
            Log::debug('Имена - '.$names);
            $messageId =  $this->telegraph->message("Найдено:\n".$names)
                ->keyboard(Keyboard::make()->buttons([
                    Button::make($pageId.'/'.$totalPages)->action(''),
                    Button::make('>')->action('getCollection')->param('page_id',$pageId+1)->param('chat_id',$chatId)
                ]))
                ->send()->telegraphMessageId();

            Log::debug('Отправил сообщение');

            $chat->last_message_id = $messageId;
            $chat->save();
        }
        Chat::setBotState($chatId,Bot::NAMES_STATE);
    }

    public function getModel(int $number,int $chatId): void
    {
        $chat = Chat::get($chatId);
        $collection = $chat->collection;
        $chat->collection = null;
        $chat->save();
        $modelContent = $collection[$number];
        Log::debug($this->logArray($modelContent));
        $chat = Chat::get($this->getChatId());
        $category = $chat->category;
        $model = '';
        switch ($category) {
            case 'books':
                $model = new Book($modelContent);
                break;
            case 'audios':
                $model = new Audio($modelContent);
                break;
        }
        $this->telegraph->message($model->toString())->keyboard(Keyboard::make()->buttons([
            Button::make('Искать ещё раз')->action('search')->param('chat_id',$this->getChatId()),
            Button::make('К началу')->action('start')->param('chat_id',$this->getChatId())
        ]))->send();
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

    public function getClient(): MasterClient
    {
        return new MasterClient(config('search_sdk.master_key'));
    }

    public function logArray($array): bool|string
    {
        return json_encode($array,JSON_UNESCAPED_UNICODE);
    }




}
