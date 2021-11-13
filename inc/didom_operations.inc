<?php

use DiDom\Document;
use DiDom\Query;
use DiDom\Element;
use function logger\writeLog;

/************************************************************************************************
 * Операции над контентом
 ***********************************************************************************************/
/**
 * Выполняем действия над контентом
 *
 * @param $data
 * @return mixed|string
 */
function didom_process_content(&$data){
	// Проверка на пустоту
	if ( preg_match("/^(\s)*$/mu", $data) || !$data ){
		writeLog('didom_process_content(): получена пустая $data - "'.$data.'"');
		return $data;
	}

	// Строим документ
	$document = new Document($data);

	// Операции

	// Удалим h1
	didom_content_deleteH1($document);
	// Выкачиваем аттачи
	didom_content_downloadAttaches($document, true);
	// Чистим пустые <p>
    didom_content_deleteEmptyElements($document);
    // Удалим дату из самой статьи
	didom_content_deleteDate($document);

	// Возврат результата
	$html = didom_innerHTML_helper($document);

	// форматирование
	$beautifier = new Beautify_Html();
	$html = $beautifier->beautify($html);

	return $html;
}

/**
 * Удалит H1 из контентной части
 *
 * @param $document
 * @return mixed
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
 * Обработка и скачивание всех img и пришпиленных документов
 *
 * @param $document
 * @param bool $store_in_list_instead
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

/**
 * Удаление пустых элементов (<p>)
 *
 * @param $document
 */
function didom_content_deleteEmptyElements(&$document){
    $elems = $document->find('p');

    if (count($elems)){
        foreach ($elems as $elem){

            // Получаем текст и убираем пробелы
            $innerText = trim($elem->text(), ' ');

            // Если текст отсутствует, можно удалить элемент
            if ( preg_match("/^(\s)*$/mu", $innerText) ){
                $elem->remove();
                writeLog('didom_content_deleteEmptyElements(): удалён элемент');
            }
        }
    }
}

/**
 * Удаляет дату из html статьи
 *
 * @param $document
 */
function didom_content_deleteDate(&$document){
	$elems = $document->find('.newsdate');

	if (count($elems)){
		foreach ($elems as $elem){
			$elem->remove();
		}
	}
}

/**
 * Парсит столбец контента и определяет, в рубрике ли мы новостей. Если да, то собирает картинку и текст анонса
 *
 * @param $data
 * @return mixed
 * @throws \DiDom\Exceptions\InvalidSelectorException
 */
function didom_collect_announces(&$data){
    // Проверка на пустоту
    if ( preg_match("/^(\s)*$/mu", $data) || !$data ){
        writeLog('didom_collect_announces(): получена пустая $data - "'.$data.'"');
        return $data;
    }

    // Строим документ
    $document = new Document($data);

    // Операции
    // Проверим наличие .newsitem
    $news = $document->find('.newsitem');
    if (count($news) < 1) return $data; // Выход, если нет

    // Проверяем/создаём глобальную перменную для хранения анонсов
    if (!isset($_SESSION['didom_announces'])){
        $_SESSION['didom_announces'] = array();
    }

    // Перебор анонсов
    foreach ($news as $index => $element){
        $temporary = array( 'url'=>'', 'img'=>'', 'descr'=>'' ); // сюда сложим элементы

        // ищем картинку, анонс, а затем ссылку
        $image = $element->first('td > img');
        if ($image) $temporary['img'] = $image->getAttribute('src');
        // картинку нужно занести в список для скачивания
        if ($temporary['img']){
            $list_file = WORKDIR_ROOT . '/_downloadedfiles/to_download_announces.txt';
            $img_href_array = parse_url_custom($temporary['img'], 'kavraion.ru');
            $href_downloadBy = 'http://www.kavraion.ru/'.ltrim($img_href_array['path'], '/');
            file_put_contents($list_file, $href_downloadBy.PHP_EOL, FILE_APPEND | LOCK_EX);
            // Теперь модифицировать путь к ней - сделать относительный от корня, с нашей подпапкой
            $img_path_info = parse_url_custom($temporary['img'], 'kavraion.ru');
            if ($img_path_info['is_internal']){
                $temporary['img'] = '/_imgs/'.ltrim($temporary['img'], '/');
            }
        }

        // Анонс новости
        $descr = $element->first('.newsdescription');
        if ($descr) $temporary['descr'] = trim($descr->text());

        if (!$temporary['img'] && !$temporary['descr']) continue; // если ни описания, ни картинки, пропускаем

        // зафиксируем ссылку на новость - будет id
        $url = $element->first('.newsheader a');
        if (!$url) {
            writeLog('didom_collect_announces(): не удаётся найти url новости!');
            continue;
        }
        $url = $url->getAttribute('href');

        // получим расширенные данные об url
        $url_data = parse_url_custom($url, 'kavraion.ru');
        // сгенерируем полный url, или воспользуемся изначальным
        $url = 'http://www.kavraion.ru' . '/' . ltrim($url_data['path'], '/');
        $temporary['url'] = $url;
        writeLog('didom_collect_announces(): строим полный путь на новость сами, $url = '.$url);

        // теперь занести всё в глобальную переменную
        $_SESSION['didom_announces'][] = $temporary;
    }

    // Возврат исходника, т.к. мы его не модифицируем
    return $data;
}


