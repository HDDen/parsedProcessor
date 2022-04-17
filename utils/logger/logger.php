<?php

/*
require_once 'utils/logger.php';
use function logger\writeLog;
*/

namespace logger;

use \ZipArchive as ZipArchive;
use function defined;
use function dirname;

/* Использование

$logdata = ''.PHP_EOL;
$arrayToFile = json_encode($result);
$logdata .= 'Распечатка массива $result:'.PHP_EOL.$arrayToFile.PHP_EOL;
writeLog($logdata);


writeLog($logdata, true); - true если начинаем писать лог в начале всего скрипта, и нужно проконтролировать размер накопившегося лога.


*/

/*

short snippet

$logdata = print_r('',true);
$logfile = $_SERVER['DOCUMENT_ROOT'] . '/www/log.txt';
date_default_timezone_set( 'Europe/Moscow' );
$date = date('d/m/Y H:i:s', time());
//file_put_contents($logfile, $date.': '.$logdata.PHP_EOL, FILE_APPEND | LOCK_EX);

*/

// Сами ф-ии
// Старая вызывалась в новой, во втором методе упаковки

/*function old_writeLog($logdata = ''){
    global $logfile;
    date_default_timezone_set( 'Europe/Moscow' );
    $date = date('d/m/Y H:i:s', time());
    file_put_contents($logfile, $date.': '.$logdata.PHP_EOL, FILE_APPEND | LOCK_EX);
}*/

if (!function_exists('writeLog')){
    function writeLog($logdata = '', $newstarted = false, $try_echo = false){
	    // Логи. Замена echo
	    $logfile = dirname(__FILE__).'/log.txt';

	    if (defined('MADM_LOGGER_PATH') && (MADM_LOGGER_PATH != '')){
		    $logfile = MADM_LOGGER_PATH;
	    }

    	date_default_timezone_set( 'Europe/Moscow' );
        // Контроль размера файла. Если он больше определенного размера, помещаем в архив и пересоздаем.
        $maxLogSize = 1000000; // мегабайт

        if ($newstarted){
            $actualLogSize = filesize($logfile);

            if ($actualLogSize >= $maxLogSize){
                $date = date('d-m-Y_H-i-s', time());

                $zipped = false;
                if (class_exists('ZipArchive')){
                    $zip_file = dirname($logfile).'/_log_'.$date.'.zip';
                    $zip = new \ZipArchive;

                    if ($zip->open($zip_file, ZIPARCHIVE::CREATE)!==TRUE)
                    {
                        exit("cannot open <$zip_file>\n");
                    }
                    $zip->addFile($logfile,'log.txt');
                    $zip->close();
                    $zipped = true;
                }

                // Второй метод сжатия - если не отработал первый.
                $gzipped = false;
                if ( !$zipped ){

                    $bkp_to = dirname($logfile);
                    $bkp_name = '_log_'.$date.'.tar.gz';

                    $toarchive = shell_exec('tar -zcvf '.$bkp_to.'/'.$bkp_name.' '.$logfile.' ');
                    //$toarchive = shell_exec('tar -zcvf file.tar.gz /path/to/filename ');

                    $newlogdata = 'Прошли стадию паковки в гз'.PHP_EOL;
                    $newlogdata .= var_export($toarchive, true);
                    //old_writeLog($newlogdata);

                    $gzipped = true;
                }

                if ( $zipped || $gzipped ){
                    unlink($logfile);
                }
            }
        }

        $date = date('d/m/Y H:i:s', time());
        file_put_contents($logfile, $date.': '.$logdata.PHP_EOL, FILE_APPEND | LOCK_EX);

        // вывод на экран
        if ($try_echo){
            echo $logdata.'<br />';
            flush();
            usleep(1);
        }

    }
}


?>