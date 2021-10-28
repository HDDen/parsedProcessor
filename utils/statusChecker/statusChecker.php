<?php

function serverResponceCheck($url){
    if (!$url) return false;

    $toCheckURL = $url; // Домен для проверки

    // поработаем с CURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $toCheckURL);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10); // разрешаем только 10 редиректов за раз во избежание бесконечного цикла
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); // Получаем HTTP-код
    $new_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    // Массив возможных HTTP статус кодов
    $codes = array(
        0=>'Domain Not Found',
        100=>'Continue',
        101=>'Switching Protocols',
        200=>'OK',
        201=>'Created',
        202=>'Accepted',
        203=>'Non-Authoritative Information',
        204=>'No Content',
        205=>'Reset Content',
        206=>'Partial Content',
        300=>'Multiple Choices',
        301=>'Moved Permanently',
        302=>'Found',
        303=>'See Other',
        304=>'Not Modified',
        305=>'Use Proxy',
        307=>'Temporary Redirect',
        400=>'Bad Request',
        401=>'Unauthorized',
        402=>'Payment Required',
        403=>'Forbidden',
        404=>'Not Found',
        405=>'Method Not Allowed',
        406=>'Not Acceptable',
        407=>'Proxy Authentication Required',
        408=>'Request Timeout',
        409=>'Conflict',
        410=>'Gone',
        411=>'Length Required',
        412=>'Precondition Failed',
        413=>'Request Entity Too Large',
        414=>'Request-URI Too Long',
        415=>'Unsupported Media Type',
        416=>'Requested Range Not Satisfiable',
        417=>'Expectation Failed',
        500=>'Internal Server Error',
        501=>'Not Implemented',
        502=>'Bad Gateway',
        503=>'Service Unavailable',
        504=>'Gateway Timeout',
        505=>'HTTP Version Not Supported'
    );

    // Ищем совпадения с нашим списком и формируем ответ
    $result = array(
        'status' => false,
        'code' => false,
        'full' => $data,
    );

    if (isset($codes[$http_code])) {

        $result['status'] = $http_code;
        $result['code'] = $codes[$http_code];

        /*
        echo 'Сайт вернул ответ: '.$http_code.' - '.$codes[$http_code].'<br />';
        preg_match_all("/HTTP\/1.[1|0]s(d{3})/",$data,$matches);
        
        array_pop($matches[1]);

        if (count($matches[1]) > 0) {

            // Идем дальше по списку, чтобы посмотреть, какие мы еще статус коды получили
            foreach ($matches[1] as $c) {
                echo $c.' - '.$codes[$c].'<br />';
            }

        }

        // Проверяем если урл поменялся или нет
        if ($toCheckURL != $new_url) {
            echo 'Новый URL: '.$new_url;
        }
        */

    }

    return $result;
}