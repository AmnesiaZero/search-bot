<?php

namespace App;

use App\Models\Bot;
use App\Models\Chat;
use DefStudio\Telegraph\Exceptions\FileException;
use DefStudio\Telegraph\Handlers\WebhookHandler;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use DefStudio\Telegraph\Storage\StorageDriver;
use DefStudio\Telegraph\Telegraph;
use Exception;
use Faker\Core\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Stringable;
use Search\Sdk\Clients\MasterClient;
use Search\Sdk\collections\AudioCollection;
use Search\Sdk\collections\AuthorCollection;
use Search\Sdk\collections\BookCollection;
use Search\Sdk\collections\Collection;
use Search\Sdk\collections\CollectionsCollection;
use Search\Sdk\collections\FreePublicationCollection;
use Search\Sdk\Models\Audio;
use Search\Sdk\Models\Author;
use Search\Sdk\Models\Book;
use Search\Sdk\Models\FreePublication;
use Search\Sdk\Models\Model;
use Vkrsmart\Sdk\Models\Document;
use Vkrsmart\Sdk\Models\Report;


class Handler extends WebhookHandler
{

    public Telegraph $telegraph;

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
        Log::debug('Text - ' . $text);
        $chatId = $this->message->chat()->id();
        $chat = Chat::get($chatId);
        $botState = $chat->bot_state;
        if ($botState != Bot::NAMES_STATE) {
            $chat->collection = null;
        }
        if ($botState==Bot::SEARCH_STATE) {
            $chat->params = null;
        }
        $chat->save();
        switch ($botState) {
            case Bot::SEARCH_STATE:
                $this->finalSearch($text);
                break;
            case Bot::NAMES_STATE:
                $this->getModel($text, $chatId);
                break;
            case Bot::PARAM_STATE:
                $this->finishSetting($text);
                break;
            case Bot::UPLOAD_STATE:
                $this->uploadDocument();
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
        Chat::setBotState($this->getChatId(), Bot::NEUTRAL_STATE);
        $this->telegraph->chat($chatId)->message('Привет, я IPRBOT, твой личный помощник по поиску учебников в цифровых библиотеках экосистемы IPR SMART от компании IPR MEDIA.
        Ты сможешь найти книги по названию книги, издательству или автору. Найденные учебники доступны в рамках подписки твоего университета.
        Для работы в экосистеме IPR SMART необходимо авторизоваться на ресурсе (https://www.iprbookshop.ru). Логин и пароль можно взять в библиотеке.')
            ->send();
        $this->telegraph->chat($chatId)->message('Что желаете сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Поиск')->action('search')->param('chat_id', $chatId),
            Button::make('Проверка оригинальности')->action('vkrsmart')->param('chat_id',$chatId),
            Button::make('Помощь')->action('help')->param('chat_id', $chatId)
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
        $categories = [
            'books' => 'Книги',
            'audios' => 'Аудио',
            'collections' => 'Коллекции',
            'authors' => 'Авторы',
            'free_publications' => 'Бесплатные публикации'
        ];
        $buttons = [];
        foreach ($categories as $key => $value) {
            $buttons[] = Button::make($value)->action('setCategory')->param('chat_id', $chatId)->param('category', $key);
        }
        $this->telegraph->chat($chatId)->message('Что вы хотите искать?')->keyboard(Keyboard::make()->buttons($buttons))->send();
    }

    public function setCategory(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $chat->category = $this->data->get('category');
        $chat->save();
        $this->telegraph->chat($chatId)->message('Что вы хотите сделать?')->keyboard(Keyboard::make()->buttons([
            Button::make('Настроить поисковое выражение')->action('searchWord')->param('chat_id', $chatId),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id', $chatId)
        ]))->send();
    }

    public function searchWord(): void
    {
        $chatId = $this->getChatId();
        Chat::setBotState($chatId, Bot::SEARCH_STATE);
        $this->telegraph->chat($chatId)->message('Введите поисковое выражение')->send();
    }

    public function finalSearch(string $text): void
    {
        $chatId = $this->getChatId();
        Log::debug('chat id = ' . $chatId);
        $chat = Chat::get($chatId);
        $chat->search = $text;
        $chat->save();
        $this->telegraph->chat($chatId)->message("Поисковое выражение успешно установлено\nЧто хотите сделать?")->keyboard(Keyboard::make()->buttons([
            Button::make('Отправить поиск')->action('getCollection')->param('chat_id', $this->getChatId()),
            Button::make('Настроить параметры поиска')->action('params')->param('chat_id', $this->getChatId()),
        ]))->send();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function getCollection(): void
    {
        Log::debug('Вошёл в getCollection');
        $searchMessageId = 0;
        $client = $this->getSearchClient();
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $collection = match ($category) {
            'books' => new BookCollection($client),
            'audios' => new AudioCollection($client),
            'collections' => new CollectionsCollection($client),
            'authors' => new AuthorCollection($client),
            'free_publications' => new FreePublicationCollection($client),
             default => new Collection($client),
        };
        Log::debug('Прошёл match');
        //Если коллекция уже хранится в БД,она подгружается из неё(при рекурсивном вызове функции)
        if ($chat->collection != null) {
            Log::debug('Подгрузка content с БД');
            $content = $chat->collection;
        }
        //Если коллекции в БД нет,то отправляется запрос к API через SDK
        else {
            Log::debug('Запрос к API');
            if ($chat->params != null) {
                $params = $chat->params;
            } else {
                $params = [];
            }
            $searchMessageId = $this->telegraph->chat($chatId)->message('Поиск....')->send()->telegraphMessageId();
            $search = $chat->search;
            if ($search == null) {
                $search = '';
            }
            //Запрос к API
            $content = $collection->searchMaster($search, array_merge(['available' => 0], $params));
            $this->telegraph->chat($chatId)->
            message('Найдено '.$collection->getTotal()." изданий\nЧтобы получить подробную информацию,напишите нужный номер")->send();
            if (!$collection->getSuccess()) {
                $this->errorMessage($collection->getMessage(), $chatId);
                return;
            }
            if ($collection->getTotal() == 0) {
                $this->telegraph->chat($chatId)->message('К сожалению,по этому запросу ничего не нашлось')->keyboard(Keyboard::make()->buttons($this->getDefaultButtons($chatId)))->send();
                return;
            }
            $total = $collection->getTotal();
            if (is_int($total)) {
                $this->telegraph->chat($chatId)->message("Найдено:" .
                    $collection->getTotal() . " изданий\nЧтобы посмотреть подробную информацию,напишите нужный номер")->send();
            }
            Log::debug('Total = ' . $collection->getTotal());
            $chat->collection = $content;
        }
        if (!$content) {
            $this->errorMessage($collection->getMessage(), $chatId);
            return;
        }
        $messageId = $chat->last_message_id;
        $totalPages = intdiv(count($content), 10) + 1;
        Log::debug("Message id = " . $messageId);
        $pageId = $this->data->get('page_id');
        Log::debug("Page id = " . $pageId);
        //Условие для проверки на рекурсивный вызов(редактирование существующего сообщения)
        if ($messageId != null and $pageId != null) {
            Log::debug('Вошёл в условие');
            $names = $collection->getNames($content, $pageId);
            $buttons = $this->getCollectionButtons($chatId, $pageId, $totalPages);
            $this->telegraph->chat($chatId)->edit($messageId)->message($names)->keyboard(Keyboard::make()->buttons($buttons))->send();
        }
        //Если находится только 1 страница,то кнопки не подгружаются
        elseif ($totalPages == 1) {
            $pageId = 1;
            $names = $collection->getNames($content, $pageId);
            $this->telegraph->chat($chatId)->message($names)->send();
        }
        //Отправка изначального сообщения
        else {
            $pageId = 1;
            $names = $collection->getNames($content, $pageId);
            $buttons = $this->getCollectionButtons($chatId, $pageId, $totalPages);
            Log::debug('Имена - ' . $names);
            $messageId = $this->telegraph->chat($chatId)->message($names)
                ->keyboard(Keyboard::make()->buttons($buttons))->send()->telegraphMessageId();
            $this->telegraph->chat($chatId)->deleteMessage($searchMessageId)->send();
            Log::debug('Отправил сообщение');
            $chat->last_message_id = $messageId;;
        }
        $chat->save();
        Chat::setBotState($chatId, Bot::NAMES_STATE);
    }

    public function getModel(int $number, int $chatId): void
    {
        $chat = Chat::get($chatId);
        $collection = $chat->collection;
        $modelContent = $collection[$number - 1];
        Log::debug('Model = ' . $this->logArray($modelContent));
        $chat = Chat::get($this->getChatId());
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book($modelContent),
            'audios' => new Audio($modelContent),
            'collections' => new \Search\Sdk\Models\Collection($modelContent),
            'authors' => new Author($modelContent),
            'free_publications' => new FreePublication($modelContent),
            default => new Model($modelContent),
        };
        $this->telegraph->chat($chatId)->message($model->toString())->keyboard(Keyboard::make()->buttons($this->getDefaultButtons($chatId)))->send();
    }

    /**
     * @return void
     */
    public function params(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book(),
            'audios' => new Audio(),
            'collections' => new \Search\Sdk\Models\Collection(),
            'authors' => new Author(),
            'free_publications' => new FreePublication()
        };
        Log::debug('class = ' . get_class($model));
        $params = $model->getParams();
        $buttons = [];
        foreach ($params as $key => $value) {
            Log::debug('key = '.$key.' value = '.$value);
            $buttons[] = Button::make($value)->action('setParams')->param('param', $key)->param('chat_id', $chatId);
        }
        $this->telegraph->chat($chatId)->message("Выберите параметр")->keyboard(Keyboard::make()->buttons($buttons))->send();
        Chat::setBotState($chatId, Bot::PARAMS_STATE);
    }


    public function setParams(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $category = $chat->category;
        $model = match ($category) {
            'books' => new Book(),
            'audios' => new Audio(),
            'collections' => new \Search\Sdk\Models\Collection(),
            'authors' => new Author(),
            'free_publications' => new FreePublication(),
            default => new Model(),
        };
        $param = $this->data->get('param');
        Log::debug('param = ' . $param);
        if ($model->isInt($param)) {
            Log::debug('Вошёл в условие');
            $this->telegraph->chat($chatId)->message('Выберите значение:')->keyboard(Keyboard::make()->buttons([
                Button::make('Установить минимальное значение')->action('setParam')->param('chat_id',$chatId)
                ->param('param',$param)->param('param_set','_min'),
                Button::make('Установить максимальное значение')->action('setParam')->param('chat_id',$chatId)
                    ->param('param',$param)->param('param_set','_max'),
                Button::make('Установить равное значение')->action('setParam')->param('chat_id',$chatId)
                    ->param('param',$param)->param('param_set',''),
            ]))->send();
            Log::debug('Думаю,проблема с кнопками');
        } else {
            $chat->param = $param;
            $chat->save();
            $this->telegraph->chat($chatId)->message('Введите значение')->send();
            Chat::setBotState($chatId, Bot::PARAM_STATE);
        }
    }

    public function setParam(): void
    {
        Log::debug('Вошёл в setParam');
        $param = $this->data->get('param');
        Log::debug('param 2 = '.$param);
        $paramSet = $this->data->get('param_set');
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $chat->param = $param . $paramSet;
        $chat->save();
        $this->telegraph->chat($chatId)->message('Введите значение')->send();
        Chat::setBotState($chatId, Bot::PARAM_STATE);
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
        $buttons = $this->getFinalSettingsButtons($chatId);
        $this->telegraph->chat($chatId)->message("Значение успешно установлено\nЧто хотите сделать дальше?")->keyboard(Keyboard::make()->buttons($buttons))->send();
    }

    public function cleanParams(): void
    {
        $chatId = $this->getChatId();
        $chat = Chat::get($chatId);
        $chat->params = null;
        $chat->save();
        $buttons = $this->getFinalSettingsButtons($chatId);
        $this->telegraph->chat($chatId)->message('Параметры успешно очищены')->keyboard(Keyboard::make()->buttons($buttons))->send();
    }

    public function vkrsmart():void
    {
         $chatId = $this->getChatId();
         $this->telegraph->chat($chatId)->message("Для проверки на оригинальность загрузите работу")->send();
         Chat::setBotState($chatId,Bot::UPLOAD_STATE);
    }

    /**
     * @throws FileException
     * @throws Exception
     */
    public function uploadDocument(): void
    {
        $chatId = $this->getChatId();
        $messageDocument = $this->message->document();
        $storagePath = config('vkrsmart.storage_path');
        $this->telegraph->store($messageDocument,$storagePath);
        $messageId = $this->telegraph->chat($chatId)->message('Загрузка......')->send()->telegraphMessageId();
        $client = $this->getVkrClient();
        $document = new Document($client);
        $file = Storage::get($storagePath);
        $document->uploadDocument($file);
        $report = new Report($client);
//        if(!$report->get($document->getId())){
//             $this->errorMessage($report->getMessage(),$chatId);
//             return;
//        }
          Log::debug("Отправка отчёта");
          $this->telegraph->chat($chatId)->deleteMessage($messageId)->send();
          $this->telegraph->chat($chatId)->message("Получен отчёт:\n". $report->toString())->keyboard(Keyboard::make()->buttons([
              Button::make('В начало')->action('start')->param('chat_id',$chatId),
              Button::make('Отправить другую работу')->action('vkrsmart')->param('chat_id',$chatId)
          ]))->send();
    }



    public function getChatId()
    {
        if ($this->message != null) {
            return $this->message->chat()->id();
        }
        return $this->data->get('chat_id');
    }
    public function logArray($array): bool|string
    {
        return json_encode($array, JSON_UNESCAPED_UNICODE);
    }

    public function getDefaultButtons(int $chatId): array
    {
        return [
            Button::make('Искать ещё раз')->action('search')->param('chat_id', $chatId),
            Button::make('К началу')->action('start')->param('chat_id', $chatId)
        ];
    }


    public function getSearchClient(): MasterClient
    {
        return new MasterClient(config('search_sdk.master_key'));
    }
    public function getVkrClient():\Vkrsmart\Sdk\clients\MasterClient
    {
        return new \Vkrsmart\Sdk\clients\MasterClient(config('vkrsmart.master_key'));
    }

    public function errorMessage(string $message, int $chatId): void
    {
        $this->telegraph->chat($chatId)->message("К сожалению,возникла ошибка\nОписание - " . $message)
            ->keyboard(Keyboard::make()->buttons($this->getDefaultButtons($chatId)))->send();
        Chat::setBotState($chatId,Bot::NEUTRAL_STATE);
    }

    public function getFinalSettingsButtons(int $chatId):array
    {
        $buttonsAction = [
            'searchWord' => 'Установить поисковое выражение',
            'params' => 'Установить ещё один параметр',
            'cleanParams' => 'Очистить все параметры',
            'getCollection' => 'Отправить поиск',
            'search' => 'Искать заново'
        ];
        $buttons = [];
        foreach ($buttonsAction as $action=>$name){
            $buttons[] = Button::make($name)->action($action)->param('chat_id',$chatId);
        }
        return $buttons;
    }

    public function getCollectionButtons(int $chatId, int $page, int $totalPages): array
    {
        $buttons = [
            Button::make($page . '/' . $totalPages)->action(''),
            Button::make('>')->action('getCollection')->param('page_id', $page + 1)->param('chat_id', $chatId)
        ];
        if ($page > 1) {
            array_unshift($buttons,
                Button::make('<')->action('getCollection')->param('page_id', $page - 1)->param('chat_id', $chatId)
            );
        }
        return $buttons;
    }

}
