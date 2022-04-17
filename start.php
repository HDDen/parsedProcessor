<?php
session_start();
$_SESSION['didom_announces'] = array();

set_time_limit(0);
ini_set('memory_limit','-1');
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

const WORKDIR_ROOT = __DIR__;
const MADM_LOGGER_PATH = WORKDIR_ROOT . '/log.txt';

require_once WORKDIR_ROOT . '/vendor/autoload.php';
require_once WORKDIR_ROOT . '/utils/kamaparser/kamaparser.php';
require_once WORKDIR_ROOT . '/utils/logger/logger.php';
require_once WORKDIR_ROOT . '/utils/htmlbeauty/htmlbeauty.php';
require_once WORKDIR_ROOT . '/utils/statusChecker/statusChecker.php';
require_once WORKDIR_ROOT . '/utils/dataCache/classDataCache.php';
require_once WORKDIR_ROOT . '/utils/dataCache/classSiteSettings.php';
require_once WORKDIR_ROOT . '/inc/didom_operations.inc';
use function logger\writeLog;

/*
 * Что нужно: открыть csv/xls, пройти каждую строку, поудалять лишние элементы (h1),
 * собрать href разных ссылок - если относится к текущему сайту, убрать домен и привести к абсолютному виду. Подпапку можно задать.
 * если сторонний - ничего не делаем
 */
$parserScriptStart = microtime(true); // Замер времени начала

start(); // Запуск

$parserScriptTime = microtime(true) - $parserScriptStart;
echo 'Время выполнения: '.$parserScriptTime;
writeLog(PHP_EOL.'Время выполнения: '.$parserScriptTime, false, true);

