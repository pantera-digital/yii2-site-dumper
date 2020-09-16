<?php

namespace app;

use DateTime;
use Yii;
use linslin\yii2\curl\Curl;

/**
 * Модель для выгрузки страниц в автономное хранилище
 * 
 * Позволяет скачивать, сохранять и оптимизировать под нужную архитектуру
 * 
 * @method download(string $link, int $depth = 0, int $buffer = 0, bool $searchExternal = false) Скачивает страницу
 * @method save() Сохраняет скачанные страницы на диск
 * @method saveStatistics() Сохранение статистики
 * @method convertLinks(array $links = null) Конвертер ссылок
 * @method convertFiles(array $files = null) Конвертер страниц
 * @method filterLink(string $link) Фильтрация ссылки
 * 
 * @author Арсен Мирзаев red@hood.su
 * 
 * @todo
 * 1. $searchExternal переделать в $depthExterntal
 * 2. Исправить обработку ссылки: zakupki.gov.ru/data/common-info.html?regNumber=0816500000619001511
 * 4. Конструкцию <base> надо доработать на проверку уже существующего тега
 */
class Dumper extends \yii\base\Component
{
    /**
     * Глубина поиска страниц относительно первичной
     */
    protected $depth = 0;

    /**
     * Буфер страниц для скачивания
     */
    protected $buffer;

    /**
     * Флаг форсированного выполнения (перезаписи файлов)
     */
    protected $force;

    /**
     * Путь для сохранения файлов
     */
    protected $path;

    /**
     * Буфер страниц для скачивания
     */
    protected $searchExternal;

    /**
     * Регистр обработанных ссылок
     * 
     * 'Ссылка' => [
     *      [0] => 'URN файла'
     *      [1] => 'URL файла'
     *      [2] => 'URI файла для конвертации страниц'
     * ]
     */
    protected $links = [];

    /**
     * Буфер скачанных файлов
     * 
     * 'Файл (URN)' => [
     *      [0] => 'Тип'
     *      [1] => 'Данные'
     * ]
     * 
     * Типы:
     * [0] - HTML страница
     * [1] - документ ('.css', '.js', '.png'...)
     */
    protected $filesBuffer = [];

    /**
     * Регистр сохранённых файлов
     * 
     * 'Файл (URN)' => [
     *      [0] => 'Тип'
     *      [1] => 'Данные'
     * ]
     * 
     * Типы:
     * [0] - HTML страница
     * [1] - документ ('.css', '.js', '.png'...)
     */
    protected $files = [];

    /**
     * Блокировка циклов
     * 
     * Указывает работает основное скачивание или рекурсивное
     */
    protected $subdownload = false;

    /**
     * Количество новых найденных ссылок
     */
    protected $linksNew = 0;

    /**
     * Запрашиваемая ссылка
     */
    protected $target;

    /**
     * SCHEME/PROTOCOL запроса
     */
    public $connectionProtocol;

    /**
     * HOST запроса
     */
    public $connectionHost;

    /**
     * Собранная информация о выполнении
     * 
     * [0] => 'Запрошенный URI'
     * [1] => 'Статус выполнения (завершен или ошибка)'
     * [2] => [
     *     [0] => 'Количество найденных ссылок'
     *     [1] => 'Количество найденных ссылок без дубликатов'
     *     [2] => 'Количество обработанных ссылок'
     * ]
     * [3] => [
     *     [0] => 'Количество найденных HTML страниц'
     *     [1] => 'Количество конвертированных страниц'
     * ]
     * [4] => [
     *     [0] => 'Количество найденных документов (.png, .css, .pdf)'
     *     [1] => 'удалено'
     *     [2] => 'Найдено: Изображения',
     *     [3] => 'Найдено: Видеозаписи',
     *     [4] => 'Найдено: Аудиозаписи',
     *     [5] => 'Найдено: CSS',
     *     [6] => 'Найдено: JS',
     *     [7] => 'Найдено: Не опознано',
     * ]
     */
    public $statistics = [
        '',
        1,
        [
            0,
            0,
            0
        ],
        [
            0,
            0,
            0
        ],
        [
            0,
            0,
            0,
            0,
            0,
            0,
            0,
            0,
        ]
    ];

