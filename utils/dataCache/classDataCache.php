<?php
##################################################
# Класс КЭШИРОВАНИЯ данных (массивов и объектов)
##################################################
#
#	Можно кэшировать результаты SQL запросов или любые данные в виде массивов или объектов
#
#	Пример использования класса для кеширования данных
#
#		// Создаём объект для кэширования данных с уникальным для этих данных ID - findXXX
#		$dataCache = new DataCache('findXXX');
#		// Запрашиваем инициализацию кэша
#		$getDataFromCache = $dataCache->initCacheData();
#		if ($getDataFromCache) {
#			// Получаем кэшированные данные из кэша
#			$data = $dataCache->getCacheData();
#		} else {
#			// Исполняем код если кеширование отключено или данные в кеше старые
#			$data = generateNewData();
#			.......
#			// Обновляем данные в кэше
#			$dataCache->updateCacheData($data);
#		}
#

class DataCache {
	
	private $cache;
	private $cacheFileName;
	private $cacheFolder;
	private $cacheFullFileName;
	private $cacheFullFilePath;
	private $cacheEnabled;
	private $cacheTime;
	private $dataName;
	
	const DEFAULT_CACHE_PATH = '/cache_data/';		# путь к папке с файлами для хранения кешированных данных
	const DEFAULT_CACHE_TIME = 600;					# время кеширования данных, секунды

	#-------------------------
	public function __construct($dataName) {
		if (defined('SiteSettings::dataCacheEnabled') && SiteSettings::dataCacheEnabled === true) {
			$this->setCacheOn();
		} else {
			$this->setCacheOff();
		}
		if (defined('SiteSettings::dataCacheTime') && !empty(SiteSettings::dataCacheTime)) {
			$this->setCacheTime(SiteSettings::dataCacheTime);
		} else {
			$this->setCacheTime(self::DEFAULT_CACHE_TIME);
		}
		if (defined('SiteSettings::dataCacheFolder') && !empty(SiteSettings::dataCacheFolder)) {
			$this->cacheFolder = $_SERVER['DOCUMENT_ROOT'] . SiteSettings::dataCacheFolder;
		} else {
			$this->cacheFolder = $_SERVER['DOCUMENT_ROOT'] . self::DEFAULT_CACHE_PATH;
		}
		$this->dataName = $dataName;
		$md5hash = md5($this->dataName);
		$this->cacheFileName = substr($md5hash, 2, 30);
		$this->cacheFullFilePath = $this->cacheFolder . substr($md5hash, 0, 1) . '/' . substr($md5hash, 1, 1) . '/';
		$this->cacheFullFileName = $this->cacheFullFilePath . $this->cacheFileName;
				
		return true;
	}
	
	#---------------------------
	public function setCacheOn()
	{
		$this->cacheEnabled = true;
	}

	#---------------------------
	public function setCacheOff()
	{
		$this->cacheEnabled = false;
	}

	#--------------------------------
	public function getCacheEnabled()
	{
		return $this->cacheEnabled;
	}
	
	#-------------------------------------------
	public function setCacheTime(int $seconds)
	{
		$this->cacheTime = $seconds;
	}

	#------------------------------
	public function initCacheData()
	{
		if (!$this->cacheEnabled) { return false; }
		$cacheOld = time() - @filemtime($this->cacheFullFileName);
		if($cacheOld < $this->cacheTime) {
			$fp = @fopen($this->cacheFullFileName, "r");
			$this->cache = @fread($fp, filesize($this->cacheFullFileName));
			@fclose($fp);
			return true;
		}
		
		return false;
	}

	#-----------------------------
	public function getCacheData()
	{
		if (!$this->cacheEnabled) { return false; }
		
		if (empty($this->cache)) {
			return false;
		} else {
			return unserialize($this->cache);
		}
	}

	#----------------------------------------
	public function updateCacheData($newData)
	{
		if (!$this->cacheEnabled) { return false; }
		
		$this->cache = $newData;
		$output = serialize($this->cache);
		if(!@file_exists($this->cacheFullFilePath)) { @mkdir($this->cacheFullFilePath, 0777, true); }
		$fp = @fopen($this->cacheFullFileName, "w");
		@fwrite($fp, $output);
		@fclose($fp);
		
		return true;
	}
	
}
?>
