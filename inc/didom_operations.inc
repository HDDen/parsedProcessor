<?php
use DiDom\Document;
use DiDom\Query;
use DiDom\Element;
use function logger\writeLog;

/************************************************************************************************
 * Операции над контентом
 ***********************************************************************************************/
/**
 * @param $data
 *
 * Выполняем действия над контентом
 */
function didom_process_content(&$data){
	$document = new Document($data);

	// Операции

	// Удалим h1
	didom_content_deleteH1($document);
	// Выкачиваем аттачи
	didom_content_downloadAttaches($document, true);

	// Возврат результата
	$html = didom_innerHTML_helper($document);

	// форматирование
	$beautifier = new Beautify_Html();
	$html = $beautifier->beautify($html);

	return $html;
}

/**
 * @param $document
 * @return mixed
 *
 * Удалит H1 из контентной части
 */
function didom_content_deleteH1(&$document){
	$h1 = $document->find('h1');
	if (count($h1)){
		foreach ($h1 as $elem){
			$elem->remove();
		}
	}
	return $document;
}

/**
 * @param $document
 *
 * Обработка и скачивание всех img и пришпиленных документов
 */
function didom_content_downloadAttaches(&$document, $store_in_list_instead = false){
	// Нужно сначала выкачать все картинки, затем пройти href всех ссылок

	// Проверим наличие у нас общей папки
	$download_root = WORKDIR_ROOT . '/_downloadedfiles';
	if(!is_dir($download_root)) {
		mkdir($download_root, 0777, true);
	}

	// проверим наличие подпапки изображений
    $imgs_prefix_folder = '/_imgs';
	$download_imgs = WORKDIR_ROOT . '/_downloadedfiles' . $imgs_prefix_folder;
	if(!is_dir($download_imgs)) {
		mkdir($download_imgs, 0777, true);
	}

	// проверим наличие подпапки документов
    $docs_prefix_folder = '/_docs';
	$download_docs = WORKDIR_ROOT . '/_downloadedfiles' . $docs_prefix_folder;
	if(!is_dir($download_docs)) {
		mkdir($download_docs, 0777, true);
	}

	// Фильтр расширений
	$attach_extensions_filter = array('html', 'htm');

	// установим домен
	$root_domain = 'http://www.kavraion.ru';
	$short_domain = 'kavraion.ru';

	// Поиск изображений и документов в странице
	$attachments = $document->find('img[src], a[href*="."]');
	if (count($attachments)){
		foreach ($attachments as $attach){
			// поймём, что за элемент
			$tagname = $attach->tag;
			if ($tagname == 'img'){
				$href_attr = 'src';
				$add_prefix_folder = $imgs_prefix_folder;
				$attach_type_dir = $download_imgs;
			} else if ($tagname == 'a'){
				$href_attr = 'href';
                $add_prefix_folder = $docs_prefix_folder;
				$attach_type_dir = $download_docs;
			}

			// получим href
			$href = $attach->getAttribute($href_attr);

			// если href не спарсился, или там data:base64, или там меньше 4 символов - пропускаем
			if (!$href || (stripos($href, ';base64') !== false) || (strlen($href) < 4) ){
				writeLog('didom_content_downloadAttaches(): href некорректен, '.$href);
				continue;
			}

			// Все ссылки будем воспринимать как абсолютные.
			// Также детект, ссылка наша или сторонняя
			$path_array = parse_url_custom($href, $short_domain);

			// Проверим на фильтр по расширениям
			if ( isset($path_array["extension"]) && (in_array($path_array["extension"], $attach_extensions_filter) )){
				writeLog('didom_content_downloadAttaches(): '.$href.' отфильтрован по расширению');
				continue;
			}

			// если ссылка не наша, пропускаем
			if (!$path_array['is_internal']){
				writeLog('didom_content_downloadAttaches(): '.$href.' не является внутренней, пропуск');
				continue;
			}

			// Сгенерируем серверный путь
			if (!isset($path_array['path'])){
				writeLog('didom_content_downloadAttaches(): нельзя построить внутренний путь по '.$href.', пропуск');
				continue;
			}
			$local_path = $attach_type_dir . '/' . ltrim($path_array['path'], '/');

			// Проверим, может файл уже существует
			if (file_exists($local_path)){
				writeLog('didom_content_downloadAttaches(): файл '.$href.', уже скачан ранее');
				continue;
			}

			// сгенерируем полный url, или воспользуемся изначальным
			if (!isset($path_array['host'])){
				$href_downloadBy = $root_domain . '/' . ltrim($href, '/');
				writeLog('didom_content_downloadAttaches(): строим полный путь сами, $href = '.$href);
			} else {
				$href_downloadBy = $href;
				writeLog('didom_content_downloadAttaches(): абсолютный путь, $href = '.$href);
			}

			// Проверим ответ сервера
			$serverResponce = serverResponceCheck($href_downloadBy);
			if ($serverResponce['status'] !== 200){
				writeLog('didom_content_downloadAttaches(): '.$href.' вернул код '.$serverResponce['status'].', скачивание пропущено');
				continue;
			}

			// Скачаем файл
			// Или можем сохранить его в списке для скачивания - чтобы скачать сторонними многопоточными средствами
			if (!$store_in_list_instead){

                // Создадим подпапку
                $image_full_dir = $attach_type_dir . '/' . ltrim($path_array["dirname"], '/');
                if(!is_dir($image_full_dir)) {
                    mkdir($image_full_dir, 0777, true);
                }

				$download_result = file_put_contents($local_path, fopen($href_downloadBy, 'r'));
				if ($download_result) writeLog('didom_content_downloadAttaches(): скачано байт '.$download_result);
			} else {
				writeLog('didom_content_downloadAttaches(): активен режим $store_in_list_instead, заносим ссылку в список');

				// В какой список будем заносить ссылку
				if ($tagname == 'img'){
                    $list_file = $download_root . '/to_download_img.txt';
                } else if ($tagname == 'a'){
                    $list_file = $download_root . '/to_download_docs.txt';
                } else {
                    $list_file = $download_root . '/to_download.txt';
                }

				file_put_contents($list_file, $href_downloadBy.PHP_EOL, FILE_APPEND | LOCK_EX);
			}

			// Заменим href у элемента
            if (@$path_array['is_internal'] && @$path_array['path']){
                $updated_href = $add_prefix_folder . '/' . ltrim($path_array['path'], '/');
                $attach->setAttribute($href_attr, $updated_href);
                writeLog('didom_content_downloadAttaches(): заменили $href, ' . $updated_href);
            }

		}
	}
	unset($imgs);
}