    /**
     * Время начала выполнения скрипта
     * 
     * Используется для вычисления времени выполнения и записи в статистику
     */
    public $timeStart;

    public function __construct()
    {
        // Начало отсчёта синтетического теста времени выполнеия для записи в статистику
        $this->timeStart = microtime(true);

        // if (YII_DEBUG) {
            register_shutdown_function(array(&$this, 'saveStatistics'));
        // }
    }

    /**
     * Скачивание страницы
     * 
     * @param string $link Ссылка
     * @param int $depth Глубина скачивания вложенных ссылок
     * @param int $buffer Буфер файлов
     * @param bool $force Флаг форсированного выполнения (с перезаписью существующих файлов)
     * @param string $path Свой путь для сохранения
     * @param bool $searchExternal Флаг поиска ссылок во внешних сайтах
     * 
     * @return Dump
     */
    public function download($link, $depth = 0, $buffer = 0, $force = false, $searchExternal = false, $path = '')
    {
        if (!$link = $this->filterLink($link)) {
            // Если ссылка не прошла фильтрацию
            return;
        }

        // Инициализация свойств
        if (!isset($this->buffer)) {
            $this->buffer = $buffer;
        }

        if (!isset($this->force)) {
            $this->force = $force;
        }

        if (!isset(Yii::$app->params['basePath'])) {
            Yii::$app->params['basePath'] = $path;
        }

        if (!isset($this->searchExternal)) {
            $this->searchExternal = $searchExternal;
        }

        if (!isset($this->connectionProtocol)) {
            // Проверка наличия domain.zone в ссылке на подобие: 'https://domain.zone/foo/bar'
            preg_match_all('/(.*)?:?(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $link, $linkMatch);

            $this->connectionProtocol = $linkMatch[1][0] ?? $_SERVER['REQUEST_SCHEME'] ?? $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'http';
        }

        if (!isset($this->connectionHost)) {
            // Проверка наличия domain.zone в ссылке на подобие: 'https://domain.zone/foo/bar'
            preg_match_all('/(.*)?:?(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $link, $linkMatch);

            // Проверка наличия domain.zone в ссылке на подобие: 'domain.zone/' или 'domain.zone' или 'subdomain.domain.zone'
            preg_match_all('/^([^\\\|\/|\\s]+\.[^\\\|\/|\\s]+)(\\\|\/)?$/', $link, $domainMatch);

            $this->connectionHost = $linkMatch[3][0] ?? $domainMatch[1][0] ?? $_SERVER['REQUEST_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
        }

        // Обработка буфера (это весь его код)
        if (count($this->filesBuffer) >= $this->buffer) {
            $this->save();
        }

        // Если скачивание является первым (ручной запрос)
        if (!isset($this->target)) {
            // Инициализация стартовой ссылки
            $this->target = $link;

            // Добавление стартовой ссылки в регистр
            array_unshift($this->links, $link);

            // Инициализация данных для статистики
            $this->statistics[0] = $this->target;
            $this->statistics[2][1] = 1;
        } else if ($this->subdownload && $depth === 0) {
            // Иначе если это дополнительное скачивание и глубина равна нулю

            $this->linksNew++;
            // Добавление стартовой ссылки в регистр
            array_unshift($this->links, $link);
        }

        // Обработка стартовой ссылки
        if (isset($this->target)) {
            // Внимание: $targetMatch не очищается и используется в коде ниже
            preg_match_all('/(.*)?:?(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $this->target, $targetMatch);
        }

        if (preg_match('/^[^\/|\\\]+\..+$/', $link)) {
            // Паттерн: 'domain.zone', 'domain.zone/foo/bar/index.html'
            $request = ($this->connectionProtocol ?? 'http') . '://' . $link;
        } else if (preg_match_all('/(^http.*(\/\/|\\\\)|^(\/\/|\\\\))(.*)(\/|\\\|$)(.*$)/iU', $link, $match)) {
            // Паттерн: 'https://domain.zone/foo/index.html', '//domain.zone/foo'
            $request = ($this->connectionProtocol ?? 'http') . '://' . $match[4][0] . '/' . $match[6][0];
        } else if (preg_match('/(^\/[^\/\\\\\s]+[^\s]*$|^\\\[^\/\\\\\s]+[^\\s]*$|^\/$|^\\\$)/', $link)) {
            // Паттерн: '/', '/foo/bar', '/foo/bar/index.html'
            $request = ($this->connectionProtocol ?? 'http') . '://' . ($targetMatch[3][0] ?? $this->connectionHost) . $link;
        } else if (preg_match('/(^[^\/\\\\\s\.]+(\/|\\\|$)([^\/\\\\\s]*$|[^\/\\\\\s]+(\/|\\\).*))/', $link)) {
            // Паттерн: 'foo/index.html', 'foo/bar/index.html', 'foo', 'foo/'
            $request = ($this->connectionProtocol ?? 'http') . '://' . ($targetMatch[3][0] ?? $this->connectionHost) . '/' . $link;
        } else {
            unset($this->links[$link]);
            return $this;
        }
        unset($match); // Очистка на всякий случай, так как переменные остаются

        // Выполнение запроса
        $this->filesBuffer[$link][1] = (new Curl())->setOption(CURLOPT_RETURNTRANSFER, true)
            ->setOption(CURLOPT_FOLLOWLOCATION, true)
            ->setOption(CURLOPT_SSL_VERIFYPEER, true)
            ->setOption(CURLOPT_USERAGENT, Yii::$app->params['useragent'])
            ->get($request);

        if (preg_match('/(https?:(\/\/|\\\\).+(\/|\\\).+|^(\/|\\\).+)(\.(?!php|htm)[A-z0-9]+)[^\/\\\s]*$/i', $link)) {
            // Если ссылка является документом ('.css', '.js', '.png'...)
            $this->filesBuffer[$link][0] = 1;
        } else {
            // Иначе расценивается как HTML страница для продолжения поиска
            preg_match_all('/(.*)?:?(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $link, $linkMatch);

            // Если это внутренний URL или domain.zone цели сходится с domain.zone обрабатываемой ссылки или есть разрешение на проход внешних ссылок
            if (empty($linkMatch[3][0]) || $targetMatch[3][0] === $linkMatch[3][0] || $this->searchExternal === true) {
                $this->filesBuffer[$link][0] = 0;
                $file = $this->filesBuffer[$link][1];
            } else {
                $this->filesBuffer[$link][0] = 0;
            }
        }
        unset($targetMatch, $linkMatch, $link); // Очистка на всякий случай, так как переменные остаются

        // Извлечение ссылок из страницы по свойствам href='' и src=''
        // Единственное место где добавляются найденные ссылки
        if (!empty($file) && $depth > 0 && preg_match_all('/(href|src)\\s?=\\s*[\"\']?((?!(\"|\'))(?!tel)(?!mailto)[^\"\']+)[\"\']/i', $file, $match)) {
            // Если файл скачан, глубина больше нуля и были найдены ссылки

            // Прибавление количества новых ссылок для обработки
            $this->linksNew += count($match[2]);

            // Прибавление количества новых ссылок для вывода в статистике
            $this->statistics[2][0] += count($match[2]);

            // Добавление ссылок в общий регистр
            foreach ($match[2] as $link) {
                if (!$link = $this->filterLink($link)) {
                    // Если ссылка не прошла фильтрацию
                    continue;
                }
                array_unshift($this->links, $link);
            }
        }
        unset($link, $file, $match); // Очистка на всякий случай, так как переменные остаются

        // Конвертация ссылок
        if (isset($this->links) && $this->linksNew) {
            // Если глубина больше ноля, ссылки существуют и счетчик новых ссылок больше ноля

            $this->convertLinks();
        }

        if (!$this->subdownload) {
            // Если это не дополнительное скачивание и текущая глубина не равна нулю

            // Устанавливается для того, чтобы работать в цикле
            $this->depth = $depth;

            // Скачиваем найденные страницы по установленной глубине поиска
            while ($this->depth-- > 0) {
                foreach ($this->links as $link => $type) {
                    $this->subdownload = true;
                    $this->download($link, $this->depth);
                    $this->subdownload = false;
                }

                $this->convertFiles($this->save(), true);
            }
            unset($link, $type);
        } 

        // Сохранение остатков ссылок после обработки
        $this->save();

        // Конвертация сохранённых файлов
        $this->convertFiles();

        return $this;
    }

    /**
     * Сохранение страницы
     * 
     * Получает на вход файлы из буфера и сохраняет на диске
     * На выходе будут перенесены в массив $this->files
     * 
     * @return array
     */
    public function save($files = null)
    {
        if (empty($files)) {
            $files = &$this->filesBuffer;
        }

        if (isset($this->links)) {
            $this->convertLinks();
        }

        $savedFiles = [];

        foreach ($files as $link => $file) {
            if (!empty($this->links[$link][1]) && !preg_match('/^(\/|\\\)/', $this->links[$link][1])) {
                // Если в начале ссылки нет слеша и ссылка не, то добавить слеш
                $this->links[$link][1] = '/' . $this->links[$link][1];
            }

            // Проверка существования каталога и его создание 
            if (!file_exists(Yii::$app->params['basePath'] . $this->links[$link][1])) {
                mkdir(Yii::$app->params['basePath'] . $this->links[$link][1], 0755, true);
            }

            // Сохранение файла
            if (!file_exists(Yii::$app->params['basePath'] . $this->links[$link][1] . $this->links[$link][0]) || $this->force) {
                if (file_put_contents(Yii::$app->params['basePath'] . $this->links[$link][1] . $this->links[$link][0], $file[1])) {
                    $this->files[$link][0] = $file[0];
                    $this->files[$link][1] = $file[1];
                }
            }
            $savedFiles[$link] = $files[$link];
            unset($files[$link]);
        }
        unset($link, $file); // Очистка на всякий случай, так как переменные остаются

        // Указание сборщику статистики, что парсер успешно завершил свою работу
        $this->statistics[1] = 0;

        return $savedFiles;
    }

    /**
     * Сохранить статистику
     * 
     * Возвращает статус сохранения (true/false)
     * 
     * @return bool
     */
    public function saveStatistics()
    {
        // Запись времени окончания работы скрипта
        $timeFinish = microtime(true);

        $i = new DateTime(Yii::$app->params['timezone'] ?? 'Europe/Moscow');

        $date = date_format($i, 'Y-m-d');
        $dateFull = date_format($i, 'Y.m.d H:i:s');

        $request            = $this->statistics[0]                ?? 'Ошибка';
        $time               = ($timeFinish - $this->timeStart)    ?? 'Ошибка';
        $status             = $this->statistics[1] === 0 ? 'Успех' : 'Ошибка';

        $linksCount         = $this->statistics[2][0]             ?? 'Ошибка';
        $linksProcessed     = $this->statistics[2][1]             ?? 'Ошибка';
        $linksProcessedReal = $this->statistics[2][2]             ?? 'Ошибка';

        $pagesCount         = $this->statistics[3][0]             ?? 'Ошибка';
        $pagesProcessed     = $this->statistics[3][1]             ?? 'Ошибка';

        $filesCount         = $this->statistics[4][0]             ?? 'Ошибка';

        $imagesCount        = $this->statistics[4][2]             ?? 'Ошибка';
        $videosCount        = $this->statistics[4][3]             ?? 'Ошибка';
        $audiosCount        = $this->statistics[4][4]             ?? 'Ошибка';
        $cssCount           = $this->statistics[4][5]             ?? 'Ошибка';
        $jsCount            = $this->statistics[4][6]             ?? 'Ошибка';
        $unidentifiedCount  = $this->statistics[4][7]             ?? 'Ошибка';

        if (!file_exists(Yii::getAlias('@runtime/logs'))) {
            mkdir(Yii::getAlias('@runtime/logs'), 0755, true);
        }
        $file = fopen(Yii::getAlias('@runtime/logs') . '/' . $date . uniqid('_DUMPER_', true) . '.log', 'a+');

        fwrite($file, <<<EOT
///////////////////////////////////////////////////////////
///                Статистика выполнения                ///
///////////////////////////////////////////////////////////

                       Дата:   $dateFull

                     Запрос:   $request
           Время выполнения:   $time секунд
          Статус выполнения:   $status

///////////////////////////////////////////////////////////

             Найдено ссылок:   $linksCount
          Обработано ссылок:   $linksProcessed
      Из них без дубликатов:   $linksProcessedReal

//////////////////////////////////////////////////////////

            Найдено страниц:   $pagesCount
     Конвертировано страниц:   $pagesProcessed

///////////////////////////////////////////////////////////

         Найдено документов:   $filesCount

                Изображения:   $imagesCount
                Видеозаписи:   $videosCount
                Аудиозаписи:   $audiosCount
                CSS скрипты:   $cssCount
                 JS скрипты:   $jsCount
                Не опознано:   $unidentifiedCount

///////////////////////////////////////////////////////////
EOT
        );
        fclose($file);

        unset($i, $date, $dateFull, $file); // Очистка на всякий случай, так как переменные остаются
    }

    /**
     * Конвертер ссылок
     * 
     * Приводит ссылки к заданному архитектурой виду
     * 
     * @return array
     */
    private function convertLinks($links = null)
    {
        if (empty($links)) {
            $links = &$this->links;
        }

        while ($this->linksNew >= 0) {
            // Инициализация ссылки и копии для будущего поиска в файлах
            if (!array_key_exists($this->linksNew, $links)) {
                // Подготовка к следующей итерации цикла
                $this->linksNew--;
                continue;
            }
            $link = $rawLink = $links[$this->linksNew];

            if (is_array($link)) {
                // Если это уже конвертированная ссылка
                continue;
            }

            $uri = $this->initLink($link);

            preg_match_all('/(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $uri, $uriMatch);
            preg_match_all('/(\/\/|\\\\)(.*)((\/|\\\|$).*$)/U', $this->target, $targetMatch);

            if ($targetMatch[2][0] === $uriMatch[2][0]) {
                $location = '';
            } else {
                $location = Yii::$app->params['externalLinksPath'] . '/' . $uriMatch[2][0];
            }
            unset($uriMatch, $targetMatch);

            // Инициализация ссылки
            if ($uri === $this->target || $uri . '/' === $this->target || $uri === $this->target . '/' || $uri . '\\' === $this->target || $uri === $this->target . '\\' || $uri === '/' || $uri === '\\') {
                // Если это первый запуск (запрошенная, главная ссылка)

                // Создание ссылки
                $links[$rawLink][0] = '/index.html';
                $links[$rawLink][1] = '';
                $links[$rawLink][2] = '/index.html';
            } else if (preg_match('/\\.css/i', $uri)) {
                // Если это CSS файл

                // Получение последнего каталога (имени файла с расширением), например: '/index.html'
                if (preg_match_all('/[^\/\\\\\s]+$/', $uri, $file)) {
                    // Создание ссылки
                    $links[$rawLink][0] = '/' . $file[0][0];
                    $links[$rawLink][1] = $location . Yii::$app->params['cssPath'];
                    $links[$rawLink][2] = $links[$rawLink][1] . $links[$rawLink][0];
                }

                // Обновление статистики
                $this->statistics[4][0]++;
                $this->statistics[4][5]++;
            } else if (preg_match('/\\.js/i', $uri)) {
                // Если это JS файл

                // Получение последнего каталога (имени файла с расширением), например: '/index.html'
                if (preg_match_all('/[^\/\\\\\s]+$/', $uri, $file)) {
                    // Создание ссылки
                    $links[$rawLink][0] = '/' . $file[0][0];
                    $links[$rawLink][1] = $location . Yii::$app->params['jsPath'];
                    $links[$rawLink][2] = $links[$rawLink][1] . $links[$rawLink][0];
                }

                // Обновление статистики
                $this->statistics[4][0]++;
                $this->statistics[4][6]++;
            } else if (preg_match('/(\\.png|\\.jpeg|\\.jpg|\\.webp|\\.gif|\\.svg|\\.ico)/i', $uri)) {
                // Если это изображение

                // Получение последнего каталога (имени файла с расширением), например: '/index.html'
                if (preg_match_all('/[^\/\\\\\s]+$/', $uri, $file)) {
                    // Создание ссылки
                    $links[$rawLink][0] = '/' . $file[0][0];
                    $links[$rawLink][1] = $location . Yii::$app->params['imgPath'];
                    $links[$rawLink][2] = $links[$rawLink][1] . $links[$rawLink][0];
                }

                // Обновление статистики
                $this->statistics[4][0]++;
                $this->statistics[4][2]++;
            } else if (preg_match('/(https?:(\/\/|\\\\).+(\/|\\\).+|^(\/|\\\).+)(\.(?!php|htm)[A-z0-9]+)[^\/\\\s]*$/i', $uri)) {
                // Если это неопознанный документ (очень затратное выражение, но по другому никак)

                // Получение последнего каталога (имени файла с расширением), например: '/index.html'
                if (preg_match_all('/[^\/\\\\\s]+$/', $uri, $file)) {
                    // Создание ссылки
                    $links[$rawLink][0] = '/' . $file[0][0];
                    $links[$rawLink][1] = $location . Yii::$app->params['docsPath'];
                    $links[$rawLink][2] = $links[$rawLink][1] . $links[$rawLink][0];
                }

                // Обновление статистики
                $this->statistics[4][0]++;
                $this->statistics[4][7]++;
            } else if (preg_match_all('/(\/\/|\\\\)(.*)((\/|\\|$).*$)/U', $uri, $uriMatch)) {
                // Иначе, если это обрабатывается универсально или как HTML документ

                if (isset($uriMatch[3][0])) {
                     // Если есть путь к файлу, например 'https://domain.zone/это/обязательно/index.html'
                    if (preg_match_all('/^([^\/\\\\\s\.]*[^\\s\.]+)([^\/\\\]*\.html|[^\/\\\]*\.php|[^\/\\\]*\.htm)?$/U', $uriMatch[3][0], $uriSplit)) {
                        // Если в URI не найден URN (файл с расширением, например: 'index.php')

                        if (empty($uriSplit[2][0])) {
                            $uriSplit[2][0] = '/index.html';
                        }

                        // Создание ссылки
                        $links[$rawLink][0] = $uriSplit[2][0];
                        $links[$rawLink][1] = $location . Yii::$app->params['pagesPath'] . $uriSplit[1][0];
                        $links[$rawLink][2] = $links[$rawLink][1] . $links[$rawLink][0];
                    }
                } else {
                    // Иначе обрабатывается как пустая ссылка, например 'https://domain.zone'

                    // Создание ссылки
                    $links[$rawLink][0] = '/index.html';
                    $links[$rawLink][1] = $location . Yii::$app->params['pagesPath'] . '/' . $uriMatch[1][0];
                    $links[$rawLink][2] = $links[$rawLink][1] . '/index.html';
                }

                // Прибавление к количеству найденных страниц
                $this->statistics[3][0]++;
            }

            // Удаление обработанной ссылки и оставшихся переменных
            unset($links[$this->linksNew], $rawLink, $location, $uriSplit, $match, $file, $uri);

            // Прибавление к количеству обработанных ссылок
            $this->statistics[2][1]++;

            // Подготовка к следующей итерации цикла
            $this->linksNew--;
        }

        // Количество обработанных ссылок без дубликатов
        $this->statistics[2][2] = count($this->links);

        return $links;
    }

    /**
     * Конвертер страниц
     * 
     * Преобразует ссылки в тексте (HTML документе)
     * Возвращает массив не найденных файлов
     * 
     * @param array $files Файлы для конвертации
     * 
     * @return array
     */
    private function convertFiles($files = null)
    {
        if (empty($files)) {
            $files = &$this->files;
        }

        foreach ($files as $link => &$file) {
            if ($file[0] === 0 && isset($this->links[$link]) && file_exists(Yii::$app->params['basePath'] . $this->links[$link][1] . $this->links[$link][0]) && $content = file_get_contents(Yii::$app->params['basePath'] . $this->links[$link][1] . $this->links[$link][0])) {
                // Если метаданные файла указывают, что он является HTML документом

                if (preg_match_all('/(href|src)\\s?=\\s*[\"\']?((?!(\"|\'))(?!tel)(?!mailto)[^\"\']+)[\"\']/i', $content, $match)) {
                    // Если найдены ссылки

                    // Конвертация
                    foreach ($match[2] as $rawLink) {
                        if (!$rawLink = $this->filterLink($rawLink)) {
                            // Если ссылка не прошла фильтрацию
                            continue;
                        }

                        if (!array_key_exists($rawLink, $this->links)) {
                            continue;
                        }

                        $content = preg_replace('/(\"|\')' . preg_quote($rawLink, '/') . '(\"|\')/', '".' . $this->links[$rawLink][2] . '"', $content);
                    }

                    // Инъекция тега <base> в страницу, чтобы работали относительные пути
                    if (preg_match_all('/(.*<head>)(.*)/si', $content, $contentMatch)) {
                        // Если удалось найти <head> в странице

                        // Определяем вложенность страницы
                        if (preg_match_all('/([^\\\|\/|\\s]+)/', $this->links[$link][1], $urlMatch)) {
                            $catalogsDepth = count($urlMatch[1]);
                        }
                        
                        $content = $contentMatch[1][0] . "\n<base href=\"" . str_repeat('../', $catalogsDepth ?? 0) . '">'. $contentMatch[2][0];
                    }

                    // Сохранение файла
                    if (file_put_contents(Yii::$app->params['basePath'] . $this->links[$link][1] . $this->links[$link][0], $content)) {
                        unset($this->files[$link]);

                        // Прибавление к количеству конвертированных страниц
                        $this->statistics[3][1]++;
                    }
                }
            } else if ($file[0] !== 0) {
                // Если файл не является HTML документом

                unset($this->files[$link]);
            } else {
                // Иначе воспринимается как не HTML документ, который не требует конвертацию

                continue;
            }
        }
        unset($link, $file); // Очистка на всякий случай, так как переменные остаются

        return $files;
    }

    /**
     * Фильтрация ссылки
     * 
     * Перед инициализацией ссылка проверяется фильтрами
     * 
     * @return ?string
     */
    private function filterLink($link)
    {
        // Проверка существования URL в чёрном списке
        if (!empty(Yii::$app->params['regBlackList']) && preg_match('/' . Yii::$app->params['regBlackList'] . '/', $link)) {
            return null;
        }

        // Преобразование слешей в Unix стиль, унификация
        $link = preg_replace('/\\\/', '/', $link);

        return $link;
    }

    /**
     * Инициализация ссылки
     * 
     * Подготовка к конвертации
     * 
     * @return string
     */
    private function initLink($link)
    {
        // Подготовка ссылок перед обработкой
        // Разбиение ссылки на каталоги: 'https://domain.zone/foo/bar/index.html' на 'https:', 'domain.zone', 'foo', 'bar', 'index.html'
        if (preg_match_all('/([^\\\|\/|\\s]+)/', $link, $uriMatch)) {
            // Определение того, что URI является полноценным и имеет протокол подключения, например: 'https:', 'ssh:', 'mail:'
            if (preg_match('/^.*:$/', $uriMatch[0][0])) {
                $uri = $uriMatch[0][0] . '//' . $uriMatch[0][1];
                $uriMatch[0] = array_slice($uriMatch[0], 2);
            } else {
                $uri = $this->connectionProtocol . ':' . '//' . $this->connectionHost;
            }

            // Замена всех фрагментов URI от символов, которые Windows не даёт записывать в именах файлов и каталогов на '@'
            // На данный момент достаточно заменять все символы на один, так как обратная конвертация не потребуется, а шанс конфликта имён минимален
            foreach ($uriMatch[0] as &$piece) {
                $piece = preg_replace('/(\\\|\/|\:|\*|\?|\"|\<|\>|\|)/', '@', $piece);
            }
            unset($piece);

            // Сборка новой ссылки из фрагментов оригинальной
            foreach ($uriMatch[0] as $piece) {
                $uri .= '/' . $piece;
            }
            unset($piece);

        } else if ($link === '/' || $link === '\\') {
            // Иначе, если ссылка ведёт на главную страницу сайта

            if (!$this->searchExternal) {
                $uri = $this->connectionProtocol . ':' . '//' . $this->connectionHost;
            }

        } else {
            // Иначе всё обрабатывается как ссылка на текущего хоста

            if (!preg_match('/^(\/|\\\)/', $link)) {
                $link = '/' . $link;
            }
            $link = preg_replace('/(\/|\\\)$/', '', $link);

            $uri = $this->connectionProtocol . ':' . '//' . $this->connectionHost . $link;
        }
        unset($link, $uriMatch, $site);

        return $uri;
    }
}
