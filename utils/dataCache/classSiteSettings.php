<?php

class SiteSettings
{
	
	const dataCacheEnabled = true;			# Включить кэшированние данных
	const dataCacheTime = 31536000*60;			# время кеширования данных, секунды. Год
	const dataCacheFolder = '/parsedProcessor/cache_data/';	# путь к папке с файлами для хранения кешированных данных
		
}

?>