/************************************************************************************************
 * Служебные функции
 ***********************************************************************************************/

/**
 * @param $url
 * @param $internal_domain
 * @return array|false|int|string|null
 *
 * Парсит url и получает доп. инфу по нему (путь к файлу, и внутренняя ли ссылка)
 */
function parse_url_custom($url, $internal_domain){
	$result = parse_url($url);
	$is_internal = false; // дефолт

	if (isset($result['host'])){
		if (stripos($result['host'], $internal_domain) !== false){
			$is_internal = true; // ссылка внутренняя, с нашего сервера
		}
	} else {
		$is_internal = true; // если хост не спарсился, точно ссылка внутренняя
	}

	// установим внутренняя ли это ссылка
	$result['is_internal'] = $is_internal;

	// установим путь к файлу
	if (isset($result['path'])){
		$pathinfo = pathinfo($result['path']);
		$result['dirname'] = isset($pathinfo['dirname']) ? $pathinfo['dirname'] : false;
		$result['basename'] = isset($pathinfo['basename']) ? $pathinfo['basename'] : false;
		$result['extension'] = isset($pathinfo['extension']) ? $pathinfo['extension'] : false;
	} else {
		$result['dirname'] = $result['basename'] = $result['extension'] = false;
	}

	return $result;
}
/**
 * @param $document
 *
 * Пытаемся получить внутренний html от body
 */
function didom_innerHTML_helper(&$document){
	$body = $document->first('body');

	if ($body) {
		$returned = $body->innerHTML();
	} else {
		$returned = $document->toElement()->innerHtml();
	}

	return $returned;
}

/**
 * @param $data
 * @return mixed
 *
 * Пример функции
 */
function didom_process_DUMMY(&$data){
	$document = new Document($data);

	// Операции

	// Возврат результата
	$html = didom_innerHTML_helper($document);
	return $html;
}