<?php
set_time_limit(0);
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('WORKDIR_ROOT', __DIR__);

require_once __DIR__ . '/vendor/autoload.php';
require_once 'utils/kamaparser/kamaparser.php';
require_once 'utils/logger/logger.php';
require_once 'utils/htmlbeauty/htmlbeauty.php';
require_once 'utils/statusChecker/statusChecker.php';
require_once 'inc/didom_operations.inc';
use function logger\writeLog;

define('DEBUGCSV', 'true');
define('MADM_LOGGER_PATH', WORKDIR_ROOT.'/log.txt');

use DiDom\Document;
use DiDom\Query;
use DiDom\Element;

/*
 * Что нужно: открыть csv, пройти каждую строку, поудалять лишние элементы (h1),
 * собрать href разных ссылок - если относится к текущему сайту, убрать домен и привести к абсолютному виду. Подпапку можно задать.
 * если сторонний - ничего не делаем
 */

start();

function start(){
	// переключатель режима дебага
	$debug = false;
	if (defined(DEBUGCSV) && (DEBUGCSV === 'true')) $debug = true;
	//

	// Зафиксируем старт
	if ($debug) writeLog(PHP_EOL.PHP_EOL.'Старт задачи');

	// Имя исходного CSV
	$src = '_in/demo.csv';
	// Имя итогового CSV
	$dest = '_out/demo-result.csv';

	// Загрузим CSV
	$data = kama_parse_csv_file($src);
	if ($debug){
		writeLog("Загрузили файл '".$src."'");
		//print_r( $data );
	}

	// Процессинг полученных данных выведем в отдельную функцию
	$processed = processParsedData($data); // false / обработанный массив для обратного преобразования

	// Запишем CSV
	$output = kama_create_csv_file($data, $dest);

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