function start(){
	///////////////////////////////////////////////////////////////////////
	// Настройки
	///////////////////////////////////////////////////////////////////////
	$useInputCSV = true; // Получать из CSV или из xls. От этого зависит, какой обработчик будем использовать
	$readCSVbyKama = false; // Читать CSV через kama или phpspreadsheet
	$useOutputCSV = false; // выводить как CSV
	$outputPhpSpreadSheet = true; // чем писать CSV

	// Имя исходного
	$src = '_in/03-12-2021_potentially_empty_pages.csv';
	// Имя итогового CSV
	$dest = '_out/result_03-12-2021_potentially_empty_pages.xlsx';

	// Пропуск первых столбцов. Условно, первый столбец - заголовки
	$skip_first_n = 1; // количество, а не индекс!
	// Пропуск остальных столбцов. Уже количество. $skip_after = 10; - после 10 обработанных остальные будут отбрасываться
	$skip_after = 0;

    // Формирование ссылок для скачивания в формате aria2 либо wget
    define('FILELINKS_ARIA2_FORMAT', true); // false = wget
	///////////////////////////////////////////////////////////////////////


	// Зафиксируем старт
	writeLog(PHP_EOL.PHP_EOL.'Старт задачи', false, true);

	writeLog('Входной файл: "'.$src.'"', false, true);

	// Кэширование.
    // Будет два вида кэша.
    // Первый - загрузили xlsx и узбогоились (на 20000 строк занимает 6 минут)
    // Второй - уже обработанный xlsx, прямо перед экспортом в результирующий xlsx

    $data_array = false; // умолчание
    // Разбор входного файла на массив. Создаем и инициализируем
    $cache_openedSrc = new DataCache($src.filemtime(WORKDIR_ROOT.'/'.$src));
    // $cache_openedSrc->setCacheOff(); // отключаем, если нужно
    $cache_openedSrc_inited = $cache_openedSrc->initCacheData();

    if ($cache_openedSrc_inited){ // если инициализация прошла успешно
        // Получаем кэшированные данные
        $data_array = $cache_openedSrc->getCacheData();
    }

    if ($data_array){
        writeLog("Разбор xlsx/csv - загрузили из кэша", false, true);
        unset($cache_openedSrc);
    } else {
        if ($useInputCSV){
            // Загрузим CSV
	        if ($readCSVbyKama){
		        $data_array = kama_parse_csv_file($src);
	        } else {
		        $csvReader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
		        $spreadsheet = $csvReader->load($src);
		        $data_array = $spreadsheet->getSheet(0)->toArray();
	        }
        } else {
            $xlsReader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $xlsReader->load($src);
            $data_array = $spreadsheet->getSheet(0)->toArray();
        }
        writeLog("Загрузили файл '".$src."'", false, true);

        // Запишем в кэш
        if ($cache_openedSrc_inited){
            // Запишем кэшированные данные
            $cache_openedSrc->updateCacheData($data_array);
            unset($cache_openedSrc);
            writeLog("Разбор xlsx/csv - записали в кэш", false, true);
        }
    }

    // Кэширование процессинга
    $cache_processed = new DataCache($src.'_processed_'.filemtime(WORKDIR_ROOT.'/'.$src));
    $cache_processed->setCacheOff(); // отключаем, если нужно
    $cache_processed_inited = $cache_processed->initCacheData();

    if ($cache_processed_inited) { // если инициализация прошла успешно
        $data_array_cached = $cache_processed->getCacheData(); // Получим данные из кэша, поместим во времянку
    }

    // Если данные получены, передадим их в основной массив данных, и обнулим временный
    if (isset($data_array_cached) && $data_array_cached){
        $data_array = $data_array_cached;
        unset($data_array_cached);
        writeLog("Обработанные данные - загрузили из кэша", false, true);
    } else {
        // Процессинг полученных данных выведем в отдельную функцию
        $data_array = processParsedData($data_array, $skip_first_n, $skip_after); // false / обработанный массив для обратного преобразования
        // Запишем в кэш
        if ($cache_processed_inited){ // если инициализация прошла успешно
            // Запишем кэшированные данные
            $cache_processed->updateCacheData($data_array);
            writeLog("Обработанные данные - записали в кэш", false, true);
            unset($cache_processed);
        }
    }

    // Сопоставим анонсы. Сначала в строку заголовка, в конец, допишем столбцы с метками - img и descr
    // Затем, перебираем массив с анонсами. Берем url, ищем его в элементах общего массива. Находим - мержим поля (дописываем в конец)
    if (isset($_SESSION['didom_announces']) && !empty($_SESSION['didom_announces'])){
        // Запомним индексы крайних элементов. Добавлять данные анонсов будем начиная с этой позиции
        $announce_img_columnIndex = count($data_array[0]);
        $announce_descr_columnIndex = $announce_img_columnIndex + 1;

        $data_array[0][] = 'img анонса';
        $data_array[0][] = 'Текст анонса';


        // Перебор анонсов
        foreach ($_SESSION['didom_announces'] as $announce_index => $announce_data){

            // сопоставим столбец с uri через псевдонимы
            $data_uri_index = 0;
            foreach ($data_array[0] as $alias_index => $alias_text){
                if (trim($alias_text) === 'Address') {
                    $data_uri_index = $alias_index;
                    writeLog('Анонсы: сопоставили столбец url оригинала. №'.$alias_index);
                }
            }

            // перебор данных основного массива. Проверяем на совпадение uri анонса и uri строки массива
            foreach ($data_array as $data_array_rowIndex => $data_array_rowData){
                if ($data_array_rowIndex === 0) continue; // пропуск заголовков
                //
                if (trim($announce_data['url']) === trim($data_array_rowData[$data_uri_index])){
                    // нашли совпадение, дописываем в конец. Сначала картинка, затем
                    $data_array[$data_array_rowIndex][$announce_img_columnIndex] = $announce_data['img']; // присвоим столбец img
                    $data_array[$data_array_rowIndex][$announce_descr_columnIndex] = $announce_data['descr']; // присвоим столбец descr

                    writeLog('Нашли анонс '.$announce_data['url'].', присвоили к строке '.$data_array_rowIndex, false, true);
                }
            }
        }

        // Также отдельно зафиксируем результат парсинга новостей
        // Добавим заголовки
        array_unshift($_SESSION['didom_announces'], array('url', 'img', 'descr'));
        // Сохранение
        exportResultToFile($_SESSION['didom_announces'], $dest.'_announces.xlsx', false, true);
        writeLog('Также сохранили анонсы', false, true);
    }
    session_destroy();

    // Разобьём путь на столбцы.
    // Сначала зафиксируем индекс, откуда можем начать их дописывать.
    // Затем, на каждой итерации, будем обновлять заголовки в первой строке по количеству вложенности
    $splitted_cats_startIndex = count($data_array[0]);
    $breadcrumb_text_index = false;
    foreach ($data_array as $data_row_index => $data_row_data){
        if ($data_row_index === 0){
            // узнаем индекс столбца со структурой пути
            foreach ($data_row_data as $temporary_index => $temporary_value){
                if (trim($temporary_value) === '.path 1'){
                    $breadcrumb_text_index = $temporary_index;
                    writeLog('Индекс столбца с хлебными крошками - '.$temporary_index);
                    break;
                }
            }
            continue;
        } else {
            if ($breadcrumb_text_index === false) {
                writeLog('Мы не смогли определить позицию крошек, разбор крошек пропущен');
                break;
            }

            // Определили индекс столбца крошек, разделяем его на массив
            $bread_arr = explode(' > ', $data_row_data[$breadcrumb_text_index]);

            if (empty($bread_arr)) continue;

            // Определяем длину массива с крошками, на основании этого резервируем заголовки + добавляем эти данные
            foreach ($bread_arr as $bread_arr_index => $bread_arr_data){
                $data_array[0][ ($splitted_cats_startIndex + $bread_arr_index) ] = 'Категория '.($bread_arr_index + 1);
                // и добавляем данные
                // нужно заполнить пустотой всё между

                $temp_target_index = $splitted_cats_startIndex + $bread_arr_index;

                // Если крайний индекс +1 меньше чем нужный, подобавлять пустых столбцов
                if ( count($data_array[$data_row_index]) < $temp_target_index){
                    // устанавливаем количество, сколько надо добавить, и делаем это в цикле
                    $to_add = $temp_target_index - count($data_array[$data_row_index]);
                    while ($to_add > 0){
                        $data_array[$data_row_index][] = '';
                        $to_add--;
                    }
                }

                $data_array[$data_row_index][($splitted_cats_startIndex + $bread_arr_index)] = $bread_arr_data;
            }
        }


    }

	// Запишем результат
    exportResultToFile($data_array, $dest, $useOutputCSV, $outputPhpSpreadSheet);
    writeLog('Записали результат в "'.$dest.'"', false, true);



	// Зафиксируем конец задачи
    writeLog('Конец задачи', false, true);
}

