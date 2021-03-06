<?php
/**
 * Created by PhpStorm.
 * User: bruce
 * Date: 2018-09-06
 * Time: 21:01
 */


namespace uploader;

use Upyun\Config;
use Upyun\Upyun;

class UploadUpyun extends Upload{
    public $serviceName;
    //操作员名称
    public $operator;
    //操作员密码
    public $password;
    //域名
    public $domain;
    public static $config;
    //arguments from php client, the image absolute path
    public $argv;

    /**
     * Upload constructor.
     *
     * @param $config
     * @param $argv
     */
    public function __construct($config, $argv)
    {
	    $tmpArr = explode('\\',__CLASS__);
	    $className = array_pop($tmpArr);
	    $ServerConfig = $config['storageTypes'][strtolower(substr($className,6))];
	    
        $this->serviceName = $ServerConfig['serviceName'];
        $this->operator = $ServerConfig['operator'];
        $this->password = $ServerConfig['password'];
        $this->domain = $ServerConfig['domain'];

        $this->argv = $argv;
        static::$config = $config;
    }

    /**
     *
     * @return string
     * @throws \ImagickException
     * @throws \OSS\Core\OssException
     */
    public function upload(){
        $link = '';
        foreach($this->argv as $filePath){
            $mimeType = $this->getMimeType($filePath);
            $originFilename = $this->getOriginFileName($filePath);
            //如果不是允许的图片，则直接跳过（目前允许jpg/png/gif）
            if(!in_array($mimeType, static::$config['allowMimeTypes'])){
                $error = 'Only MIME in "'.join(', ', static::$config['allowMimeTypes']).'" is allow to upload, but the MIME of this photo "'.$originFilename.'" is '.$mimeType."\n";
                $this->writeLog($error, 'error_log');
                continue;
            }
	
	        //如果配置了优化宽度，则优化
	        $uploadFilePath = $filePath;
	        $tmpImgPath = '';
	        if(isset(static::$config['imgWidth']) && static::$config['imgWidth'] > 0){
		        $tmpImgPath = $this->optimizeImage($filePath, static::$config['imgWidth']);
		        $uploadFilePath = $tmpImgPath ? $tmpImgPath : $filePath;
	        }

	        //添加水印
	        if(isset(static::$config['watermark']['useWatermark']) && static::$config['watermark']['useWatermark']==1){
		        $tmpImgPath = $uploadFilePath = $this->watermark($filePath);
	        }

            //获取随机文件名
            $newFileName = $this->genRandFileName($uploadFilePath);

            //组装key名（因为我们用的是腾讯云的对象存储服务，存储是用key=>value的方式存的）
            $key = date('Y/m/d/') . $newFileName;

            try {
	            $serviceConfig = new Config($this->serviceName, $this->operator, $this->password);
	            $client = new Upyun($serviceConfig);
	            $retArr = $client->write($key, fopen($uploadFilePath, 'r'));
	            
                if(!isset($retArr['x-upyun-content-length'])){
                    $this->writeLog(var_export($retArr, true)."\n", 'error_log');
                    continue;
                }
	            $publicLink = $this->domain.'/'.$key;
                //按配置文件指定的格式，格式化链接
                $link .= $this->formatLink($publicLink, $originFilename);
                //删除临时图片
                $tmpImgPath && is_file($tmpImgPath) && @unlink($tmpImgPath);
            } catch (NosException $e) {
                //上传数错，记录错误日志
                $this->writeLog($e->getMessage()."\n", 'error_log');
                continue;
            }
        }
        return $link;
    }
}