/************************************************************************************************
 * Хлебные крошки
 ***********************************************************************************************/
/**
 * Обходит html хлебных крошек, строит из них текст вида "Раздел > Раздел 2 > Раздел n"
 *
 * @param $data
 * @return string
 * @throws \DiDom\Exceptions\InvalidSelectorException
 */
function didom_process_breadcrumbs(&$data){
	// Проверка на пустоту
	if ( preg_match("/^(\s)*$/mu", $data) || !$data ){
		writeLog('didom_process_breadcrumbs(): получена пустая $data - "'.$data.'"');
		return $data;
	}

	// Строим документ
	$document = new Document($data);

	// В полученном html все элементы пути - <A>. Самоссылки нет, т.е. просто обрабатываем все ссылки

	$sections_array = array(); // сюда будем заносить текстовые значения каждой ссылки

	$elements = $document->find('a');
	if (count($elements)){
		foreach ($elements as $elem){
			$innerText = $elem->text();

			// Если внутренний текст пустой, ничего не делаем
			if ( preg_match("/^(\s)*$/mu", $innerText) ){
				continue;
			}

			// Заносим в массив
			$sections_array[] = $innerText;
		}
	}
	unset($elements);
	unset($document);

	// Схлопываем массив с нашим разделителем
	$result = implode(' > ', $sections_array);

	// Возврат результата
	return $result;
}

/************************************************************************************************
 * Дата публикации
 ***********************************************************************************************/
/**
 * Обработка html даты, приведение к простому текстовому виду
 *
 * @param $data
 * @return mixed
 * @throws \DiDom\Exceptions\InvalidSelectorException
 */
function didom_process_date(&$data){
	// Проверка на пустоту
	if ( preg_match("/^(\s)*$/mu", $data) || !$data ){
		writeLog('didom_process_date(): получена пустая $data - "'.$data.'"');
		return $data;
	}

	// Строим документ
	$document = new Document($data);

	// Дата прячется в <td>
	$date_array = array(); // сюда будем заносить текстовые значения каждого элемента даты

	$elements = $document->find('td');
	if (count($elements)){
		foreach ($elements as $elem){
			$innerText = $elem->text();

			// Если внутренний текст пустой, ничего не делаем
			if ( preg_match("/^(\s)*$/mu", $innerText) ){
				continue;
			}

			// Заносим в массив
			$date_array[] = $innerText;
		}
	}
	unset($elements);
	unset($document);

	// Схлопываем массив с нашим разделителем
	$result = implode('.', $date_array);

	// Возврат результата
	return $result;
}





/************************************************************************************************
 * Служебные функции
 ***********************************************************************************************/

/**
 * Парсит url и получает доп. инфу по нему (путь к файлу, и внутренняя ли ссылка)
 *
 * @param $url
 * @param $internal_domain
 * @return array|false|int|string|null
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
 * Пытаемся получить внутренний html от body
 *
 * @param $document
 * @return mixed
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
 * Пример функции
 *
 * @param $data
 * @return mixed
 */
function didom_process_DUMMY(&$data){
	// Проверка на пустоту
	if ( preg_match("/^(\s)*$/mu", $data) || !$data ){
		writeLog('didom_process_DUMMY(): получена пустая $data - "'.$data.'"');
		return $data;
	}

	// Строим документ
	$document = new Document($data);

	// Операции

	// Возврат результата
	$html = didom_innerHTML_helper($document);
	return $html;
}