/**
 * Пишет массив данных в xlsx/csv
 *
 * @param $data_array
 * @param $dest
 * @param $useOutputCSV
 * @param $outputPhpSpreadSheet
 * @return bool
 * @throws \PhpOffice\PhpSpreadsheet\Writer\Exception
 */
function exportResultToFile($data_array, $dest, $useOutputCSV, $outputPhpSpreadSheet){
    // Запишем результат
    // Если будем писать xlsx, или csv через PhpSpreadSheet, подготовим площадку для этого
    if (!$useOutputCSV || ($useOutputCSV && $outputPhpSpreadSheet)){
        // Создаем экземпляр класса электронной таблицы
        $exportSpreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        // Получаем текущий активный лист
        $sheet = $exportSpreadsheet->getActiveSheet();
        // Записываем данные
        $sheet->fromArray($data_array, NULL, 'A1');
    }

    if ($useOutputCSV){
        if ($outputPhpSpreadSheet){
            // Пишем в CSV средствами PhpSpreadSheet
            if (isset($exportSpreadsheet)){
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($exportSpreadsheet);
                $writer->save($dest);
                writeLog('Сохранили CSV через PhpSpreadSheet', false, true);
            } else {
                writeLog('Должны писать CSV через PhpSpreadSheet, но не создали $exportSpreadsheet. Скорее всего, ничего не запишется', false, true);
            }
        } else {
            $output = kama_create_csv_file($data_array, $dest);
            if ($output){
                writeLog('Сохранили CSV через kama_create_csv_file()', false, true);
            } else {
                writeLog('Не удалось сохранить CSV через kama_create_csv_file()', false, true);
            }
        }
    } else {
        // Пишем в xlsx
        if (isset($exportSpreadsheet)){
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($exportSpreadsheet);
            //Сохраняем файл в текущей папке, в которой выполняется скрипт.
            //Чтобы указать другую папку для сохранения.
            //Прописываем полный путь до папки и указываем имя файла
            $writer->save($dest.'.xlsx');
            writeLog('Сохранили xlsx', false, true);
        } else {
            writeLog('Должны писать в xlsx, но не создали $exportSpreadsheet. Скорее всего, ничего не запишется', false, true);
        }
    }

    return true;
}

