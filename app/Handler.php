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
use Search\Sdk\collections\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Stringable;
use Search\Sdk\Clients\BasicClient;
use Search\Sdk\Clients\MasterClient;
use Search\Sdk\collections\AudioCollection;
use Search\Sdk\collections\BookCollection;
use Search\Sdk\Models\Audio;
use Search\Sdk\Models\Book;
use Search\Sdk\Models\Model;
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
        if ($botState!=Bot::NAMES_STATE){
            $chat->collection = null;
        }
        if($botState!=Bot::PARAMS_STATE and $botState!=Bot::PARAM_STATE){
            $chat->params = null;
        }
        $chat->save();
        switch ($botState){
            case Bot::SEARCH_STATE:
                $this->finalSearch($text);
                break;
            case Bot::PARAMS_STATE:
                $this->setParams($text);
                break;
            case Bot::NAMES_STATE:
                $this->getModel($text,$chatId);
                break;
            case Bot::PARAM_STATE:
                $this->finishSetting($text);
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
        $this->telegraph->chat($chatId)->message('Привет, я IPRBOT, твой личный помощник по поиску учебников в цифровых библиотеках экосистемы IPR SMART от компании IPR MEDIA.
        Ты сможешь найти книги по названию книги, издательству или автору. Найденные учебники доступны в рамках подписки твоего университета.
        Для работы в экосистеме IPR SMART необходимо авторизоваться на ресурсе (https://www.iprbookshop.ru). Логин и пароль можно взять в библиотеке.')
            ->send();
        $this->telegraph->chat($chatId)->message('Что желаете сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Поиск')->action('search')->param('chat_id',$chatId),
            Button::make('Помощь')->action('help')->param('chat_id',$chatId)
        ]))->send();
    }

    public function help(): void
    {
        $chatId = $this->getChatId();
        $this->telegraph->chat($chatId)->message('Тут пока ничего нет')->send();
    }

    public function search(): void
    {
        $chatId = $this->getChatId();
        $this->telegraph->chat($chatId)->message('Что вы хотите искать?')->keyboard(Keyboard::make()->buttons([
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
        $this->telegraph->chat($chatId)->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить поисковое выражение')->action('searchWord')->param('chat_id',$chatId),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id',$chatId)
        ]))->send();
    }

    public function searchWord(): void
    {
        $chatId = $this->getChatId();
        Chat::setBotState($chatId,Bot::SEARCH_STATE);
        $this->telegraph->chat($chatId)->message('Введите поисковое выражение')->send();
    }

    public function finalSearch(string $text): void
    {
        $chatId = $this->getChatId();
        Log::debug('chat id = '.$chatId);
        $chat = Chat::get($chatId);
        $chat->search = $text;
        $chat->save();
        $this->telegraph->chat($chatId)->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
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
        $searchMessageId = 0;
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
            if($chat->params!=null){
                $params = $chat->params;
            }
            else{
                $params = [];
            }
            $searchMessageId =  $this->telegraph->chat($chatId)->message('Поиск....')->send()->telegraphMessageId();
            $search = $chat->search;
            if($search == null){
                $search = '';
            }
            $content =  $collection->searchMaster($search,array_merge(['available' => 0],$params));
            $chat->collection = $content;
        }
        if(!$content){
            $this->telegraph->chat($chatId)->message('Ошибка - '.$collection->getMessage())->send();
            return;
        }
        $messageId = $chat->last_message_id;

        Log::debug("Message id = ".$messageId);
        $totalPages = intdiv(count($content),10);
        $pageId = $this->data->get('page_id');
        Log::debug("Page id = ".$pageId);
        if ($messageId!=null and $pageId!=null){
            Log::debug('Вошёл в условие');
            $names = $collection->getNames($content,$pageId);
            $buttons = $this->getButtons($chatId,$pageId,$totalPages);
            $this->telegraph->chat($chatId)->edit($messageId)->message($names)->keyboard(Keyboard::make()->buttons($buttons))->send();
        }
        else{
            $pageId = 1;
            $names = $collection->getNames($content,$pageId);
            $buttons = $this->getButtons($chatId,$pageId,$totalPages);
            Log::debug('Имена - '.$names);
            $this->telegraph->chat($chatId)->message("Найдено:".
                $collection->getTotal()." изданий\nЧтобы посмотреть подробную информацию,напишите нужный номер")->send();
            $messageId =  $this->telegraph->chat($chatId)->message($names)
                ->keyboard(Keyboard::make()->buttons($buttons))->send()->telegraphMessageId();
            $this->telegraph->chat($chatId)->deleteMessage($searchMessageId)->send();
            Log::debug('Отправил сообщение');
            $chat->last_message_id = $messageId;;
        }
        $chat->save();
        Chat::setBotState($chatId,Bot::NAMES_STATE);
    }

    public function getModel(int $number,int $chatId): void
    {
        $chat = Chat::get($chatId);
        $collection = $chat->collection;
        $modelContent = $collection[$number-1];
        Log::debug($this->logArray($modelContent));
        $chat = Chat::get($this->getChatId());
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book($modelContent),
            'audios' => new Audio($modelContent),
            default => new Model($modelContent),
        };
        $this->telegraph->chat($chatId)->message($model->toString())->keyboard(Keyboard::make()->buttons([
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
        $this->telegraph->chat($chatId)->message('Выберите параметры')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить параметры модели')->action('modelParams')->param('chat_id',$this->getChatId()),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id',$this->getChatId()),
        ]))->send();
    }

    /**
     * @throws Exception
     */
    public function modelParams(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book(),
            'audios' => new Audio(),
            default => new Model(),
        };
        Log::debug('class = '.get_class($model));
        $params =  $model->getStringParams();
        $this->telegraph->chat($chatId)->message("Выберите параметр: \n".$params)->send();
        Chat::setBotState($chatId,Bot::PARAMS_STATE);
    }

    public function setParams(string $text): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book(),
            'audios' => new Audio(),
             default => new Model(),
        };
        $params =  $model->getParams();
        $number = intval($text);
        if(!array_key_exists($number,$params)){
            $this->telegraph->chat($chatId)->message('Такого номера нет')->send();
            return;
        }
        $param = $params[$number-1];
        Log::debug('param = '.$param);
        if($model->isInt($param)){
            $this->telegraph->chat($chatId)->message('Выберите')->keyboard(Keyboard::make()->buttons([
                Button::make('Установить минимальное значение')->action('setParam')->param('chat_id',$this->getChatId())->param('param',$param)->param('param_set','_min'),
                Button::make('Установить максимальное значение')->action('setParam')->param('chat_id',$this->getChatId())->param('param',$param)->param('param_set','_max'),
                Button::make('Установить равное значение')->action('setParam')->param('chat_id',$this->getChatId())->param('param',$param)->param('param_set','')
            ]))->send();
        }
        else{
            $chat->param = $param;
            $chat->save();
            $this->telegraph->chat($chatId)->message('Введите значение')->send();
            Chat::setBotState($chatId,Bot::PARAM_STATE);
        }
    }

    public function setParam(): void
    {
        $param = $this->data->get('param');
       $paramSet =  $this->data->get('param_set');
       $chatId = $this->getChatId();
       $chat = Chat::get($chatId);
       $chat->param = $param.$paramSet;
       $chat->save();
       $this->telegraph->chat($chatId)->message('Введите значение')->send();
       Chat::setBotState($chatId,Bot::PARAM_STATE);
    }

    public function finishSetting(string $value): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $params = $chat->params;
        $param = $chat->param;
        $params[$param] = $value;
        $chat->params = $params;
        $chat->save();
        $this->telegraph->chat($chatId)->message('Значение успешно установлено')->keyboard(Keyboard::make()->buttons([
            Button::make('Установить поисковое выражение')->action('searchWord')->param('chat_id',$chatId),
            Button::make('Установить ещё один параметр')->action('params')->param('chat_id',$chatId),
            Button::make('Отправить поиск')->action('getCollection')->param('chat_id',$chatId),
            Button::make('Искать заново')->action('search')->param('chat_id',$chatId)
        ]))->send();
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

    public function getButtons(int $chatId,int $page,int $totalPages): array
    {
        $buttons = [
            Button::make($page.'/'.$totalPages)->action(''),
            Button::make('>')->action('getCollection')->param('page_id',$page+1)->param('chat_id',$chatId)
        ];
        if($page>1){
            array_unshift($buttons,
                Button::make('<')->action('getCollection')->param('page_id',$page-1)->param('chat_id',$chatId)
            );
        }
        return $buttons;
    }


}
