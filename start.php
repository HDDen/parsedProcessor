<?php
set_time_limit(0);
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('WORKDIR_ROOT', __DIR__);

require_once WORKDIR_ROOT . '/vendor/autoload.php';
require_once WORKDIR_ROOT . '/utils/kamaparser/kamaparser.php';
require_once WORKDIR_ROOT . '/utils/logger/logger.php';
require_once WORKDIR_ROOT . '/utils/htmlbeauty/htmlbeauty.php';
require_once WORKDIR_ROOT . '/utils/statusChecker/statusChecker.php';
require_once WORKDIR_ROOT . '/utils/dataCache/classDataCache.php';
require_once WORKDIR_ROOT . '/utils/dataCache/classSiteSettings.php';
require_once WORKDIR_ROOT . '/inc/didom_operations.inc';
use function logger\writeLog;

define('DEBUGCSV', 'true');
define('MADM_LOGGER_PATH', WORKDIR_ROOT.'/log.txt');

use DiDom\Document;
use DiDom\Query;
use DiDom\Element;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Что нужно: открыть csv, пройти каждую строку, поудалять лишние элементы (h1),
 * собрать href разных ссылок - если относится к текущему сайту, убрать домен и привести к абсолютному виду. Подпапку можно задать.
 * если сторонний - ничего не делаем
 */
$parserScriptStart = microtime(true); // Замер времени начала

start(); // Запуск

$parserScriptTime = microtime(true) - $parserScriptStart;
echo 'Время выполнения: '.$parserScriptTime;
writeLog(PHP_EOL.'Время выполнения: '.$parserScriptTime);

function start(){
	// переключатель режима дебага
	$debug = false;
	if (defined(DEBUGCSV) && (DEBUGCSV === 'true')) $debug = true;
	//

	// Зафиксируем старт
	if ($debug) writeLog(PHP_EOL.PHP_EOL.'Старт задачи');

	$useInputCSV = false; // Получать из CSV или из xls. От этого зависит, какой обработчик будем использовать
	$useOutputCSV = false; // выводить как CSV
	$outputPhpSpreadSheet = true; // чем писать CSV

	// Имя исходного CSV
	$src = '_in/demo-29-10-2021.xlsx';
	// Имя итогового CSV
	$dest = '_out/result_demo-29-10-2021.xlsx.csv';

	writeLog('Входной файл: "'.$src.'"');

	// Кэширование
	// Создаём объект для кэширования обработанных данных с УНИКАЛЬНЫМ для этих данных ID - по имени входного файла
	$dataCache_processed = new DataCache($src);
	// Запрашиваем инициализацию кэша
	$getProcessedDataFromCache = $dataCache_processed->initCacheData();

	$processed = false; // умолчание
	if ($getProcessedDataFromCache) {
		// Получаем кэшированные данные
		$processed = $dataCache_processed->getCacheData();
	}

	if ($processed){
		writeLog("Загрузили из кэша");
	} else {

		if ($useInputCSV){
			// Загрузим CSV
			$data = kama_parse_csv_file($src);
		} else {
			$xlsReader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
			$spreadsheet = $xlsReader->load($src);
			$data = $spreadsheet->getSheet(0)->toArray();
		}

		if ($debug){
			writeLog("Загрузили файл '".$src."'");
			//print_r( $data );
		}

		// Процессинг полученных данных выведем в отдельную функцию
		$processed = processParsedData($data); // false / обработанный массив для обратного преобразования

		// Запишем в кэш
		$dataCache_processed->updateCacheData($processed);
	}

	// Запишем результат

	// Если будем писать xlsx, или csv через PhpSpreadSheet, подготовим площадку для этого
	if (!$useOutputCSV || ($useOutputCSV && $outputPhpSpreadSheet)){
		// Создаем экземпляр класса электронной таблицы
		$exportSpreadsheet = new Spreadsheet();
		// Получаем текущий активный лист
		$sheet = $exportSpreadsheet->getActiveSheet();
		// Записываем данные
		$sheet->fromArray($processed, NULL, 'A1');
	}

	if ($useOutputCSV){
		if ($outputPhpSpreadSheet){
			// Пишем в CSV средствами PhpSpreadSheet
			if (isset($exportSpreadsheet)){
				$writer = new \PhpOffice\PhpSpreadsheet\Writer\Csv($exportSpreadsheet);
				$writer->save($dest);
				writeLog('Сохранили CSV через PhpSpreadSheet');
			} else {
				writeLog('Должны писать CSV через PhpSpreadSheet, но не создали $exportSpreadsheet. Скорее всего, ничего не запишется');
			}
		} else {
			$output = kama_create_csv_file($processed, $dest);
		}
	} else {
		// Пишем в xlsx
		if (isset($exportSpreadsheet)){
			$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($exportSpreadsheet);
			//Сохраняем файл в текущей папке, в которой выполняется скрипт.
			//Чтобы указать другую папку для сохранения.
			//Прописываем полный путь до папки и указываем имя файла
			$writer->save($dest.'.xlsx');
			writeLog('Сохранили xlsx');
		} else {
			writeLog('Должны писать в xlsx, но не создали $exportSpreadsheet. Скорее всего, ничего не запишется');
		}
	}

	writeLog('Записали результат в "'.$dest.'"');

	// Зафиксируем конец задачи
	if ($debug) writeLog('Конец задачи');
}

function processParsedData(&$data){
	// переключатель режима дебага
	$debug = false;
	if (defined(DEBUGCSV) && (DEBUGCSV === 'true')) $debug = true;
	//

	// Зафиксируем вход в функцию
	if ($debug) writeLog(PHP_EOL.PHP_EOL.'processParsedData(): Начали');

	// Проверим на пустоту
	if (empty($data)) {
		if ($debug) writeLog('processParsedData(): Передан пустой массив $data. Уходим');
		return false;
	}

	// Сопоставим индексы столбцов реальным данным. И назначим на каждый индекс обработчики
	$col_operations = array(
		'0' => [], // просто url
		'2' => ['didom_process_breadcrumbs'], // этот столбец - html хлебных крошек. Для них выполним эти функции
		'3' => ['didom_process_content'], // весь контент страницы
		'4' => ['didom_process_date'], // дата публикации
		'6' => [], // title
		'8' => [], // H1
	);

	// Перебор элементов.
	foreach ($data as $row_index => $row){ // каждую строку
		if ($debug) writeLog('processParsedData(): Строка №'.$row_index);
		foreach ($row as $column_index => $column_data){ // а теперь каждый столбец
			// а теперь сравнение.
			// если в массиве $col_operations есть операции для этого индекса, выполняем их по-очереди
			$col_index_strval = strval($column_index);
			if ( array_key_exists($col_index_strval, $col_operations) ) {
				// получим список операций - проверим, массив ли, и не пуст ли
				if (is_array($col_operations[$col_index_strval]) && !empty($col_operations[$col_index_strval])){
					// выполняем все операции
					foreach ($col_operations[$col_index_strval] as $op_name){
						// если очередная функция существует
						if (function_exists($op_name)){
							$op_result = $op_name($column_data);
							$data[$row_index][$column_index] = $op_result; // обработаем и присвоим результат
							if ($debug) writeLog('processParsedData(): '.$op_name.' выполнена');
						} else {
							if ($debug) writeLog('processParsedData(): Функции '.$op_name.' не существует!');
						}
					}
				}
			}
		}
	}

	if ($debug) writeLog('processParsedData(): Прошли все операции, возвращаем результат');

	// Возвращаем массив
	return $data;
}