function processParsedData(&$data, $skip_first_n = 1, $skip_after = false){

	// Зафиксируем вход в функцию
	writeLog(PHP_EOL.PHP_EOL.'processParsedData(): Начали', false, true);

	// Проверим на пустоту
	if (empty($data)) {
		writeLog('processParsedData(): Передан пустой массив $data. Уходим', false, true);
		return false;
	}

	// Сопоставим столбцы ожидаемым данным, и назначим на каждый свои обработчики
    // Первая строка - всегда заголовки, поэтому воспользуемся ей, как алиасами, вместо сопоставления по индексу столбцов
    $col_operations = array(
        'Address' => [], // uri
        '.path 1' => ['didom_process_breadcrumbs'], // столбец с таким названием в первой строке - html хлебных крошек. Для них выполним эти функции
        'Content 1' => ['didom_collect_announces', 'didom_process_content'], // весь контент страницы
        'date html 1' => ['didom_process_date'], // дата публикации
    );

	// Перебор элементов.

    $skip_first_n = $skip_first_n ? : 1; // ГРУБЕЙШИЙ костыль на пропуск первой строки

	foreach ($data as $row_index => $row){ // каждую строку
		writeLog('processParsedData(): Строка №'.$row_index);

		// Пропускаем заголовки. В переменной число, это количество, а не индекс
		if ($skip_first_n){
			if ($row_index < $skip_first_n){
				writeLog('processParsedData(): пропускаем начальную строку', false, true);
				continue;
			}
		}

		// Пропуск остатков - если делаем лимит на обработку только первых N
		if ($skip_after){
			if ($row_index >= ($skip_first_n + $skip_after) ){
				writeLog('processParsedData(): пропускаем из-за лимита в '.$skip_after." эл-ов", false, true);
				continue;
			}
		}

		foreach ($row as $column_index => $column_data){ // а теперь каждый столбец
			// а теперь сравнение.
            // по индексу столбца получим его алиас (значение столбца с этим же индексом, но из первой строки)
            // по алиасу (как по ключу массива) проверим наличие операций для столбца
            $alias = trim($data[0][$column_index], ' ');
            if (array_key_exists($alias, $col_operations)) {
				// получим список операций - проверим, массив ли, и не пуст ли
				if (is_array($col_operations[$alias]) && !empty($col_operations[$alias])){
					// выполняем все операции
					foreach ($col_operations[$alias] as $op_name){
						// если очередная функция существует
						if (function_exists($op_name)){
							$op_result = $op_name($column_data);
							$data[$row_index][$column_index] = $op_result; // обработаем и присвоим результат
							writeLog('processParsedData(): '.$op_name.' выполнена');
						} else {
							writeLog('processParsedData(): Функции '.$op_name.' не существует!');
						}
					}
				}
			}
		}
	}

	writeLog('processParsedData(): Прошли все операции, возвращаем результат', false, true);

	// Возвращаем массив
	return $data;
}

